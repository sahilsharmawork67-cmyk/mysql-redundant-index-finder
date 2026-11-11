<?php

declare(strict_types=1);

namespace IndexFinder\Console;

enum ExitCode: int
{
    case NoFindings = 0;
    case FindingsFound = 1;
    case Error = 2;
}
