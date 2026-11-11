<?php

declare(strict_types=1);

namespace IndexFinder\Tests\Unit\Analyzer;

use IndexFinder\Analyzer\Finding;
use IndexFinder\Analyzer\FindingReason;
use IndexFinder\Analyzer\RedundancyAnalyzer;
use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;
use IndexFinder\Schema\IndexType;
use IndexFinder\Schema\Table;
use PHPUnit\Framework\TestCase;

final class RedundancyAnalyzerTest extends TestCase
{
    public function testReportsIndexWhoseColumnsAreALeftmostPrefixOfAnother(): void
    {
        $findings = $this->analyze(
            self::index('idx_customer', IndexType::Index, 'customer_code'),
            self::index('idx_customer_status', IndexType::Index, 'customer_code', 'status'),
        );

        self::assertSame([['idx_customer', 'idx_customer_status', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testDoesNotReportIndexWhoseColumnsAreNotALeftmostPrefix(): void
    {
        self::assertSame([], $this->analyze(
            self::index('idx_status', IndexType::Index, 'status'),
            self::index('idx_customer_status', IndexType::Index, 'customer_code', 'status'),
            self::index('idx_status_customer_x', IndexType::Index, 'customer_code', 'x', 'status'),
        ));
    }

    public function testColumnOrderMatters(): void
    {
        self::assertSame([], $this->analyze(
            self::index('idx_ab', IndexType::Index, 'a', 'b'),
            self::index('idx_ba', IndexType::Index, 'b', 'a'),
        ));
    }

    public function testColumnNamesAreCaseInsensitive(): void
    {
        $findings = $this->analyze(
            self::index('idx_a', IndexType::Index, 'Customer_Code'),
            self::index('idx_ab', IndexType::Index, 'customer_code', 'status'),
        );

        self::assertSame([['idx_a', 'idx_ab', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testReportsOnlyTheLaterOfTwoExactDuplicates(): void
    {
        $findings = $this->analyze(
            self::index('idx_status', IndexType::Index, 'status', 'created_at'),
            self::index('idx_status_copy', IndexType::Index, 'status', 'created_at'),
        );

        self::assertSame([['idx_status_copy', 'idx_status', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testNonUniqueIndexDuplicatingAUniqueIndexIsRedundant(): void
    {
        $findings = $this->analyze(
            self::index('idx_email', IndexType::Index, 'email'),
            self::index('uniq_email', IndexType::Unique, 'email'),
        );

        self::assertSame([['idx_email', 'uniq_email', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testNonUniqueIndexCoveredByALongerUniqueIndexIsRedundant(): void
    {
        $findings = $this->analyze(
            self::index('idx_sku', IndexType::Index, 'sku'),
            self::index('uniq_sku_category', IndexType::Unique, 'sku', 'category_id'),
        );

        self::assertSame([['idx_sku', 'uniq_sku_category', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testUniqueIndexIsNotRedundantBecauseOfALongerNonUniqueIndex(): void
    {
        self::assertSame([], $this->analyze(
            self::index('uniq_code', IndexType::Unique, 'customer_code'),
            self::index('idx_code_status', IndexType::Index, 'customer_code', 'status'),
        ));
    }

    public function testUniqueIndexIsNotRedundantBecauseOfALongerUniqueIndex(): void
    {
        self::assertSame([], $this->analyze(
            self::index('uniq_sku', IndexType::Unique, 'sku'),
            self::index('uniq_sku_category', IndexType::Unique, 'sku', 'category_id'),
        ));
    }

    public function testUniqueIndexIsNotRedundantBecauseOfAnIdenticalNonUniqueIndex(): void
    {
        $findings = $this->analyze(
            self::index('uniq_email', IndexType::Unique, 'email'),
            self::index('idx_email', IndexType::Index, 'email'),
        );

        // The plain index is the one to drop, even though it was declared later.
        self::assertSame([['idx_email', 'uniq_email', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testDuplicateUniqueIndexesReportTheLaterOne(): void
    {
        $findings = $this->analyze(
            self::index('uniq_email', IndexType::Unique, 'email'),
            self::index('uniq_email_2', IndexType::Unique, 'email'),
        );

        self::assertSame([['uniq_email_2', 'uniq_email', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testUniqueIndexDuplicatingThePrimaryKeyIsRedundant(): void
    {
        $findings = $this->analyze(
            self::index('uniq_id', IndexType::Unique, 'id'),
            self::index('PRIMARY', IndexType::Primary, 'id'),
        );

        self::assertSame([['uniq_id', 'PRIMARY', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testPrimaryKeyIsNeverReported(): void
    {
        $findings = $this->analyze(
            self::index('PRIMARY', IndexType::Primary, 'order_id'),
            self::index('idx_order_product', IndexType::Index, 'order_id', 'product_id'),
            self::index('uniq_order_product', IndexType::Unique, 'order_id', 'product_id'),
            self::index('idx_order', IndexType::Index, 'order_id'),
        );

        self::assertSame(
            [
                ['idx_order_product', 'uniq_order_product', FindingReason::Duplicate],
                ['idx_order', 'PRIMARY', FindingReason::Duplicate],
            ],
            self::summarise($findings),
        );
    }

    public function testNonUniqueIndexCoveredByThePrimaryKeyIsRedundant(): void
    {
        $findings = $this->analyze(
            self::index('PRIMARY', IndexType::Primary, 'order_id', 'product_id'),
            self::index('idx_order', IndexType::Index, 'order_id'),
        );

        self::assertSame([['idx_order', 'PRIMARY', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testFulltextIsOnlyComparedWithFulltextAndNeverAsAPrefix(): void
    {
        self::assertSame([], $this->analyze(
            self::index('idx_name', IndexType::Index, 'name'),
            self::index('ft_name', IndexType::Fulltext, 'name'),
            // A FULLTEXT index only serves MATCH() on its exact column list, so prefixes don't count.
            self::index('ft_name_description', IndexType::Fulltext, 'name', 'description'),
        ));
    }

    public function testFulltextDuplicatesAreReported(): void
    {
        $findings = $this->analyze(
            self::index('ft_name', IndexType::Fulltext, 'name', 'description'),
            self::index('ft_name_copy', IndexType::Fulltext, 'name', 'description'),
        );

        self::assertSame([['ft_name_copy', 'ft_name', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testSpatialIsOnlyComparedWithSpatial(): void
    {
        $findings = $this->analyze(
            self::index('idx_location', IndexType::Index, 'location'),
            self::index('sp_location', IndexType::Spatial, 'location'),
            self::index('sp_location_copy', IndexType::Spatial, 'location'),
        );

        self::assertSame([['sp_location_copy', 'sp_location', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testDifferentPrefixLengthsAreTreatedAsDifferentColumns(): void
    {
        self::assertSame([], $this->analyze(
            new Index('idx_name_10', IndexType::Index, [new IndexColumn('name', 10)]),
            new Index('idx_name', IndexType::Index, [new IndexColumn('name')]),
            new Index('idx_name_20_status', IndexType::Index, [new IndexColumn('name', 20), new IndexColumn('status')]),
        ));
    }

    public function testMatchingPrefixLengthsAreComparedNormally(): void
    {
        $findings = $this->analyze(
            new Index('idx_name_10', IndexType::Index, [new IndexColumn('name', 10)]),
            new Index('idx_name_10_status', IndexType::Index, [new IndexColumn('name', 10), new IndexColumn('status')]),
        );

        self::assertSame([['idx_name_10', 'idx_name_10_status', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testDifferentSortDirectionsAreTreatedAsDifferentColumns(): void
    {
        self::assertSame([], $this->analyze(
            new Index('idx_created_desc', IndexType::Index, [new IndexColumn('created_at', descending: true)]),
            new Index('idx_created_status', IndexType::Index, [new IndexColumn('created_at'), new IndexColumn('status')]),
        ));
    }

    public function testFunctionalKeyPartsMatchOnlyIdenticalExpressions(): void
    {
        $findings = $this->analyze(
            new Index('idx_lower', IndexType::Index, [new IndexColumn('lower(email)', isExpression: true)]),
            new Index('idx_lower_status', IndexType::Index, [new IndexColumn('lower(email)', isExpression: true), new IndexColumn('status')]),
            new Index('idx_upper', IndexType::Index, [new IndexColumn('upper(email)', isExpression: true)]),
            new Index('idx_plain', IndexType::Index, [new IndexColumn('lower(email)')]),
        );

        self::assertSame([['idx_lower', 'idx_lower_status', FindingReason::LeftmostPrefix]], self::summarise($findings));
    }

    public function testHashIndexesAreOnlyRedundantAsExactHashDuplicates(): void
    {
        $findings = $this->analyze(
            new Index('idx_a', IndexType::Index, [new IndexColumn('a')], 'HASH'),
            new Index('idx_ab', IndexType::Index, [new IndexColumn('a'), new IndexColumn('b')], 'HASH'),
            new Index('idx_ab_btree', IndexType::Index, [new IndexColumn('a'), new IndexColumn('b')], 'BTREE'),
            new Index('idx_a_copy', IndexType::Index, [new IndexColumn('a')], 'HASH'),
        );

        self::assertSame([['idx_a_copy', 'idx_a', FindingReason::Duplicate]], self::summarise($findings));
    }

    public function testChainedIndexesAreReportedAgainstTheIndexThatIsKept(): void
    {
        $findings = $this->analyze(
            self::index('idx_a', IndexType::Index, 'a'),
            self::index('idx_ab', IndexType::Index, 'a', 'b'),
            self::index('idx_abc', IndexType::Index, 'a', 'b', 'c'),
            self::index('idx_a_copy', IndexType::Index, 'a'),
        );

        self::assertSame(
            [
                ['idx_a', 'idx_abc', FindingReason::LeftmostPrefix],
                ['idx_ab', 'idx_abc', FindingReason::LeftmostPrefix],
                ['idx_a_copy', 'idx_abc', FindingReason::LeftmostPrefix],
            ],
            self::summarise($findings),
        );
    }

    public function testIndexesOnDifferentTablesAreNeverCompared(): void
    {
        $result = (new RedundancyAnalyzer())->analyze([
            new Table('orders', [self::index('idx_a', IndexType::Index, 'a')]),
            new Table('customers', [self::index('idx_ab', IndexType::Index, 'a', 'b')]),
        ]);

        self::assertFalse($result->hasFindings());
        self::assertSame(2, $result->tableCount());
        self::assertSame(2, $result->indexCount());
    }

    public function testFindingsCarryTheTableName(): void
    {
        $result = (new RedundancyAnalyzer())->analyze([
            new Table('orders', [self::index('idx_a', IndexType::Index, 'a'), self::index('idx_ab', IndexType::Index, 'a', 'b')]),
        ]);

        self::assertTrue($result->hasFindings());
        self::assertSame('orders', $result->findings[0]->table);
    }

    /**
     * @return list<Finding>
     */
    private function analyze(Index ...$indexes): array
    {
        return (new RedundancyAnalyzer())->analyzeTable(new Table('t', array_values($indexes)));
    }

    private static function index(string $name, IndexType $type, string ...$columns): Index
    {
        return new Index($name, $type, array_map(static fn (string $column): IndexColumn => new IndexColumn($column), array_values($columns)));
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{string, string, FindingReason}>
     */
    private static function summarise(array $findings): array
    {
        return array_map(
            static fn (Finding $finding): array => [$finding->redundantIndex->name, $finding->coveringIndex->name, $finding->reason],
            $findings,
        );
    }
}
