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
 * for the message-data escaping this reporter applies.
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

        return '::' . $this->command($severity)
            . ' file=' . $this->escape($this->paths->relativize($origin->file))
            . ',line=' . $origin->line
            . ',title=' . $this->escape($title);
    }

    private function hopNotice(Finding $finding, Hop $hop, int $index): string
    {
        $title = 'phptramp::hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin;

        return '::notice file=' . $this->escape($this->paths->relativize($hop->file))
            . ',line=' . $hop->line
            . ',title=' . $this->escape($title);
    }

    /**
     * @return array<int, Hop> subsequent-hop entries keyed by their chain index
     *                         (1 .. hops-1) — the terminal node, at index
     *                         hops, is never included
     */
    private function subsequentHops(Finding $finding): array
    {
        $hops = [];
        for ($index = 1; $index < $finding->hops; $index++) {
            $hops[$index] = $finding->chain[$index];
        }

        return $hops;
    }

    private function command(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }

    private function escape(string $value): string
    {
        $value = str_replace('%', '%25', $value);
        $value = str_replace("\r", '%0D', $value);

        return str_replace("\n", '%0A', $value);
    }
}
