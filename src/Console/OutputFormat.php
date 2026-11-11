<?php

declare(strict_types=1);

namespace IndexFinder\Console;

use IndexFinder\Report\JsonReporter;
use IndexFinder\Report\Reporter;
use IndexFinder\Report\TextReporter;

enum OutputFormat: string
{
    case Table = 'table';
    case Json = 'json';

    public function reporter(): Reporter
    {
        return match ($this) {
            self::Table => new TextReporter(),
            self::Json => new JsonReporter(),
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $format): string => $format->value, self::cases());
    }
}
