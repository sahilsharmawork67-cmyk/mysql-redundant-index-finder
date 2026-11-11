<?php

declare(strict_types=1);

namespace IndexFinder\Schema;

final readonly class Table
{
    /**
     * @param list<Index> $indexes In declaration order
     */
    public function __construct(
        public string $name,
        public array $indexes,
    ) {
    }

    public function findIndex(string $name): ?Index
    {
        foreach ($this->indexes as $index) {
            if (strcasecmp($index->name, $name) === 0) {
                return $index;
            }
        }

        return null;
    }
}
