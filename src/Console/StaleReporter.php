<?php

declare(strict_types=1);

namespace PhpTramp\Console;

use PhpTramp\Baseline\Baseline;
use PhpTramp\Chain\Finding;
use PhpTramp\Ignore\SuppressionIndex;

/**
 * Collects the stale-baseline and stale-suppression lines for a full run.
 * Extracted from {@see Application} so the parser/runner stays under the
 * PHPMD complexity ceiling — the same reason {@see BaselineFilter} is a
 * separate class.
 *
 * Stale detection is skipped entirely under --changed-only: the diff filter
 * removes most chains before matching, so "stale" would be almost everything,
 * almost always wrong. Full runs only.
 */
final class StaleReporter
{
    public function __construct(
        private readonly ?Baseline $baseline,
        private readonly SuppressionIndex $suppressions,
    ) {
    }

    /**
     * The findings passed in are post-suppression, pre-baseline-exclude —
     * exactly what reached the baseline filter, so {@see Baseline::staleEntries()}
     * sees the live fingerprints.
     *
     * @param list<Finding> $preBaselineFindings
     * @param list<string> $firedKeys suppression keys that dropped >= 1 chain
     * @return list<string> stderr lines, without the trailing newline
     */
    public function lines(bool $changedOnly, array $preBaselineFindings, array $firedKeys): array
    {
        if ($changedOnly) {
            return [];
        }

        $lines = [];
        if ($this->baseline !== null) {
            foreach ($this->baseline->staleEntries($preBaselineFindings) as $entry) {
                $lines[] = 'phptramp: stale baseline entry: ' . $entry;
            }
        }

        $staleSuppressions = array_values(array_diff($this->suppressions->keys(), $firedKeys));
        foreach ($staleSuppressions as $key) {
            $lines[] = 'phptramp: stale suppression: ' . $key;
        }

        return $lines;
    }

    /**
     * The base exit (0 or 1 from the findings) is unchanged by stale entries
     * unless --fail-on-stale is set and any stale line was produced: then a 0
     * becomes 1. An actual error result stays 1; a tool error (2) returns
     * earlier and never reaches here.
     *
     * @param list<string> $staleLines
     */
    public function exitCode(bool $hasError, bool $failOnStale, array $staleLines): int
    {
        $base = $hasError ? 1 : 0;
        if ($base === 0 && $failOnStale && $staleLines !== []) {
            return 1;
        }

        return $base;
    }
}
