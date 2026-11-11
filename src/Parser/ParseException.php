<?php

declare(strict_types=1);

namespace IndexFinder\Parser;

use RuntimeException;

final class ParseException extends RuntimeException
{
    public static function atLine(string $message, int $line): self
    {
        return new self(\sprintf('%s on line %d', $message, $line));
    }
}
