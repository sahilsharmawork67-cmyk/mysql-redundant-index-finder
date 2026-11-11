<?php

declare(strict_types=1);

namespace IndexFinder\Tests\Unit\Parser;

use IndexFinder\Parser\ParseException;
use IndexFinder\Parser\SchemaParser;
use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;
use IndexFinder\Schema\IndexType;
use IndexFinder\Schema\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaParserTest extends TestCase
{
    public function testParsesEveryIndexTypeFromMysqldumpOutput(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE `orders` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `order_number` varchar(32) NOT NULL,
              `customer_code` varchar(32) NOT NULL,
              `status` varchar(20) NOT NULL,
              `notes` text,
              `location` point NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_order_number` (`order_number`),
              KEY `idx_customer_status` (`customer_code`,`status`),
              INDEX `idx_status` (`status`),
              FULLTEXT KEY `ft_notes` (`notes`),
              SPATIAL KEY `sp_location` (`location`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            SQL);

        self::assertSame('orders', $table->name);
        self::assertSame(
            [
                ['PRIMARY', IndexType::Primary, ['id']],
                ['uniq_order_number', IndexType::Unique, ['order_number']],
                ['idx_customer_status', IndexType::Index, ['customer_code', 'status']],
                ['idx_status', IndexType::Index, ['status']],
                ['ft_notes', IndexType::Fulltext, ['notes']],
                ['sp_location', IndexType::Spatial, ['location']],
            ],
            self::summarise($table),
        );
    }

    public function testParsesMultipleTablesAndIgnoresOtherStatements(): void
    {
        $tables = (new SchemaParser())->parse(<<<'SQL'
            SET NAMES utf8mb4;
            DROP TABLE IF EXISTS `a`;
            CREATE TABLE `a` (`id` int, PRIMARY KEY (`id`));
            INSERT INTO `a` VALUES (1);
            CREATE VIEW `v` AS SELECT * FROM `a`;
            CREATE TABLE `b` (`id` int, KEY `idx_id` (`id`));
            SQL);

        self::assertSame(['a', 'b'], array_map(static fn (Table $table): string => $table->name, $tables));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tableHeaders(): iterable
    {
        yield 'unquoted' => ['CREATE TABLE orders'];
        yield 'if not exists' => ['CREATE TABLE IF NOT EXISTS `orders`'];
        yield 'temporary' => ['create temporary table `orders`'];
        yield 'schema-qualified' => ['CREATE TABLE `shop`.`orders`'];
        yield 'schema-qualified, unquoted' => ['CREATE TABLE shop.orders'];
    }

    #[DataProvider('tableHeaders')]
    public function testReadsTableNameFromHeaderVariants(string $header): void
    {
        $table = $this->parseSingleTable($header . ' (id int, KEY idx_id (id));');

        self::assertSame('orders', $table->name);
        self::assertSame([['idx_id', IndexType::Index, ['id']]], self::summarise($table));
    }

    public function testSkipsCreateTableLike(): void
    {
        self::assertSame([], (new SchemaParser())->parse('CREATE TABLE `copy` LIKE `orders`;'));
    }

    public function testPreservesColumnOrderInMultiColumnIndexes(): void
    {
        $table = $this->parseSingleTable('CREATE TABLE t (a int, b int, c int, KEY idx (`c`, b,`a`))');

        self::assertSame([['idx', IndexType::Index, ['c', 'b', 'a']]], self::summarise($table));
    }

    public function testReadsPrefixLengthsAndSortDirection(): void
    {
        $table = $this->parseSingleTable(
            'CREATE TABLE t (name varchar(255), created_at datetime, KEY idx (`name`(10), created_at DESC, id ASC))',
        );

        self::assertEquals(
            [
                new IndexColumn('name', length: 10),
                new IndexColumn('created_at', descending: true),
                new IndexColumn('id'),
            ],
            $table->indexes[0]->columns,
        );
    }

    public function testReadsFunctionalKeyParts(): void
    {
        $table = $this->parseSingleTable('CREATE TABLE t (email varchar(255), KEY idx_lower ((LOWER(`email`))), KEY idx_b ((lower(email)) DESC))');

        self::assertEquals(new IndexColumn('lower(email)', isExpression: true), $table->indexes[0]->columns[0]);
        self::assertEquals(new IndexColumn('lower(email)', descending: true, isExpression: true), $table->indexes[1]->columns[0]);
    }

    public function testCommentsAndCommentStringsDoNotBreakParsing(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE `products` (
              `id` int NOT NULL, -- primary key, see below (really)
              `sku` varchar(64) NOT NULL COMMENT 'Stock code, KEY (sku) for lookups',
              # a hash comment with a comma, and UNIQUE KEY `fake` (`x`)
              `name` varchar(255) /* inline, block ) comment */ NOT NULL,
              `label` varchar(20) COMMENT 'it''s "quoted", \'escaped\' -- not a comment',
              PRIMARY KEY (`id`), -- trailing comment
              KEY `idx_sku` (`sku`) COMMENT 'used by the (search) page, mostly'
            );
            SQL);

        self::assertSame(
            [
                ['PRIMARY', IndexType::Primary, ['id']],
                ['idx_sku', IndexType::Index, ['sku']],
            ],
            self::summarise($table),
        );
    }

    public function testReadsIndexesDeclaredInlineOnColumns(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE users (
              id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
              email varchar(255) NOT NULL UNIQUE,
              username varchar(50) UNIQUE KEY COMMENT 'handle',
              unique_code varchar(10) NOT NULL,
              note varchar(20) DEFAULT 'PRIMARY KEY'
            )
            SQL);

        self::assertSame(
            [
                ['PRIMARY', IndexType::Primary, ['id']],
                ['email', IndexType::Unique, ['email']],
                ['username', IndexType::Unique, ['username']],
            ],
            self::summarise($table),
        );
    }

    public function testNamesConstraintIndexesAndUnnamedIndexesLikeMysql(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE t (
              a int, b int, c int,
              CONSTRAINT `pk_t` PRIMARY KEY (a),
              CONSTRAINT `uq_b` UNIQUE (b),
              CONSTRAINT `uq_ignored` UNIQUE KEY `uq_named` (c),
              CONSTRAINT UNIQUE (c, b),
              KEY (b),
              INDEX (b),
              KEY (b, c)
            )
            SQL);

        self::assertSame(
            ['PRIMARY', 'uq_b', 'uq_named', 'c', 'b', 'b_2', 'b_3'],
            array_map(static fn (Index $index): string => $index->name, $table->indexes),
        );
    }

    public function testIgnoresForeignKeysAndChecks(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE `order_items` (
              `order_id` int NOT NULL,
              `qty` int NOT NULL,
              KEY `fk_order` (`order_id`),
              CONSTRAINT `fk_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
              FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
              CONSTRAINT `chk_qty` CHECK ((`qty` > 0)),
              CHECK (`qty` < 1000)
            )
            SQL);

        self::assertSame([['fk_order', IndexType::Index, ['order_id']]], self::summarise($table));
    }

    public function testReadsIndexAlgorithmBeforeOrAfterColumns(): void
    {
        $table = $this->parseSingleTable(<<<'SQL'
            CREATE TABLE t (
              a int, b int,
              PRIMARY KEY USING HASH (a),
              KEY idx_b USING BTREE (b),
              KEY idx_ab (a, b) USING HASH COMMENT 'x',
              KEY idx_ba (b, a) COMMENT 'USING HASH'
            ) ENGINE=MEMORY
            SQL);

        self::assertSame(
            ['HASH', 'BTREE', 'HASH', null],
            array_map(static fn (Index $index): ?string => $index->algorithm, $table->indexes),
        );
    }

    public function testParsesTableWithoutIndexes(): void
    {
        self::assertSame([], $this->parseSingleTable('CREATE TABLE t (a int, b varchar(10))')->indexes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSql(): iterable
    {
        yield 'unclosed table body' => ["CREATE TABLE `t` (\n  a int,\n  KEY idx (a)", 'Unterminated CREATE TABLE `t` on line 1'];
        yield 'missing table name' => ['CREATE TABLE (a int)', 'Expected a table name after CREATE TABLE on line 1'];
        yield 'index without columns' => ["CREATE TABLE t (\n a int,\n KEY idx\n)", 'Expected "(" to start the index column list on line 3'];
        yield 'empty column list' => ['CREATE TABLE t (a int, KEY idx ())', 'Empty index column list on line 1'];
        yield 'garbage in column list' => ['CREATE TABLE t (a int, KEY idx (a, = b))', 'Unexpected "=" in index column list on line 1'];
    }

    #[DataProvider('invalidSql')]
    public function testReportsMalformedDefinitions(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        (new SchemaParser())->parse($sql);
    }

    private function parseSingleTable(string $sql): Table
    {
        $tables = (new SchemaParser())->parse($sql);
        self::assertCount(1, $tables);

        return $tables[0];
    }

    /**
     * @return list<array{string, IndexType, list<string>}>
     */
    private static function summarise(Table $table): array
    {
        return array_map(
            static fn (Index $index): array => [
                $index->name,
                $index->type,
                array_map(static fn (IndexColumn $column): string => $column->name, $index->columns),
            ],
            $table->indexes,
        );
    }
}
