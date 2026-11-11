<?php

declare(strict_types=1);

namespace IndexFinder\Report;

use IndexFinder\Analyzer\AnalysisResult;
use IndexFinder\Analyzer\Finding;

/**
 * Renders findings as a plain-text table followed by the suggested fixes.
 */
final class TextReporter implements Reporter
{
    private const HEADERS = ['Table', 'Redundant index', 'Covered by', 'Reason'];

    public function render(AnalysisResult $result): string
    {
        $scanned = \sprintf(
            '%d %s, %d %s scanned',
            $result->tableCount(),
            $result->tableCount() === 1 ? 'table' : 'tables',
            $result->indexCount(),
            $result->indexCount() === 1 ? 'index' : 'indexes',
        );

        if (!$result->hasFindings()) {
            return \sprintf("No redundant indexes found (%s).\n", $scanned);
        }

        $count = \count($result->findings);
        $rows = array_map(static fn (Finding $finding): array => [
            $finding->table,
            $finding->redundantIndex->describe(),
            $finding->coveringIndex->describe(),
            $finding->reason->label(),
        ], $result->findings);

        $output = \sprintf("Found %d redundant %s (%s).\n\n", $count, $count === 1 ? 'index' : 'indexes', $scanned);
        $output .= $this->table(self::HEADERS, $rows);
        $output .= "\nSuggested fixes (review before running in production):\n\n";

        foreach ($result->findings as $finding) {
            $output .= $finding->dropStatement() . "\n";
        }

        return $output;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private function table(array $headers, array $rows): string
    {
        $widths = array_map(static fn (string $header): int => mb_strwidth($header), $headers);
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column], mb_strwidth($cell));
            }
        }

        $separator = '+' . implode('+', array_map(static fn (int $width): string => str_repeat('-', $width + 2), $widths)) . "+\n";
        $output = $separator . $this->row($headers, $widths) . $separator;
        foreach ($rows as $row) {
            $output .= $this->row($row, $widths);
        }

        return $output . $separator;
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths Column widths keyed by column position
     */
    private function row(array $cells, array $widths): string
    {
        $padded = [];
        foreach ($cells as $column => $cell) {
            $padded[] = ' ' . $cell . str_repeat(' ', $widths[$column] - mb_strwidth($cell)) . ' ';
        }

        return '|' . implode('|', $padded) . "|\n";
    }
}
