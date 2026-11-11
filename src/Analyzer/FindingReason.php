<?php

declare(strict_types=1);

namespace IndexFinder\Analyzer;

enum FindingReason: string
{
    /** Both indexes have exactly the same key parts. */
    case Duplicate = 'duplicate';
    /** The redundant index's key parts are a leftmost prefix of the covering index. */
    case LeftmostPrefix = 'leftmost_prefix';

    public function label(): string
    {
        return match ($this) {
            self::Duplicate => 'Duplicate',
            self::LeftmostPrefix => 'Leftmost prefix',
        };
    }
}
