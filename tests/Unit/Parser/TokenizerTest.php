<?php

declare(strict_types=1);

namespace IndexFinder\Tests\Unit\Parser;

use IndexFinder\Parser\ParseException;
use IndexFinder\Parser\Token;
use IndexFinder\Parser\Tokenizer;
use IndexFinder\Parser\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenizerTest extends TestCase
{
    public function testSplitsWordsIdentifiersStringsAndSymbols(): void
    {
        $tokens = (new Tokenizer())->tokenize("KEY `idx a` (name(10)) COMMENT 'x'");

        self::assertSame(
            [
                [TokenType::Word, 'KEY'],
                [TokenType::QuotedIdentifier, 'idx a'],
                [TokenType::Symbol, '('],
                [TokenType::Word, 'name'],
                [TokenType::Symbol, '('],
                [TokenType::Word, '10'],
                [TokenType::Symbol, ')'],
                [TokenType::Symbol, ')'],
                [TokenType::Word, 'COMMENT'],
                [TokenType::String, 'x'],
            ],
            self::summarise($tokens),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function comments(): iterable
    {
        yield 'double dash' => ["a -- comment, with (parens)\nb"];
        yield 'double dash at end of input' => ["a\nb --"];
        yield 'hash' => ["a # comment KEY (x)\nb"];
        yield 'block' => ["a /* KEY (x),\n multi-line */ b"];
        yield 'versioned block' => ['a /*!40101 SET NAMES utf8mb4 */ b'];
    }

    #[DataProvider('comments')]
    public function testSkipsComments(string $sql): void
    {
        self::assertSame(['a', 'b'], self::values((new Tokenizer())->tokenize($sql)));
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['5', '-', '-', '1'], self::values((new Tokenizer())->tokenize('5--1')));
    }

    public function testCommentMarkersInsideStringsAreKept(): void
    {
        $tokens = (new Tokenizer())->tokenize("'a -- b, /* c */ # d'");

        self::assertSame([[TokenType::String, 'a -- b, /* c */ # d']], self::summarise($tokens));
    }

    public function testUnescapesQuotes(): void
    {
        $tokens = (new Tokenizer())->tokenize("'it''s' 'say \\'hi\\'' \"double\" `odd``name`");

        self::assertSame(["it's", "say 'hi'", 'double', 'odd`name'], self::values($tokens));
    }

    public function testTracksLineNumbers(): void
    {
        $tokens = (new Tokenizer())->tokenize("a\n/* one\ntwo */ b\n'multi\nline' c");

        self::assertSame([1, 3, 4, 5], array_map(static fn (Token $token): int => $token->line, $tokens));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unterminated(): iterable
    {
        yield 'string' => ["a\n'oops", 'Unterminated string literal on line 2'];
        yield 'identifier' => ['`oops', 'Unterminated quoted identifier on line 1'];
        yield 'block comment' => ['/* oops', 'Unterminated block comment on line 1'];
    }

    #[DataProvider('unterminated')]
    public function testRejectsUnterminatedInput(string $sql, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        (new Tokenizer())->tokenize($sql);
    }

    /**
     * @param list<Token> $tokens
     * @return list<array{TokenType, string}>
     */
    private static function summarise(array $tokens): array
    {
        return array_map(static fn (Token $token): array => [$token->type, $token->value], $tokens);
    }

    /**
     * @param list<Token> $tokens
     * @return list<string>
     */
    private static function values(array $tokens): array
    {
        return array_map(static fn (Token $token): string => $token->value, $tokens);
    }
}
