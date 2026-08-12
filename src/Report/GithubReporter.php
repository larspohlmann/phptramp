<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Renders findings as GitHub Actions workflow commands: one `::error` or
 * `::warning` annotation per finding, plus one `::notice` per remaining
 * non-terminal hop (the terminal node, when present, is never annotated). The
 * `::error`/`::warning` annotation anchors at the first hop marked `changed`
 * (diff-aware mode) so the annotation lands on the line the diff touched; when
 * no hop is changed — every normal run, and defensively if the filter ever
 * left a finding with nothing marked — it falls back to the origin, which is
 * today's unconditional behavior. See
 * https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions
 * for the property-value escaping this reporter applies to every `file=` and
 * `title=` value.
 */
final class GithubReporter implements Reporter
{
    private readonly FindingMessage $message;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
    ) {
        $this->message = new FindingMessage();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $lines = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity === null) {
                continue;
            }
            foreach ($this->annotationLines($finding, $severity) as $line) {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function annotationLines(Finding $finding, Severity $severity): array
    {
        $nonTerminalHops = $this->nonTerminalHops($finding);
        $anchorIndex = $this->anchorIndex($nonTerminalHops);

        $lines = [$this->anchorAnnotation($finding, $severity, $anchorIndex)];
        unset($nonTerminalHops[$anchorIndex]);
        foreach ($nonTerminalHops as $index => $hop) {
            $lines[] = $this->hopNotice($finding, $hop, $index);
        }

        return $lines;
    }

    private function anchorAnnotation(Finding $finding, Severity $severity, int $anchorIndex): string
    {
        $anchor = $finding->chain[$anchorIndex];
        $title = 'phptramp::' . $this->message->describe($finding) . $this->anchorSuffix($anchorIndex);

        return '::' . $severity->label()
            . ' file=' . $this->escapeProperty($this->paths->relativize($anchor->file))
            . ',line=' . ($anchor->forwardLine ?? $anchor->line)
            . ',title=' . $this->escapeProperty($title);
    }

    private function anchorSuffix(int $anchorIndex): string
    {
        if ($anchorIndex === 0) {
            return '';
        }

        return ' (hop ' . ($anchorIndex + 1) . ' of the chain, changed by this diff)';
    }

    private function hopNotice(Finding $finding, Hop $hop, int $index): string
    {
        $title = 'phptramp::hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin;

        return '::notice file=' . $this->escapeProperty($this->paths->relativize($hop->file))
            . ',line=' . ($hop->forwardLine ?? $hop->line)
            . ',title=' . $this->escapeProperty($title);
    }

    /**
     * The chain index of the first hop marked `changed`, restricted to the
     * non-terminal hops (0 .. hops-1). Falls back to the origin (index 0) when
     * none is marked, which is the only case a normal (non diff-aware) run
     * ever produces.
     *
     * @param array<int, Hop> $nonTerminalHops
     */
    private function anchorIndex(array $nonTerminalHops): int
    {
        foreach ($nonTerminalHops as $index => $hop) {
            if ($hop->changed) {
                return $index;
            }
        }

        return 0;
    }

    /**
     * @return array<int, Hop> non-terminal-hop entries keyed by their chain
     *                         index (0 .. hops-1) — the terminal node, at
     *                         index hops, is never included
     */
    private function nonTerminalHops(Finding $finding): array
    {
        return array_slice($finding->chain, 0, $finding->hops, true);
    }

    /**
     * Escapes a workflow-command PROPERTY value (a `file=`/`title=` component),
     * per `@actions/core`'s `escapeProperty`: the same %/\r/\n data-escaping as
     * a command's message body, plus `:` and `,` — the two characters that
     * would otherwise be misread as property separators. `strtr` replaces every
     * character in a single left-to-right pass, so the `%NN` sequences it
     * introduces are never themselves re-escaped.
     */
    private function escapeProperty(string $value): string
    {
        return strtr($value, [
            '%' => '%25',
            ':' => '%3A',
            ',' => '%2C',
            "\r" => '%0D',
            "\n" => '%0A',
        ]);
    }
}
