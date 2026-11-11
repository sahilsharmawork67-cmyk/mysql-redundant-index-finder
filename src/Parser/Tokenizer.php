<?php

declare(strict_types=1);

namespace IndexFinder\Parser;

/**
 * Splits SQL text into tokens, dropping whitespace and comments.
 *
 * It understands just enough MySQL lexical structure to be safe around
 * quoted text: commas, parentheses or "--" inside a string literal or a
 * backtick-quoted identifier never leak out as structure.
 */
final class Tokenizer
{
    /**
     * @return list<Token>
     */
    public function tokenize(string $sql): array
    {
        $tokens = [];
        $length = \strlen($sql);
        $line = 1;
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === "\n") {
                $line++;
                $i++;
                continue;
            }

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // "-- comment" (MySQL requires whitespace or end of line after the dashes) and "# comment"
            if ($char === '#' || ($char === '-' && $this->isDashComment($sql, $i, $length))) {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            // "/* ... */", including MySQL's versioned "/*!40101 ... */" blocks
            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    throw ParseException::atLine('Unterminated block comment', $line);
                }
                $line += substr_count($sql, "\n", $i, $end - $i);
                $i = $end + 2;
                continue;
            }

            if ($char === '`' || $char === "'" || $char === '"') {
                $startLine = $line;
                [$value, $i] = $this->readQuoted($sql, $i, $length, $char, $line);
                $type = $char === '`' ? TokenType::QuotedIdentifier : TokenType::String;
                $tokens[] = new Token($type, $value, $startLine);
                continue;
            }

            if ($this->isWordChar($char)) {
                $start = $i;
                while ($i < $length && $this->isWordChar($sql[$i])) {
                    $i++;
                }
                $tokens[] = new Token(TokenType::Word, substr($sql, $start, $i - $start), $line);
                continue;
            }

            // Everything else is a one-character symbol. The parser only cares
            // about ( ) , ; and . - the rest are carried along harmlessly.
            $tokens[] = new Token(TokenType::Symbol, $char, $line);
            $i++;
        }

        return $tokens;
    }

    private function isDashComment(string $sql, int $i, int $length): bool
    {
        if (($sql[$i + 1] ?? '') !== '-') {
            return false;
        }

        return $i + 2 >= $length || ctype_space($sql[$i + 2]);
    }

    private function isWordChar(string $char): bool
    {
        // Bytes >= 0x80 belong to multibyte UTF-8 characters, which MySQL allows in unquoted identifiers.
        return ctype_alnum($char) || $char === '_' || $char === '$' || \ord($char) >= 0x80;
    }

    /**
     * Reads a quoted string or identifier starting at $start. Handles doubled
     * quote characters ('it''s') and, for string literals, backslash escapes.
     *
     * @return array{string, int} The unquoted value and the offset after the closing quote
     */
    private function readQuoted(string $sql, int $start, int $length, string $quote, int &$line): array
    {
        $value = '';
        $i = $start + 1;
        $startLine = $line;

        while ($i < $length) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $value .= $sql[$i + 1];
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $value .= $quote;
                    $i += 2;
                    continue;
                }

                return [$value, $i + 1];
            }

            if ($char === "\n") {
                $line++;
            }

            $value .= $char;
            $i++;
        }

        $kind = $quote === '`' ? 'quoted identifier' : 'string literal';

        throw ParseException::atLine('Unterminated ' . $kind, $startLine);
    }
}
