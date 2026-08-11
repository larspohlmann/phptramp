<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * One node of a reported chain: the method it lands in and the source location,
 * plus the line of the forwarding call site it leaves through (null on the
 * terminal node, which forwards nowhere).
 */
final class Hop
{
    public function __construct(
        public readonly string $fqmn,
        public readonly ?string $class,
        public readonly string $file,
        public readonly int $line,
        /** Line of the forwarding call site; null on the terminal node. */
        public readonly ?int $forwardLine,
        /** Whether this hop intersects the diff in diff-aware mode. */
        public readonly bool $changed = false,
    ) {
    }
}
