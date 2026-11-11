<?php

declare(strict_types=1);

namespace IndexFinder\Report;

use IndexFinder\Analyzer\AnalysisResult;

interface Reporter
{
    public function render(AnalysisResult $result): string;
}
