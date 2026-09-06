<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * One forward of a parameter as the fan-out analysis sees it: the site that
 * forwarded it and the branch arms that site sits in.
 */
final class ForwardOccurrence
{
    public function __construct(
        public readonly ForwardSite $site,
        public readonly BranchArmPath $branchArms,
    ) {
    }
}
