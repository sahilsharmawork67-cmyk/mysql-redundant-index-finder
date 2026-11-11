<?php

declare(strict_types=1);

namespace IndexFinder\Schema;

final readonly class Index
{
    /**
     * @param non-empty-list<IndexColumn> $columns
     * @param string|null $algorithm Upper-cased USING clause (BTREE, HASH), if any
     */
    public function __construct(
        public string $name,
        public IndexType $type,
        public array $columns,
        public ?string $algorithm = null,
    ) {
    }

    public function withName(string $name): self
    {
        return new self($name, $this->type, $this->columns, $this->algorithm);
    }

    public function columnCount(): int
    {
        return \count($this->columns);
    }

    public function usesHash(): bool
    {
        return $this->algorithm === 'HASH';
    }

    /**
     * Whether the columns of $other form a leftmost prefix of this index
     * (an identical column list counts as a prefix).
     */
    public function startsWith(self $other): bool
    {
        if ($other->columnCount() > $this->columnCount()) {
            return false;
        }

        foreach ($other->columns as $position => $column) {
            if (!$column->equals($this->columns[$position])) {
                return false;
            }
        }

        return true;
    }

    public function hasSameColumnsAs(self $other): bool
    {
        return $this->columnCount() === $other->columnCount() && $this->startsWith($other);
    }

    public function columnList(): string
    {
        return implode(', ', array_map(
            static fn (IndexColumn $column): string => $column->toString(),
            $this->columns,
        ));
    }

    /**
     * Short human-readable description, e.g. "idx_status (status, created_at)".
     */
    public function describe(): string
    {
        $text = $this->name . ' (' . $this->columnList() . ')';

        return match ($this->type) {
            IndexType::Index, IndexType::Primary => $text,
            default => $text . ' ' . $this->type->value,
        };
    }
}
