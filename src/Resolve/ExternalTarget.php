<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * The forward leaves analyzed code (unknown function, vendor class, interface
 * with no implementation in the index). This terminates the chain like a use:
 * the value was consumed, so the callee is not a mindless hop.
 */
final class ExternalTarget implements Resolution
{
    /** Why the target counts as outside analyzed code — for --explain. */
    public function __construct(public readonly string $detail)
    {
    }
}
