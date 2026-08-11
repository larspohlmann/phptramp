<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * One site where a parameter is forwarded whole into another call.
 */
final class ForwardSite
{
    public function __construct(
        public readonly CalleeRef $callee,
        /** Positional index (int) or named-argument key (string) of the forward. */
        public readonly int|string $argKey,
        public readonly int $line,
    ) {
    }
}
