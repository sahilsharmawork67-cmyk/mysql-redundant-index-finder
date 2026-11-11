<?php

declare(strict_types=1);

namespace IndexFinder\Parser;

use IndexFinder\Schema\Index;
use IndexFinder\Schema\IndexColumn;
use IndexFinder\Schema\IndexType;
use IndexFinder\Schema\Table;

/**
 * Extracts tables and their index definitions from CREATE TABLE statements,
 * e.g. the output of `mysqldump --no-data`.
 *
 * This is intentionally not a full SQL parser. It finds CREATE TABLE
 * statements, splits their body into top-level definitions and reads the
 * index-related ones. Everything else (column types, foreign keys, CHECK
 * constraints, table options, other statements) is skipped.
 */
final class SchemaParser
{
    public function __construct(
        private readonly Tokenizer $tokenizer = new Tokenizer(),
    ) {
    }

    /**
     * @return list<Table>
     */
    public function parse(string $sql): array
    {
        $tokens = $this->tokenizer->tokenize($sql);
        $count = \count($tokens);
        $tables = [];

        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->isKeyword('CREATE')) {
                continue;
            }

            $next = $i + 1;
            if (isset($tokens[$next]) && $tokens[$next]->isKeyword('TEMPORARY')) {
                $next++;
            }

