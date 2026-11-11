<?php

declare(strict_types=1);

namespace IndexFinder\Analyzer;

use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexType;
use IndexFinder\Schema\Table;

/**
 * Decides which indexes are made unnecessary by another index on the same
 * table.
 *
 * Index A is covered by index B when:
 *  - A is not the PRIMARY KEY, and the two are comparable (B-tree vs B-tree,
 *    FULLTEXT vs FULLTEXT, SPATIAL vs SPATIAL);
 *  - A's key parts are a leftmost prefix of B's, where a key part only
 *    matches if column, prefix length and direction are identical;
 *  - when B is strictly longer, A is not UNIQUE (a unique constraint on fewer
 *    columns is stricter and cannot be dropped), A and B support prefix
 *    lookups (not FULLTEXT/SPATIAL) and neither uses HASH;
 *  - when the column lists are identical, B is the one to keep: the stronger
 *    constraint (PRIMARY > UNIQUE > INDEX) or, if equal, the one declared
 *    first.
 *
 * Each redundant index is reported once. Its covering index is chosen among
 * indexes that are not redundant themselves, so applying every suggestion
 * never removes the last index that serves a query. Such an index always
 * exists because "covered by" is a strict ordering (more columns, or same
 * columns and stronger/earlier).
 */
final class RedundancyAnalyzer
{
    /**
     * @param list<Table> $tables
     */
    public function analyze(array $tables): AnalysisResult
    {
        $findings = [];

        foreach ($tables as $table) {
            array_push($findings, ...$this->analyzeTable($table));
        }

        return new AnalysisResult($tables, $findings);
    }

    /**
     * @return list<Finding>
     */
    public function analyzeTable(Table $table): array
    {
        $indexes = $table->indexes;

        /** @var array<int, list<int>> $coveredBy redundant position => covering positions */
        $coveredBy = [];
        foreach ($indexes as $i => $candidate) {
            foreach ($indexes as $j => $other) {
                if ($i !== $j && $this->isCoveredBy($candidate, $i, $other, $j)) {
                    $coveredBy[$i][] = $j;
                }
            }
        }

        $findings = [];
        foreach ($coveredBy as $i => $coverers) {
            $kept = array_values(array_filter($coverers, static fn (int $j): bool => !isset($coveredBy[$j])));
            $covering = $indexes[$kept[0] ?? $coverers[0]];
            $reason = $covering->hasSameColumnsAs($indexes[$i]) ? FindingReason::Duplicate : FindingReason::LeftmostPrefix;

            $findings[] = new Finding($table->name, $indexes[$i], $covering, $reason);
        }

        return $findings;
    }

    private function isCoveredBy(Index $candidate, int $candidatePosition, Index $other, int $otherPosition): bool
    {
        if ($candidate->type === IndexType::Primary) {
            return false;
        }

        if (!$candidate->type->isComparableWith($other->type) || !$other->startsWith($candidate)) {
            return false;
        }

        if ($candidate->usesHash() !== $other->usesHash()) {
            return false;
        }

        if ($other->columnCount() > $candidate->columnCount()) {
            return !$candidate->type->isUnique()
                && $candidate->type->supportsLeftmostPrefix()
                && !$other->usesHash();
        }

        // Exact duplicates: keep the stronger constraint, otherwise the first declared.
        $strengthDifference = $other->type->strength() <=> $candidate->type->strength();

        return $strengthDifference > 0 || ($strengthDifference === 0 && $otherPosition < $candidatePosition);
    }
}
