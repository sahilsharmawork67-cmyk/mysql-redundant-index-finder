<?php

declare(strict_types=1);

namespace IndexFinder\Tests\Unit\Report;

use IndexFinder\Analyzer\AnalysisResult;
use IndexFinder\Analyzer\Finding;
use IndexFinder\Analyzer\FindingReason;
use IndexFinder\Report\JsonReporter;
use IndexFinder\Report\TextReporter;
use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;
use IndexFinder\Schema\IndexType;
use IndexFinder\Schema\Table;
use PHPUnit\Framework\TestCase;

final class ReporterTest extends TestCase
{
    public function testTextReporterSummarisesCleanSchema(): void
    {
        $result = new AnalysisResult([new Table('orders', [$this->primary()])], []);

        self::assertSame("No redundant indexes found (1 table, 1 index scanned).\n", (new TextReporter())->render($result));
    }

    public function testTextReporterRendersTableAndFixes(): void
    {
        $expected = <<<'TEXT'
            Found 1 redundant index (1 table, 3 indexes scanned).

            +--------+----------------------------+-------------------------------------------+-----------------+
            | Table  | Redundant index            | Covered by                                | Reason          |
            +--------+----------------------------+-------------------------------------------+-----------------+
            | orders | idx_customer (customer_id) | idx_customer_status (customer_id, status) | Leftmost prefix |
            +--------+----------------------------+-------------------------------------------+-----------------+

            Suggested fixes (review before running in production):

            ALTER TABLE `orders` DROP INDEX `idx_customer`;

            TEXT;

        self::assertSame($expected, (new TextReporter())->render($this->resultWithFinding()));
    }

    public function testJsonReporterOutputsSummaryAndFindings(): void
    {
        $json = json_decode((new JsonReporter())->render($this->resultWithFinding()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            [
                'summary' => ['tables_scanned' => 1, 'indexes_scanned' => 3, 'redundant_indexes' => 1],
                'findings' => [[
                    'table' => 'orders',
                    'redundant_index' => ['name' => 'idx_customer', 'type' => 'INDEX', 'columns' => ['customer_id']],
                    'covering_index' => ['name' => 'idx_customer_status', 'type' => 'INDEX', 'columns' => ['customer_id', 'status']],
                    'reason' => 'leftmost_prefix',
                    'message' => 'Columns (customer_id) of idx_customer are a leftmost prefix of idx_customer_status (customer_id, status).',
                    'fix' => 'ALTER TABLE `orders` DROP INDEX `idx_customer`;',
                ]],
            ],
            $json,
        );
    }

    public function testJsonReporterOutputsEmptyFindingsList(): void
    {
        $result = new AnalysisResult([new Table('orders', [$this->primary()])], []);

        self::assertStringContainsString('"findings": []', (new JsonReporter())->render($result));
    }

    private function resultWithFinding(): AnalysisResult
    {
        $redundant = new Index('idx_customer', IndexType::Index, [new IndexColumn('customer_id')]);
        $covering = new Index('idx_customer_status', IndexType::Index, [new IndexColumn('customer_id'), new IndexColumn('status')]);

        return new AnalysisResult(
            [new Table('orders', [$this->primary(), $redundant, $covering])],
            [new Finding('orders', $redundant, $covering, FindingReason::LeftmostPrefix)],
        );
    }

    private function primary(): Index
    {
        return new Index('PRIMARY', IndexType::Primary, [new IndexColumn('id')]);
    }
}
