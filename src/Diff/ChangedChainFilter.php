<?php

declare(strict_types=1);

namespace PhpTramp\Diff;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Diff-aware post-processor: keeps only findings that touch the diff, and
 * marks which of their hops do.
 */
final class ChangedChainFilter
{
    public function __construct(private readonly ChangedLines $changedLines)
    {
    }

    /**
     * Frozen rule 9. Keeps findings with >= 1 changed hop, rebuilt so each kept
     * finding's hops carry `changed`. A hop matches iff its declaration `line`
     * or its `forwardLine` is in the diff for its file. The terminal node (the
     * chain entry with forwardLine === null) never matches and is never marked.
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings): array
    {
        $kept = [];

        foreach ($findings as $finding) {
            $rebuilt = $this->rebuildIfChanged($finding);
            if ($rebuilt !== null) {
                $kept[] = $rebuilt;
            }
        }

        return $kept;
    }

    private function rebuildIfChanged(Finding $finding): ?Finding
    {
        $markedChain = $this->markChangedHops($finding->chain);
        if (!$this->hasChangedHop($markedChain)) {
            return null;
        }

        return new Finding(
            $finding->param,
            $finding->origin,
            $finding->terminal,
            $finding->terminalKind,
            $finding->hops,
            $markedChain,
            $finding->classes,
            $finding->notes,
            $finding->trace,
        );
    }

    /**
     * @param list<Hop> $chain
     * @return list<Hop>
     */
    private function markChangedHops(array $chain): array
    {
        return array_map(fn (Hop $hop): Hop => $this->markHop($hop), $chain);
    }

    private function markHop(Hop $hop): Hop
    {
        return new Hop($hop->fqmn, $hop->class, $hop->file, $hop->line, $hop->forwardLine, $this->hopMatches($hop));
    }

    private function hopMatches(Hop $hop): bool
    {
        if ($hop->forwardLine === null) {
            return false;
        }

        return $this->changedLines->containsLine($hop->file, $hop->line)
            || $this->changedLines->containsLine($hop->file, $hop->forwardLine);
    }

    /** @param list<Hop> $chain */
    private function hasChangedHop(array $chain): bool
    {
        foreach ($chain as $hop) {
            if ($hop->changed) {
                return true;
            }
        }

        return false;
    }
}
