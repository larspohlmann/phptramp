<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * A forwarding site resolved to a concrete method inside analyzed code, together
 * with the name of the target parameter the argument binds to.
 */
final class ResolvedTarget implements Resolution
{
    public function __construct(
        public readonly string $fqmn,
        public readonly string $boundParam,
    ) {
    }

    public function describe(): string
    {
        return $this->fqmn;
    }
}
