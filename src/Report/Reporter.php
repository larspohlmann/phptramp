<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;

interface Reporter
{
    /**
     * @param list<Finding> $findings ALL findings, unfiltered — each reporter
     *                                 applies its Thresholds itself
     */
    public function render(array $findings): string;
}
