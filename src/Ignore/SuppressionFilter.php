<?php

declare(strict_types=1);

namespace PhpTramp\Ignore;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Drops findings whose chain hits a configured suppression — the same decision
 * `ChainTraversal` used to make silently while building chains. Moved out here
 * so the decision is observable: the returned {@see SuppressionOutcome}
 * records every suppression key that fired, which Task 6 diffs against
 * {@see SuppressionIndex::keys()} to report stale ignores.
 *
 * Stateless per call: the constructor holds only the suppression index, and
 * {@see filter()} touches no member state.
 */
final class SuppressionFilter
{
    public function __construct(private readonly SuppressionIndex $suppressions)
    {
    }

    /**
     * @param list<Finding> $findings
     */
    public function filter(array $findings): SuppressionOutcome
    {
        $kept = [];
        $seen = [];
        $firedKeys = [];

        foreach ($findings as $finding) {
            $matchedKeys = $this->matchedKeysFor($finding);
            if ($matchedKeys === []) {
                $kept[] = $finding;

                continue;
            }

            foreach ($matchedKeys as $key) {
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $firedKeys[] = $key;
            }
        }

        return new SuppressionOutcome($kept, $firedKeys);
    }

    /**
     * The keys matched by any hop of this finding, in chain order and within a
     * hop in the frozen check order (method, param, declaration line, line
     * above, forward line). Duplicates across hops are left in: {@see filter()}
     * dedups every key against a run-global set before recording it, so a
     * second dedup here would be dead work.
     *
     * The terminal hop is skipped: `forwardLine === null` identifies it (the
     * terminal forwards nowhere), matching the old `ChainTraversal` guard and
     * the `ChangedChainFilter` precedent. Terminals are not hops and never
     * suppress.
     *
     * @return list<string>
     */
    private function matchedKeysFor(Finding $finding): array
    {
        $matched = [];

        foreach ($finding->chain as $hop) {
            if ($hop->forwardLine === null) {
                continue;
            }
            foreach ($this->matchedKeysForHop($hop) as $key) {
                $matched[] = $key;
            }
        }

        return $matched;
    }

    /**
     * The keys this single hop triggers, in the frozen check order preserved
     * from the old `ChainTraversal::isSuppressedHop`.
     *
     * @return list<string>
     */
    private function matchedKeysForHop(Hop $hop): array
    {
        $matched = [];

        if ($this->suppressions->suppressesMethod($hop->fqmn)) {
            $matched[] = SuppressionIndex::methodKey($hop->fqmn);
        }

        if ($this->suppressions->suppressesParam($hop->fqmn, $hop->param)) {
            $matched[] = SuppressionIndex::paramKey($hop->fqmn, $hop->param);
        }

        if ($this->suppressions->suppressesLine($hop->file, $hop->line)) {
            $matched[] = SuppressionIndex::lineKey($hop->file, $hop->line);
        }

        if ($this->suppressions->suppressesLine($hop->file, $hop->line - 1)) {
            $matched[] = SuppressionIndex::lineKey($hop->file, $hop->line - 1);
        }

        if ($hop->forwardLine !== null && $this->suppressions->suppressesLine($hop->file, $hop->forwardLine)) {
            $matched[] = SuppressionIndex::lineKey($hop->file, $hop->forwardLine);
        }

        return $matched;
    }
}
