<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * One forward of a parameter as the fan-out analysis sees it: the callee that
 * received it and the branch arms it sits in.
 */
final class ForwardOccurrence
{
    public function __construct(
        public readonly CalleeRef $callee,
        public readonly BranchArmPath $branchArms,
    ) {
    }
}
