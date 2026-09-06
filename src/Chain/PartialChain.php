<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * The immutable in-progress state of one depth-first walk: the pure-forward hops
 * committed so far (the terminal is never one of them), the resolution trace,
 * and the set of node keys on the path for cycle detection.
 */
final class PartialChain
{
    /**
     * @param list<Hop> $hops
     * @param list<string> $trace
     * @param list<string> $keys
     */
    public function __construct(
        public readonly string $originParam,
        public readonly array $hops = [],
        public readonly array $trace = [],
        public readonly array $keys = [],
    ) {
    }

    public function append(Hop $hop, string $traceLine, string $key): self
    {
        return new self(
            $this->originParam,
            [...$this->hops, $hop],
            [...$this->trace, $traceLine],
            [...$this->keys, $key],
        );
    }

    /**
     * A copy with one more resolution-trace line but no new hop — for an edge
     * that ends the chain at the current node (an internal-function use) rather
     * than advancing to a collaborator.
     */
    public function appendTrace(string $traceLine): self
    {
        return new self(
            $this->originParam,
            $this->hops,
            [...$this->trace, $traceLine],
            $this->keys,
        );
    }

    public function hasKey(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }
}
