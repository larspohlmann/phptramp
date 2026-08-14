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
        $anyChanged = false;
        $markedChain = [];
        foreach ($finding->chain as $hop) {
            $matched = $this->hopMatches($hop);
            $anyChanged = $anyChanged || $matched;
            $markedChain[] = $hop->withChanged($matched);
        }

        if (!$anyChanged) {
            return null;
        }

        return $finding->withChain($markedChain);
    }

    private function hopMatches(Hop $hop): bool
    {
        if ($hop->forwardLine === null) {
            return false;
        }

        return $this->changedLines->containsLine($hop->file, $hop->line)
            || $this->changedLines->containsLine($hop->file, $hop->forwardLine);
    }
}
