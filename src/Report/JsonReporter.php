<?php

declare(strict_types=1);

namespace IndexFinder\Report;

use IndexFinder\Analyzer\AnalysisResult;
use IndexFinder\Analyzer\Finding;
use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;

/**
 * Renders findings as JSON for use in scripts and CI pipelines.
 */
final class JsonReporter implements Reporter
{
    public function render(AnalysisResult $result): string
    {
        $payload = [
            'summary' => [
                'tables_scanned' => $result->tableCount(),
                'indexes_scanned' => $result->indexCount(),
                'redundant_indexes' => \count($result->findings),
            ],
            'findings' => array_map(fn (Finding $finding): array => [
                'table' => $finding->table,
                'redundant_index' => $this->index($finding->redundantIndex),
                'covering_index' => $this->index($finding->coveringIndex),
                'reason' => $finding->reason->value,
                'message' => $finding->message(),
                'fix' => $finding->dropStatement(),
            ], $result->findings),
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @return array{name: string, type: string, columns: list<string>}
     */
    private function index(Index $index): array
    {
        return [
            'name' => $index->name,
            'type' => $index->type->value,
            'columns' => array_map(static fn (IndexColumn $column): string => $column->toString(), $index->columns),
        ];
    }
}
