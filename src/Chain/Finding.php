<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * One maximal tramp-data chain: a parameter forwarded, unused, from its origin
 * method to a terminal. `hops` counts the pure-forward nodes only (the terminal
 * is never counted); thresholding against `--limit` is the caller's job.
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
}
