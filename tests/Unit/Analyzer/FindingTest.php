<?php

declare(strict_types=1);

namespace IndexFinder\Tests\Unit\Analyzer;

use IndexFinder\Analyzer\Finding;
use IndexFinder\Analyzer\FindingReason;
use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;
use IndexFinder\Schema\IndexType;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testBuildsDropStatementWithQuotedIdentifiers(): void
    {
        $finding = new Finding(
            'odd`table',
            new Index('idx`weird', IndexType::Index, [new IndexColumn('a')]),
            new Index('idx_ab', IndexType::Index, [new IndexColumn('a'), new IndexColumn('b')]),
            FindingReason::LeftmostPrefix,
        );

        self::assertSame('ALTER TABLE `odd``table` DROP INDEX `idx``weird`;', $finding->dropStatement());
    }

    public function testDescribesLeftmostPrefix(): void
    {
        $finding = new Finding(
            'orders',
            new Index('idx_customer', IndexType::Index, [new IndexColumn('customer_code')]),
            new Index('idx_customer_status', IndexType::Index, [new IndexColumn('customer_code'), new IndexColumn('status', descending: true)]),
            FindingReason::LeftmostPrefix,
        );

        self::assertSame(
            'Columns (customer_code) of idx_customer are a leftmost prefix of idx_customer_status (customer_code, status DESC).',
            $finding->message(),
        );
    }

    public function testDescribesDuplicate(): void
    {
        $finding = new Finding(
            'customers',
            new Index('idx_email', IndexType::Index, [new IndexColumn('email', 50)]),
            new Index('uniq_email', IndexType::Unique, [new IndexColumn('email', 50)]),
            FindingReason::Duplicate,
        );

        self::assertSame('Index idx_email has the same columns as uniq_email.', $finding->message());
        self::assertSame('uniq_email (email(50)) UNIQUE', $finding->coveringIndex->describe());
    }
}
