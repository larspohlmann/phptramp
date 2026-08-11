<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * How one walked path ends: the terminal node (present only for real uses), the
 * terminal FQMN, the kind, and any notes to attach. Bundles what the finding
 * assembler needs so it takes a path and a terminal, nothing wider.
 */
final class Terminal
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public readonly ?Hop $hop,
        public readonly ?string $fqmn,
        public readonly TerminalKind $kind,
        public readonly array $notes = [],
    ) {
    }
}
