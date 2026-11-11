<?php

declare(strict_types=1);

namespace IndexFinder\Analyzer;

use IndexFinder\Schema\Index;

final readonly class Finding
{
    public function __construct(
        public string $table,
        public Index $redundantIndex,
        public Index $coveringIndex,
        public FindingReason $reason,
    ) {
    }

    public function message(): string
    {
        return match ($this->reason) {
            FindingReason::Duplicate => \sprintf(
                'Index %s has the same columns as %s.',
                $this->redundantIndex->name,
                $this->coveringIndex->name,
            ),
            FindingReason::LeftmostPrefix => \sprintf(
                'Columns (%s) of %s are a leftmost prefix of %s (%s).',
                $this->redundantIndex->columnList(),
                $this->redundantIndex->name,
                $this->coveringIndex->name,
                $this->coveringIndex->columnList(),
            ),
        };
    }

    public function dropStatement(): string
    {
        return \sprintf(
            'ALTER TABLE %s DROP INDEX %s;',
            self::quoteIdentifier($this->table),
            self::quoteIdentifier($this->redundantIndex->name),
        );
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}
