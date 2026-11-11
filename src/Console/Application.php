<?php

declare(strict_types=1);

namespace IndexFinder\Console;

use IndexFinder\Analyzer\RedundancyAnalyzer;
use IndexFinder\Parser\ParseException;
use IndexFinder\Parser\SchemaParser;
use RuntimeException;

/**
 * The `index-finder` command. Kept free of globals so it can be tested by
 * passing arguments and in-memory streams.
 */
final class Application
{
    public const NAME = 'index-finder';
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly SchemaParser $parser = new SchemaParser(),
        private readonly RedundancyAnalyzer $analyzer = new RedundancyAnalyzer(),
    ) {
    }

    /**
     * @param list<string> $arguments Command-line arguments, without the script name
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $arguments, $stdin, $stdout, $stderr): int
    {
        try {
            $options = $this->parseArguments($arguments);

            if ($options['help']) {
                fwrite($stdout, $this->help());

                return ExitCode::NoFindings->value;
            }

            if ($options['version']) {
                fwrite($stdout, self::NAME . ' ' . self::VERSION . "\n");

                return ExitCode::NoFindings->value;
            }

            $path = $options['path'];
            $sql = $path === '-' ? stream_get_contents($stdin) : $this->readFile($path);
            if ($sql === false) {
                throw new RuntimeException('Could not read the schema from standard input.');
            }

            $tables = $this->parser->parse($sql);
            if ($tables === []) {
                throw new RuntimeException(\sprintf('No CREATE TABLE statements found in %s.', $path === '-' ? 'standard input' : $path));
            }

            $result = $this->analyzer->analyze($tables);
            fwrite($stdout, $options['format']->reporter()->render($result));

            return ($result->hasFindings() ? ExitCode::FindingsFound : ExitCode::NoFindings)->value;
        } catch (UsageException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n\n" . $this->usage());

            return ExitCode::Error->value;
        } catch (ParseException $e) {
            fwrite($stderr, 'Parse error: ' . $e->getMessage() . "\n");

            return ExitCode::Error->value;
        } catch (RuntimeException $e) {
            fwrite($stderr, 'Error: ' . $e->getMessage() . "\n");

            return ExitCode::Error->value;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{help: bool, version: bool, format: OutputFormat, path: string}
     */
    private function parseArguments(array $arguments): array
    {
        $options = ['help' => false, 'version' => false, 'format' => OutputFormat::Table];
        $paths = [];
        $onlyPaths = false;

        for ($i = 0, $count = \count($arguments); $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($onlyPaths || $argument === '-' || !str_starts_with($argument, '-')) {
                $paths[] = $argument;
                continue;
            }

            if ($argument === '--') {
                $onlyPaths = true;
            } elseif ($argument === '-h' || $argument === '--help') {
                $options['help'] = true;
            } elseif ($argument === '-V' || $argument === '--version') {
                $options['version'] = true;
            } elseif ($argument === '--format' || str_starts_with($argument, '--format=')) {
                $value = $argument === '--format' ? ($arguments[++$i] ?? '') : substr($argument, \strlen('--format='));
                $options['format'] = OutputFormat::tryFrom(strtolower($value)) ?? throw new UsageException(\sprintf(
                    'Unknown format "%s". Expected one of: %s.',
                    $value,
                    implode(', ', OutputFormat::names()),
                ));
            } else {
                throw new UsageException(\sprintf('Unknown option "%s".', $argument));
            }
        }

        if ($options['help'] || $options['version']) {
            return $options + ['path' => ''];
        }

        if (\count($paths) !== 1) {
            throw new UsageException($paths === [] ? 'Missing schema file.' : 'Expected exactly one schema file.');
        }

        return $options + ['path' => $paths[0]];
    }

    private function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(\sprintf('Schema file "%s" does not exist or is not readable.', $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(\sprintf('Could not read schema file "%s".', $path));
        }

        return $contents;
    }

    private function usage(): string
    {
        return 'Usage: ' . self::NAME . " [--format=table|json] <schema.sql|->\n"
            . 'Run "' . self::NAME . " --help\" for more information.\n";
    }

    private function help(): string
    {
        return <<<HELP
            Usage: index-finder [options] <schema.sql>

            Finds redundant MySQL indexes (exact duplicates and leftmost prefixes of
            other indexes) in a file of CREATE TABLE statements, such as the output
            of `mysqldump --no-data`. Use "-" to read the schema from standard input.

            Options:
              --format=FORMAT  Output format: table (default) or json
              -h, --help       Show this help
              -V, --version    Show the version

            Exit codes:
              0  No redundant indexes found
              1  Redundant indexes found
              2  Invalid usage, unreadable file or unparseable schema

            HELP;
    }
}
