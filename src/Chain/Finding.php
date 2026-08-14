<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * One maximal tramp-data chain: a parameter forwarded, unused, from its origin
 * method to a terminal. `hops` is the score: pure-forward nodes that are not
 * `parent::` delegators (the terminal is never counted); thresholding against
 * `--limit` is the caller's job.
 */
final class Finding
{
    /**
     * @param list<Hop> $chain hops in order; includes the terminal node only when
     *                         terminalKind is used|stored|&-terminated|unused-end
     * @param list<string> $notes human-readable, e.g. "truncated: 2 implementations"
     * @param list<string> $trace per-edge resolution trace, rendered only by --explain
     */
    public function __construct(
        public readonly string $param,
        public readonly string $origin,
        public readonly ?string $terminal,
        public readonly TerminalKind $terminalKind,
        public readonly int $hops,
        public readonly array $chain,
        public readonly int $classes,
        public readonly array $notes,
        public readonly array $trace,
    ) {
    }

    /**
     * A copy of this finding carrying a rebuilt chain — so callers that only
     * re-mark hops (the diff filter) need not know this constructor's full
     * signature.
     *
     * @param list<Hop> $chain
     */
    public function withChain(array $chain): self
    {
        return new self(
            $this->param,
            $this->origin,
            $this->terminal,
            $this->terminalKind,
            $this->hops,
            $chain,
            $this->classes,
            $this->notes,
            $this->trace,
        );
    }

    /** The terminal node, when the chain has one, is always the last entry. */
    public function hasTerminalNode(): bool
    {
        return $this->terminalKind->keepsTerminalNode();
    }

    /**
     * The chain minus its terminal node — every node that forwards the value on.
     *
     * @return list<Hop>
     */
    public function forwardingHops(): array
    {
        return $this->hasTerminalNode() ? array_slice($this->chain, 0, -1) : $this->chain;
    }
}
