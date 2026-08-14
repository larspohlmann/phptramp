<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * One node of a reported chain: the method it lands in and the source location,
 * plus the line of the forwarding call site it leaves through (null on the
 * terminal node, which forwards nowhere), the node's own parameter name — which
 * the suppression filter matches `#[TrampIgnore]` param entries against — and
 * whether it forwards on via `parent::`.
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
        /** This hop's own parameter name; used for per-hop suppression lookup. */
        public readonly string $param = '',
        /**
         * Whether this node forwards on through a `parent::` call — delegation
         * to the same object's base, not a hand-off to a collaborator, so it
         * scores neither a hop nor a class (frozen rule 4).
         */
        public readonly bool $viaParent = false,
    ) {
    }

    /**
     * A copy of this hop with its diff-aware `changed` mark set — so the
     * diff filter never has to know this constructor's full signature.
     */
    public function withChanged(bool $changed): self
    {
        return new self(
            $this->fqmn,
            $this->class,
            $this->file,
            $this->line,
            $this->forwardLine,
            $changed,
            $this->param,
            $this->viaParent,
        );
    }
}
