<?php

declare(strict_types=1);

namespace IndexFinder\Parser;

final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $line,
    ) {
    }

    public function isKeyword(string ...$keywords): bool
    {
        if ($this->type !== TokenType::Word) {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (strcasecmp($this->value, $keyword) === 0) {
                return true;
            }
        }

        return false;
    }

    public function isSymbol(string $symbol): bool
    {
        return $this->type === TokenType::Symbol && $this->value === $symbol;
    }

    public function isIdentifier(): bool
    {
        return $this->type === TokenType::Word || $this->type === TokenType::QuotedIdentifier;
    }
}
