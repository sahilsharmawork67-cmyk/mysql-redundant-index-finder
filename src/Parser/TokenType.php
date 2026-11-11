<?php

declare(strict_types=1);

namespace IndexFinder\Parser;

enum TokenType
{
    /** Unquoted keyword, identifier or number. */
    case Word;
    /** Backtick-quoted identifier. */
    case QuotedIdentifier;
    /** Single- or double-quoted string literal. */
    case String;
    /** Any single punctuation character such as ( ) , ; . = */
    case Symbol;
}
