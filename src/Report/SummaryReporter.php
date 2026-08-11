<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;

/**
 * Whole-codebase overview: renders every finding regardless of thresholds, so
 * a legacy codebase can be triaged before a `--limit` is chosen. Four blocks —
 * a histogram of chain lengths, the ten longest chains, the most-forwarded
 * parameters, and a one-line totals footer — separated by a blank line.
 */
final class SummaryReporter implements Reporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
    private const TOP_CHAINS_LIMIT = 10;
    private const MAX_BAR_LENGTH = 40;

    private readonly FindingMessage $message;

    public function __construct(private readonly Thresholds $thresholds)
    {
        $this->message = new FindingMessage();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        if ($findings === []) {
            return "No chains found.\n";
        }

        $blocks = [
            $this->chainsByLengthBlock($findings),
            $this->topLongestChainsBlock($findings),
            $this->mostForwardedParametersBlock($findings),
            $this->footer($findings),
        ];

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * @param list<Finding> $findings
     */
    private function chainsByLengthBlock(array $findings): string
    {
        $counts = $this->countsByHopLength($findings);
        $labels = $this->hopLengthLabels(array_keys($counts));
        $labelWidth = $this->maxLength($labels);
        $maxCount = $this->nonEmptyMax($counts);

        $rows = [];
        foreach ($counts as $hops => $count) {
            $rows[] = self::INDENT . str_pad($labels[$hops], $labelWidth) . self::COLUMN_GAP
                . str_repeat('#', $this->barLength($count, $maxCount)) . ' ' . $count;
        }

        return "Chains by length:\n" . implode("\n", $rows);
    }

    /**
     * @param list<Finding> $findings
     * @return array<int, int> hop count => number of findings, ascending by hop count
     */
    private function countsByHopLength(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding->hops] = ($counts[$finding->hops] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param list<int> $hopCounts
     * @return array<int, string> hop count => rendered label, e.g. "1 hop"/"2 hops"
     */
    private function hopLengthLabels(array $hopCounts): array
    {
        $labels = [];
        foreach ($hopCounts as $hops) {
            $labels[$hops] = $hops . ' ' . $this->plural($hops, 'hop');
        }

        return $labels;
    }

    private function barLength(int $count, int $maxCount): int
    {
        if ($maxCount <= self::MAX_BAR_LENGTH) {
            return $count;
        }

        return max(1, (int) round($count * self::MAX_BAR_LENGTH / $maxCount));
    }

    /**
     * @param list<Finding> $findings
     */
    private function topLongestChainsBlock(array $findings): string
    {
        $top = $this->longestFindings($findings);
        $hopLabels = array_map(
            fn (Finding $finding): string => $finding->hops . ' ' . $this->plural($finding->hops, 'hop'),
            $top,
        );
        $params = array_map(static fn (Finding $finding): string => '$' . $finding->param, $top);

        $hopLabelWidth = $this->maxLength($hopLabels);
        $paramWidth = $this->maxLength($params);

        $rows = [];
        foreach ($top as $index => $finding) {
            $rows[] = self::INDENT . str_pad($hopLabels[$index], $hopLabelWidth) . self::COLUMN_GAP
                . str_pad($params[$index], $paramWidth) . self::COLUMN_GAP
                . $finding->origin . ' -> ' . $this->message->terminalClause($finding);
        }

        return "Top 10 longest chains:\n" . implode("\n", $rows);
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding> sorted by hops desc, then origin asc, then param asc; capped at 10
     */
    private function longestFindings(array $findings): array
    {
        $sorted = $findings;
        usort($sorted, static function (Finding $left, Finding $right): int {
            return [-$left->hops, $left->origin, $left->param]
                <=> [-$right->hops, $right->origin, $right->param];
        });

        return array_slice($sorted, 0, self::TOP_CHAINS_LIMIT);
    }

    /**
     * @param list<Finding> $findings
     */
    private function mostForwardedParametersBlock(array $findings): string
    {
        $rows = [];
        foreach ($this->paramCounts($findings) as $paramCount) {
            $rows[] = self::INDENT . $paramCount['count'] . 'x' . self::COLUMN_GAP . '$' . $paramCount['param'];
        }

        return "Most-forwarded parameters:\n" . implode("\n", $rows);
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{param: string, count: int}> sorted by count desc, then param asc
     */
    private function paramCounts(array $findings): array
    {
        $counts = [];
        foreach ($findings as $finding) {
            $counts[$finding->param] = ($counts[$finding->param] ?? 0) + 1;
        }

        $pairs = [];
        foreach ($counts as $param => $count) {
            $pairs[] = ['param' => $param, 'count' => $count];
        }

        usort($pairs, static function (array $left, array $right): int {
            return [-$left['count'], $left['param']] <=> [-$right['count'], $right['param']];
        });

        return $pairs;
    }

    /**
     * @param list<Finding> $findings
     */
    private function footer(array $findings): string
    {
        $total = count($findings);
        $atOrOverLimit = count(
            array_filter($findings, fn (Finding $finding): bool => $finding->hops >= $this->thresholds->limit),
        );

        return $total . ' ' . $this->plural($total, 'chain') . ' total; '
            . $atOrOverLimit . ' at or over the limit (limit: '
            . $this->thresholds->limit . ' ' . $this->plural($this->thresholds->limit, 'hop') . ').';
    }

    /**
     * @param array<array-key, string> $strings key order and identity are
     *                                          irrelevant — only lengths matter
     */
    private function maxLength(array $strings): int
    {
        return $this->nonEmptyMax(array_map('strlen', $strings));
    }

    /**
     * @param array<array-key, int> $values key order and identity are
     *                                      irrelevant — only the max value matters
     */
    private function nonEmptyMax(array $values): int
    {
        // render() already guards against an empty $findings, so every caller
        // reaches this with at least one value; the check just proves that to
        // PHPStan without a magic fallback number.
        if ($values === []) {
            throw new \LogicException('nonEmptyMax() called with an empty array.');
        }

        return max($values);
    }

    private function plural(int $count, string $singular): string
    {
        return $count === 1 ? $singular : $singular . 's';
    }
}
