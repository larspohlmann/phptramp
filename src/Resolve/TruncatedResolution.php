<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * The forward could be resolved in principle but v0.1 conservatively declines to
 * follow it (untypeable receiver, multiple interface implementations, an
 * argument that binds to no parameter). The chain is reported as at least this
 * long, with the reason attached.
 */
final class TruncatedResolution implements Resolution
{
    public function __construct(public readonly string $reason)
    {
    }

    public function describe(): string
    {
        return 'truncated: ' . $this->reason;
    }
}
