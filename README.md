# MySQL Redundant Index Finder

[![Tests](https://github.com/sahilsharmawork67-cmyk/mysql-redundant-index-finder/actions/workflows/tests.yml/badge.svg)](https://github.com/sahilsharmawork67-cmyk/mysql-redundant-index-finder/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/php-8.2%20%7C%208.3%20%7C%208.4-777bb4)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A small PHP command-line tool that reads a MySQL schema dump and reports indexes that are
redundant: exact duplicates, and indexes whose columns are a leftmost prefix of another index on
the same table. For each one it explains why and prints the `ALTER TABLE ... DROP INDEX` statement
to remove it.

It works entirely offline on `.sql` files (for example `mysqldump --no-data` output), so it needs no
database connection and fits into a CI pipeline.

## Why prefix indexes are redundant

MySQL B-tree indexes are sorted by their columns from left to right, so an index on
`(customer_id, status)` can serve any lookup or sort that an index on `(customer_id)` alone could.
The shorter index adds nothing for reads, but every `INSERT`, `UPDATE` and `DELETE` still has to
maintain it, and it takes up disk space and buffer pool memory.

## Rules

An index is reported when another index on the same table covers it:

| Situation | Reported? |
| --- | --- |
| `KEY (a)` and `KEY (a, b)` | Yes, `KEY (a)` is a leftmost prefix |
| `KEY (a, b)` twice | Yes, the one declared later |
| `KEY (a)` and `UNIQUE (a)` or `PRIMARY KEY (a)` | Yes, the plain `KEY` |
| `UNIQUE (a)` and `PRIMARY KEY (a)` | Yes, the `UNIQUE` index |
| `KEY (b)` and `KEY (a, b)` | No, not a *leftmost* prefix |

It deliberately errs on the side of not reporting:

- **UNIQUE indexes** are not redundant just because a longer index starts with the same columns.
  `UNIQUE (email)` enforces a constraint that `KEY (email, name)` does not.
- **PRIMARY KEY** is never reported.
- **FULLTEXT and SPATIAL** indexes are only compared with indexes of the same type, and only exact
  duplicates are reported (`FULLTEXT (a)` is not covered by `FULLTEXT (a, b)`).
- **Prefix lengths** must match exactly: `KEY (name(10))` and `KEY (name)` are treated as
  different columns. The same applies to sort direction (`DESC`) and functional key parts.
- **HASH indexes** (`USING HASH`, e.g. on `MEMORY` tables) cannot serve prefix lookups, so they are
  only reported as exact duplicates of another HASH index.

When several indexes cover the same redundant index, the finding points at one that is itself
kept, so applying all suggested fixes never removes the last index serving a query.

## Installation

Requires PHP 8.2 or newer and Composer.

```bash
git clone https://github.com/sahilsharmawork67-cmyk/mysql-redundant-index-finder.git
cd mysql-redundant-index-finder
composer install
```

## Usage

```bash
bin/index-finder [--format=table|json] <schema.sql>
```

Use `-` to read from standard input, for example straight from `mysqldump`:

```bash
mysqldump --no-data shop | bin/index-finder -
```

### Example

Running it on the bundled [`examples/schema.sql`](examples/schema.sql):

```text
$ bin/index-finder examples/schema.sql
Found 7 redundant indexes (4 tables, 27 indexes scanned).

+-------------+-------------------------------------------+---------------------------------------------------------------+-----------------+
| Table       | Redundant index                           | Covered by                                                    | Reason          |
+-------------+-------------------------------------------+---------------------------------------------------------------+-----------------+
| customers   | idx_email (email)                         | uniq_email (email) UNIQUE                                     | Duplicate       |
| customers   | idx_last_name (last_name)                 | idx_last_first_name (last_name, first_name)                   | Leftmost prefix |
| products    | idx_category (category_id)                | idx_category_active (category_id, is_active)                  | Leftmost prefix |
| orders      | fk_orders_customer (customer_id)          | idx_customer_status_created (customer_id, status, created_at) | Leftmost prefix |
| orders      | idx_customer_status (customer_id, status) | idx_customer_status_created (customer_id, status, created_at) | Leftmost prefix |
| orders      | idx_status_2 (status)                     | idx_status (status)                                           | Duplicate       |
| order_items | idx_order (order_id)                      | PRIMARY (order_id, product_id)                                | Leftmost prefix |
+-------------+-------------------------------------------+---------------------------------------------------------------+-----------------+

Suggested fixes (review before running in production):

ALTER TABLE `customers` DROP INDEX `idx_email`;
ALTER TABLE `customers` DROP INDEX `idx_last_name`;
ALTER TABLE `products` DROP INDEX `idx_category`;
ALTER TABLE `orders` DROP INDEX `fk_orders_customer`;
ALTER TABLE `orders` DROP INDEX `idx_customer_status`;
ALTER TABLE `orders` DROP INDEX `idx_status_2`;
ALTER TABLE `order_items` DROP INDEX `idx_order`;
```

Note what is *not* reported: `uniq_sku` stays despite `uniq_sku_category (sku, category_id)`,
`idx_last_name_prefix (last_name(10))` stays because its prefix length differs, and the FULLTEXT
index `ft_name` is not compared with the B-tree index `idx_name`.

### JSON output

```bash
bin/index-finder --format=json examples/schema.sql
```

Output, shortened to the first finding:

```json
{
    "summary": {
        "tables_scanned": 4,
        "indexes_scanned": 27,
        "redundant_indexes": 7
    },
    "findings": [
        {
            "table": "customers",
            "redundant_index": {
                "name": "idx_email",
                "type": "INDEX",
                "columns": ["email"]
            },
            "covering_index": {
                "name": "uniq_email",
                "type": "UNIQUE",
                "columns": ["email"]
            },
            "reason": "duplicate",
            "message": "Index idx_email has the same columns as uniq_email.",
            "fix": "ALTER TABLE `customers` DROP INDEX `idx_email`;"
        }
    ]
}
```

`reason` is either `duplicate` or `leftmost_prefix`.

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | No redundant indexes found |
| `1` | Redundant indexes found |
| `2` | Invalid usage, unreadable file or unparseable schema |

Because findings return `1`, the tool can fail a CI job when a migration introduces a redundant
index:

```yaml
- run: mysqldump --no-data --skip-comments app > schema.sql
- run: vendor/bin/index-finder schema.sql
```

## Before you drop an index

The suggestions are based on the schema alone, so review them first:

- MySQL needs an index on foreign key columns. Dropping an index that a foreign key uses is only
  allowed when another index starts with the same column, which is exactly the case for a
  leftmost-prefix finding, but check that the covering index is the one you intend to keep.
- Index hints (`USE INDEX`, `FORCE INDEX`) in application code that name the dropped index will
  fail.
- A shorter index is smaller, so for some very hot queries it can be marginally faster. On
  MySQL 8.0+ you can make an index `INVISIBLE` first to test the impact safely.

## Scope and limitations

The parser is focused on `CREATE TABLE` statements: table-level `PRIMARY KEY`, `UNIQUE`,
`KEY`/`INDEX`, `FULLTEXT` and `SPATIAL` definitions, inline `PRIMARY KEY`/`UNIQUE` column
attributes, `CONSTRAINT` names, prefix lengths, `ASC`/`DESC`, functional key parts and
`USING BTREE|HASH`. Comments (`--`, `#`, `/* */`) and quoted strings are handled safely. Other
statements are skipped. It does not apply later `ALTER TABLE ... ADD INDEX` statements, and it does
not account for InnoDB implicitly appending primary key columns to secondary indexes.

## Development

```bash
composer test      # PHPUnit: parser, analyzer, reporters and end-to-end tests
composer analyse   # PHPStan at level max
```

Project layout:

```text
bin/index-finder           CLI entry point
src/Parser/                Tokenizer and CREATE TABLE parser
src/Schema/                Table, Index, IndexColumn and IndexType value objects
src/Analyzer/              Redundancy rules and findings
src/Report/                Table and JSON reporters
src/Console/               Argument handling and exit codes
tests/fixtures/            Schemas used by the end-to-end tests
```

GitHub Actions runs static analysis and the test suite on PHP 8.2, 8.3 and 8.4.

## License

MIT. See [LICENSE](LICENSE).
