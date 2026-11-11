<?php

declare(strict_types=1);

namespace IndexFinder\Schema;

/**
 * One key part of an index: a column (optionally with a prefix length and
 * sort direction) or a functional expression.
 */
final readonly class IndexColumn
{
    public function __construct(
        public string $name,
        public ?int $length = null,
        public bool $descending = false,
        public bool $isExpression = false,
    ) {
    }

    /**
     * Key parts are only considered equal when the column, the prefix length
     * and the sort direction all match. `name(10)` and `name` are deliberately
     * treated as different: we would rather miss a finding than suggest
     * dropping an index that is actually needed.
     */
    public function equals(self $other): bool
    {
        return strtolower($this->name) === strtolower($other->name)
            && $this->length === $other->length
            && $this->descending === $other->descending
            && $this->isExpression === $other->isExpression;
    }

    public function toString(): string
    {
        $text = $this->isExpression ? '(' . $this->name . ')' : $this->name;

        if ($this->length !== null) {
            $text .= '(' . $this->length . ')';
        }

        return $this->descending ? $text . ' DESC' : $text;
    }
}
