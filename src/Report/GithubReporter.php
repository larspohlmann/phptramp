<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Renders findings as GitHub Actions workflow commands: one `::error` or
 * `::warning` annotation at the origin per finding, plus one `::notice` per
 * subsequent hop (the terminal node, when present, is not annotated). See
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
        $lines = [$this->originAnnotation($finding, $severity)];
        foreach ($this->subsequentHops($finding) as $index => $hop) {
            $lines[] = $this->hopNotice($finding, $hop, $index);
        }

        return $lines;
    }

    private function originAnnotation(Finding $finding, Severity $severity): string
    {
        $origin = $finding->chain[0];
        $title = 'phptramp::' . $this->message->describe($finding);

        return '::' . $severity->label()
            . ' file=' . $this->escapeProperty($this->paths->relativize($origin->file))
            . ',line=' . $origin->line
            . ',title=' . $this->escapeProperty($title);
    }

    private function hopNotice(Finding $finding, Hop $hop, int $index): string
    {
        $title = 'phptramp::hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin;

        return '::notice file=' . $this->escapeProperty($this->paths->relativize($hop->file))
            . ',line=' . $hop->line
            . ',title=' . $this->escapeProperty($title);
    }

    /**
     * @return array<int, Hop> subsequent-hop entries keyed by their chain index
     *                         (1 .. hops-1) — the terminal node, at index
     *                         hops, is never included
     */
    private function subsequentHops(Finding $finding): array
    {
        return array_slice($finding->chain, 1, $finding->hops - 1, true);
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