            if (isset($tokens[$next]) && $tokens[$next]->isKeyword('TABLE')) {
                [$table, $i] = $this->parseCreateTable($tokens, $next + 1);
                if ($table !== null) {
                    $tables[] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * @param list<Token> $tokens
     * @param int $pos Position right after the TABLE keyword
     * @return array{Table|null, int} The table (null for CREATE TABLE ... LIKE/SELECT) and the last consumed position
     */
    private function parseCreateTable(array $tokens, int $pos): array
    {
        if ($this->keywordsAt($tokens, $pos, ['IF', 'NOT', 'EXISTS'])) {
            $pos += 3;
        }

        $nameToken = $tokens[$pos] ?? null;
        if ($nameToken === null || !$nameToken->isIdentifier()) {
            throw ParseException::atLine('Expected a table name after CREATE TABLE', $nameToken->line ?? $this->lastLine($tokens));
        }

        // Qualified names (`shop`.`orders`) keep only the table part.
        $name = $nameToken->value;
        while (($tokens[$pos + 1] ?? null)?->isSymbol('.') && ($tokens[$pos + 2] ?? null)?->isIdentifier()) {
            $pos += 2;
            $name = $tokens[$pos]->value;
        }
        $pos++;

        if (!($tokens[$pos] ?? null)?->isSymbol('(')) {
            return [null, $pos - 1];
        }

        $close = $this->findClosingParenthesis($tokens, $pos);
        if ($close === null) {
            throw ParseException::atLine(\sprintf('Unterminated CREATE TABLE `%s`', $name), $nameToken->line);
        }

        $body = \array_slice($tokens, $pos + 1, $close - $pos - 1);
        $indexes = [];

        foreach ($this->splitOnCommas($body) as $definition) {
            $index = $this->parseDefinition($definition);
            if ($index !== null) {
                $indexes[] = $index;
            }
        }

        return [new Table($name, $this->assignUniqueNames($indexes)), $close];
    }

    /**
     * Parses one top-level definition from a CREATE TABLE body. Returns null
     * for definitions that do not create an index (most columns, FOREIGN KEY,
     * CHECK).
     *
     * @param non-empty-list<Token> $tokens
     */
    private function parseDefinition(array $tokens): ?Index
    {
        $pos = 0;
        $constraintName = null;

        if ($tokens[0]->isKeyword('CONSTRAINT')) {
            $pos = 1;
            $candidate = $tokens[1] ?? null;
            if ($candidate !== null && $candidate->isIdentifier()
                && !$candidate->isKeyword('PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK')) {
                $constraintName = $candidate->value;
                $pos = 2;
            }
        }

        $keyword = $tokens[$pos] ?? null;
        if ($keyword === null) {
            return null;
        }

        $type = match (true) {
            $keyword->isKeyword('PRIMARY') && ($tokens[$pos + 1] ?? null)?->isKeyword('KEY') === true => IndexType::Primary,
            $keyword->isKeyword('UNIQUE') => IndexType::Unique,
            $keyword->isKeyword('KEY', 'INDEX') => IndexType::Index,
            $keyword->isKeyword('FULLTEXT') => IndexType::Fulltext,
            $keyword->isKeyword('SPATIAL') => IndexType::Spatial,
            default => null,
        };

        if ($type === null) {
            // FOREIGN KEY, CHECK and similar never define a separate index here.
            if ($pos > 0 || $keyword->isKeyword('FOREIGN', 'CHECK', 'PERIOD')) {
                return null;
            }

            return $this->parseColumnDefinition($tokens);
        }

        $pos += $type === IndexType::Primary ? 2 : 1;
        if ($type !== IndexType::Primary && ($tokens[$pos] ?? null)?->isKeyword('KEY', 'INDEX')) {
            $pos++;
        }

        $name = $type === IndexType::Primary ? 'PRIMARY' : $constraintName;
        $current = $tokens[$pos] ?? null;
        if ($type !== IndexType::Primary && $current !== null && $current->isIdentifier() && !$current->isKeyword('USING')) {
            $name = $current->value;
            $pos++;
        }

        $algorithm = null;
        if (($tokens[$pos] ?? null)?->isKeyword('USING')) {
            $algorithm = $this->algorithmAt($tokens, $pos + 1);
            $pos += 2;
        }

        if (!($tokens[$pos] ?? null)?->isSymbol('(')) {
            throw ParseException::atLine('Expected "(" to start the index column list', ($tokens[$pos] ?? $keyword)->line);
        }

        $close = $this->findClosingParenthesis($tokens, $pos);
        if ($close === null) {
            throw ParseException::atLine('Unterminated index column list', $tokens[$pos]->line);
        }

        $columns = $this->parseColumnList(\array_slice($tokens, $pos + 1, $close - $pos - 1), $tokens[$pos]->line);

        // Index options may also carry the algorithm: KEY idx (a) USING BTREE
        for ($i = $close + 1, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->isKeyword('USING')) {
                $algorithm = $this->algorithmAt($tokens, $i + 1);
            }
        }

        // Unnamed indexes are named after their first column, as MySQL does.
        return new Index($name ?? $columns[0]->name, $type, $columns, $algorithm);
    }

    /**
     * Handles indexes declared inline on a column:
     * `id INT NOT NULL AUTO_INCREMENT PRIMARY KEY` or `email VARCHAR(255) UNIQUE`.
     *
     * @param non-empty-list<Token> $tokens
     */
    private function parseColumnDefinition(array $tokens): ?Index
    {
        if (!$tokens[0]->isIdentifier()) {
            return null;
        }

        $column = [new IndexColumn($tokens[0]->value)];
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if ($token->isSymbol('(')) {
                $depth++;
            } elseif ($token->isSymbol(')')) {
                $depth--;
            } elseif ($depth === 0 && $i > 0) {
                if ($token->isKeyword('PRIMARY') && ($tokens[$i + 1] ?? null)?->isKeyword('KEY')) {
                    return new Index('PRIMARY', IndexType::Primary, $column);
                }
                if ($token->isKeyword('UNIQUE')) {
                    return new Index($tokens[0]->value, IndexType::Unique, $column);
                }
            }
        }

        return null;
    }

