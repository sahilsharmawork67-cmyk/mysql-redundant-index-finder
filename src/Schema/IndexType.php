<?php

declare(strict_types=1);

namespace IndexFinder\Schema;

enum IndexType: string
{
    case Primary = 'PRIMARY';
    case Unique = 'UNIQUE';
    case Index = 'INDEX';
    case Fulltext = 'FULLTEXT';
    case Spatial = 'SPATIAL';

    /**
     * Whether the index enforces a uniqueness constraint.
     */
    public function isUnique(): bool
    {
        return $this === self::Primary || $this === self::Unique;
    }

    /**
     * FULLTEXT and SPATIAL indexes use their own structures and can only be
     * compared with indexes of the same type. B-tree style indexes (PRIMARY,
     * UNIQUE, plain INDEX) can be compared with each other.
     */
    public function isComparableWith(self $other): bool
    {
        return match ($this) {
            self::Fulltext, self::Spatial => $other === $this,
            default => !\in_array($other, [self::Fulltext, self::Spatial], true),
        };
    }

    /**
     * Whether a shorter index of this type can be served by a longer one
     * starting with the same columns. FULLTEXT and SPATIAL cannot.
     */
    public function supportsLeftmostPrefix(): bool
    {
        return !\in_array($this, [self::Fulltext, self::Spatial], true);
    }

    /**
     * Used to decide which of two exact duplicates to keep: the stronger
     * constraint wins.
     */
    public function strength(): int
    {
        return match ($this) {
            self::Primary => 2,
            self::Unique => 1,
            default => 0,
        };
    }
}
