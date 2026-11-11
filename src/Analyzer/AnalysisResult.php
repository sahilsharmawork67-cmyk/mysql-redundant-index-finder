<?php

declare(strict_types=1);

namespace IndexFinder\Analyzer;

use IndexFinder\Schema\Table;

final readonly class AnalysisResult
{
    /**
     * @param list<Table> $tables
     * @param list<Finding> $findings
     */
    public function __construct(
        public array $tables,
        public array $findings,
    ) {
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }

    public function tableCount(): int
    {
        return \count($this->tables);
    }

    public function indexCount(): int
    {
        return array_sum(array_map(static fn (Table $table): int => \count($table->indexes), $this->tables));
    }
}
