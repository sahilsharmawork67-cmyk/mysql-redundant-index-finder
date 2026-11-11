<?php

declare(strict_types=1);

namespace IndexFinder\Tests\EndToEnd;

use IndexFinder\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the full pipeline (read file -> parse -> analyse -> report) through
 * the Application class with in-memory streams.
 */
final class ApplicationTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    public function testFindsRedundantIndexesInFixtureSchema(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runApplication(['--format=json', self::FIXTURES . '/schema.sql']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stderr);

        /** @var array{summary: array<string, int>, findings: list<array<string, mixed>>} $report */
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['tables_scanned' => 3, 'indexes_scanned' => 17, 'redundant_indexes' => 4], $report['summary']);
        self::assertSame(
            [
                ['accounts', 'idx_id', 'PRIMARY', 'duplicate', 'ALTER TABLE `accounts` DROP INDEX `idx_id`;'],
                ['invoices', 'account_id', 'idx_account_state_issued', 'leftmost_prefix', 'ALTER TABLE `invoices` DROP INDEX `account_id`;'],
                ['invoices', 'account_id_2', 'idx_account_state_issued', 'leftmost_prefix', 'ALTER TABLE `invoices` DROP INDEX `account_id_2`;'],
                ['invoices', 'ft_notes_copy', 'ft_notes', 'duplicate', 'ALTER TABLE `invoices` DROP INDEX `ft_notes_copy`;'],
            ],
            array_map(
                static fn (array $finding): array => [
                    $finding['table'],
                    $finding['redundant_index']['name'],
                    $finding['covering_index']['name'],
                    $finding['reason'],
                    $finding['fix'],
                ],
                $report['findings'],
            ),
        );
    }

    public function testRendersTableByDefault(): void
    {
        [$exitCode, $stdout] = $this->runApplication([self::FIXTURES . '/schema.sql']);

        self::assertSame(1, $exitCode);
        self::assertStringStartsWith('Found 4 redundant indexes (3 tables, 17 indexes scanned).', $stdout);
        self::assertStringContainsString('| invoices | account_id_2 (account_id) ', $stdout);
        self::assertStringContainsString("ALTER TABLE `accounts` DROP INDEX `idx_id`;\n", $stdout);
    }

    public function testExitsWithZeroForCleanSchema(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runApplication([self::FIXTURES . '/clean.sql']);

        self::assertSame(0, $exitCode);
        self::assertSame("No redundant indexes found (1 table, 3 indexes scanned).\n", $stdout);
        self::assertSame('', $stderr);
    }

    public function testReadsSchemaFromStandardInput(): void
    {
        $sql = (string) file_get_contents(self::FIXTURES . '/schema.sql');
        [$exitCode, $stdout] = $this->runApplication(['--format', 'json', '-'], $sql);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"redundant_indexes": 4', $stdout);
    }

    public function testExampleSchemaShipsWithFindings(): void
    {
        [$exitCode, $stdout] = $this->runApplication(['--format=json', __DIR__ . '/../../examples/schema.sql']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('"redundant_indexes": 7', $stdout);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidInvocations(): iterable
    {
        yield 'no arguments' => [[], 'Error: Missing schema file.'];
        yield 'two files' => [['a.sql', 'b.sql'], 'Error: Expected exactly one schema file.'];
        yield 'unknown option' => [['--verbose', 'a.sql'], 'Error: Unknown option "--verbose".'];
        yield 'unknown format' => [['--format=xml', 'a.sql'], 'Error: Unknown format "xml". Expected one of: table, json.'];
        yield 'missing file' => [['does-not-exist.sql'], 'Error: Schema file "does-not-exist.sql" does not exist or is not readable.'];
        yield 'unparseable file' => [[self::FIXTURES . '/invalid.sql'], 'Parse error: Unterminated string literal on line 3'];
        yield 'no tables' => [[self::FIXTURES . '/no-tables.sql'], 'Error: No CREATE TABLE statements found in'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidInvocations')]
    public function testExitsWithTwoOnErrors(array $arguments, string $message): void
    {
        [$exitCode, $stdout, $stderr] = $this->runApplication($arguments);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringStartsWith($message, $stderr);
    }

    public function testShowsHelp(): void
    {
        [$exitCode, $stdout] = $this->runApplication(['--help']);

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith('Usage: index-finder [options] <schema.sql>', $stdout);
        self::assertStringContainsString('--format=FORMAT', $stdout);
    }

    public function testShowsVersion(): void
    {
        [$exitCode, $stdout] = $this->runApplication(['-V']);

        self::assertSame(0, $exitCode);
        self::assertSame('index-finder ' . Application::VERSION . "\n", $stdout);
    }

    /**
     * @param list<string> $arguments
     * @return array{int, string, string}
     */
    private function runApplication(array $arguments, string $stdin = ''): array
    {
        $in = $this->memoryStream($stdin);
        $out = $this->memoryStream();
        $err = $this->memoryStream();

        $exitCode = (new Application())->run($arguments, $in, $out, $err);

        return [$exitCode, $this->contents($out), $this->contents($err)];
    }

    /**
     * @return resource
     */
    private function memoryStream(string $contents = '')
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