    /**
     * Parses the key parts between the parentheses of an index definition:
     * `a`, `b`(10), c DESC, ((LOWER(email))).
     *
     * @param list<Token> $tokens
     * @return non-empty-list<IndexColumn>
     */
    private function parseColumnList(array $tokens, int $line): array
    {
        $columns = [];

        foreach ($this->splitOnCommas($tokens) as $part) {
            $first = $part[0];
            $next = 1;

            if ($first->isSymbol('(')) {
                // Functional key part (MySQL 8.0.13+): compare by normalised expression text.
                $close = $this->findClosingParenthesis($part, 0)
                    ?? throw ParseException::atLine('Unterminated functional key part', $first->line);
                $expression = $this->tokensToText(\array_slice($part, 1, $close - 1));
                $column = new IndexColumn($expression, isExpression: true);
                $next = $close + 1;
            } elseif ($first->isIdentifier()) {
                $length = null;
                if (($part[1] ?? null)?->isSymbol('(')
                    && isset($part[2], $part[3])
                    && ctype_digit($part[2]->value)
                    && $part[3]->isSymbol(')')) {
                    $length = (int) $part[2]->value;
                    $next = 4;
                }
                $column = new IndexColumn($first->value, $length);
            } else {
                throw ParseException::atLine(\sprintf('Unexpected "%s" in index column list', $first->value), $first->line);
            }

            if (($part[$next] ?? null)?->isKeyword('DESC')) {
                $column = new IndexColumn($column->name, $column->length, true, $column->isExpression);
            }

            $columns[] = $column;
        }

        if ($columns === []) {
            throw ParseException::atLine('Empty index column list', $line);
        }

        return $columns;
    }

    /**
     * MySQL names an unnamed index after its first column and appends _2, _3,
     * ... on collision. Mirror that so suggested DROP statements are correct.
     *
     * @param list<Index> $indexes
     * @return list<Index>
     */
    private function assignUniqueNames(array $indexes): array
    {
        $used = [];
        $result = [];

        foreach ($indexes as $index) {
            $name = $index->name;
            for ($suffix = 2; isset($used[strtolower($name)]); $suffix++) {
                $name = $index->name . '_' . $suffix;
            }
            $used[strtolower($name)] = true;
            $result[] = $name === $index->name ? $index : $index->withName($name);
        }

        return $result;
    }

    /**
     * Splits tokens on commas that are not nested inside parentheses.
     *
     * @param list<Token> $tokens
     * @return list<non-empty-list<Token>>
     */
    private function splitOnCommas(array $tokens): array
    {
        $parts = [];
        $current = [];
        $depth = 0;

        foreach ($tokens as $token) {
            if ($token->isSymbol('(')) {
                $depth++;
            } elseif ($token->isSymbol(')')) {
                $depth--;
            } elseif ($depth === 0 && $token->isSymbol(',')) {
                if ($current !== []) {
                    $parts[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * @param list<Token> $tokens
     */
    private function findClosingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $count = \count($tokens); $i < $count; $i++) {
            if ($tokens[$i]->isSymbol('(')) {
                $depth++;
            } elseif ($tokens[$i]->isSymbol(')')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<Token> $tokens
     * @param list<string> $keywords
     */
    private function keywordsAt(array $tokens, int $pos, array $keywords): bool
    {
        foreach ($keywords as $offset => $keyword) {
            if (!($tokens[$pos + $offset] ?? null)?->isKeyword($keyword)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Token> $tokens
     */
    private function algorithmAt(array $tokens, int $pos): ?string
    {
        $token = $tokens[$pos] ?? null;

        return $token !== null && $token->type === TokenType::Word ? strtoupper($token->value) : null;
    }

    /**
     * Rebuilds a compact, case-normalised text form of an expression so two
     * functional key parts can be compared.
     *
     * @param list<Token> $tokens
     */
    private function tokensToText(array $tokens): string
    {
        $text = '';

        foreach ($tokens as $token) {
            $text .= match ($token->type) {
                TokenType::String => "'" . str_replace("'", "''", $token->value) . "'",
                // `email` and email are the same identifier; drop the quoting.
                TokenType::Word, TokenType::QuotedIdentifier => ($text !== '' && preg_match('/[\w\']$/', $text) === 1 ? ' ' : '')
                    . strtolower($token->value),
                TokenType::Symbol => $token->value,
            };
        }

        return $text;
    }

    /**
     * @param list<Token> $tokens
     */
    private function lastLine(array $tokens): int
    {
        return $tokens === [] ? 1 : $tokens[\count($tokens) - 1]->line;
    }
}
