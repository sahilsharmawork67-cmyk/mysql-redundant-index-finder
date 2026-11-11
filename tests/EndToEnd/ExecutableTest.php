<?php

declare(strict_types=1);

namespace IndexFinder\Tests\EndToEnd;

use PHPUnit\Framework\TestCase;

/**
 * Smoke-tests the real bin/index-finder script in a separate PHP process,
 * including the process exit code that CI pipelines rely on.
 */
final class ExecutableTest extends TestCase
{
    private const BINARY = __DIR__ . '/../../bin/index-finder';
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testExitsWithOneWhenFindingsExist(): void
    {
        [$exitCode, $stdout] = $this->execute(self::FIXTURES . '/schema.sql');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ALTER TABLE `invoices` DROP INDEX `ft_notes_copy`;', $stdout);
    }

    public function testExitsWithZeroWhenSchemaIsClean(): void
    {
        [$exitCode, $stdout] = $this->execute(self::FIXTURES . '/clean.sql');

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith('No redundant indexes found', $stdout);
    }

    public function testExitsWithTwoOnInvalidInput(): void
    {
        [$exitCode, , $stderr] = $this->execute(self::FIXTURES . '/invalid.sql');

        self::assertSame(2, $exitCode);
        self::assertStringStartsWith('Parse error:', $stderr);
    }

    /**
     * @return array{int, string, string}
     */
    private function execute(string ...$arguments): array
    {
        $command = array_merge([PHP_BINARY, self::BINARY], $arguments);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
