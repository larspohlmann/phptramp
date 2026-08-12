<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;

/**
 * Renders findings as aligned plain text. Columns are two-space separated; the
 * label column is at least as wide as the widest fixed label ("terminal") and
 * the method column is padded to the longest method entry in each finding.
 */
final class TextReporter implements Reporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
    private const MIN_LABEL_WIDTH = 8;

    private readonly Pluralizer $pluralizer;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
        private readonly bool $explain = false,
    ) {
        $this->pluralizer = new Pluralizer();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $reportable = $this->reportableFindings($findings);
        if ($reportable === []) {
            return 'No tramp data found (' . $this->limitClause() . ").\n";
        }

        $blocks = array_map(
            fn (array $reportableFinding): string => $this->renderFinding(...$reportableFinding),
            $reportable,
        );

        return implode("\n\n", $blocks) . "\n\n" . $this->summary($reportable) . "\n";
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{0: Finding, 1: Severity}>
     */
    private function reportableFindings(array $findings): array
    {
        $reportable = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity !== null) {
                $reportable[] = [$finding, $severity];
            }
        }

        return $reportable;
    }

    private function renderFinding(Finding $finding, Severity $severity): string
    {
        $hopRows = $this->hopRows($finding);
        $labelWidth = $this->labelWidth($hopRows, $finding->notes);
        $methodWidth = $this->methodWidth($hopRows);

        $lines = [$this->header($finding, $severity)];
        foreach ($hopRows as $row) {
            $lines[] = $this->hopLine($row, $labelWidth, $methodWidth);
        }
        foreach ($finding->notes as $note) {
            $lines[] = $this->labelledLine('note', $labelWidth, $note);
        }
        foreach ($this->explainLines($finding) as $explainLine) {
            $lines[] = $explainLine;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function explainLines(Finding $finding): array
    {
        if (! $this->explain || $finding->trace === []) {
            return [];
        }

        $lines = [self::INDENT . 'explain:'];
        foreach ($finding->trace as $traceLine) {
            $lines[] = self::INDENT . self::INDENT . $traceLine;
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, method: string, location: string, annotation: string}>
     */
    private function hopRows(Finding $finding): array
    {
        $hasTerminalNode = count($finding->chain) > $finding->hops;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $hasTerminalNode && $index === $finding->hops;
            $rows[] = [
                'label' => $this->label($index, $isTerminal),
                'method' => $hop->fqmn . '($' . $finding->param . ')',
                'location' => $this->location($hop),
                'annotation' => $this->annotation($hop, $finding->terminalKind, $isTerminal),
            ];
        }

        return $rows;
    }

    private function annotation(Hop $hop, TerminalKind $terminalKind, bool $isTerminal): string
    {
        if ($isTerminal) {
            return '(' . $terminalKind->value . ')';
        }
        if ($hop->changed) {
            return '*YOURS*';
        }

        return '';
    }

    private function label(int $index, bool $isTerminal): string
    {
        if ($isTerminal) {
            return 'terminal';
        }
        if ($index === 0) {
            return 'origin';
        }

        return 'hop ' . ($index + 1);
    }

    private function location(Hop $hop): string
    {
        return $this->paths->relativize($hop->file) . ':' . $hop->line;
    }

    /**
     * @param array{label: string, method: string, location: string, annotation: string} $row
     */
    private function hopLine(array $row, int $labelWidth, int $methodWidth): string
    {
        $rest = str_pad($row['method'], $methodWidth) . self::COLUMN_GAP . $row['location'];
        if ($row['annotation'] !== '') {
            $rest .= self::COLUMN_GAP . $row['annotation'];
        }

        return $this->labelledLine($row['label'], $labelWidth, $rest);
    }

    private function labelledLine(string $label, int $labelWidth, string $rest): string
    {
        return self::INDENT . str_pad($label, $labelWidth) . self::COLUMN_GAP . $rest;
    }

    /**
     * @param list<array{label: string, method: string, location: string, annotation: string}> $rows
     * @param list<string> $notes
     */
    private function labelWidth(array $rows, array $notes): int
    {
        $widths = [self::MIN_LABEL_WIDTH];
        foreach ($rows as $row) {
            $widths[] = strlen($row['label']);
        }
        if ($notes !== []) {
            $widths[] = strlen('note');
        }

        return max($widths);
    }

    /**
     * @param list<array{label: string, method: string, location: string, annotation: string}> $rows
     */
    private function methodWidth(array $rows): int
    {
        $widths = [0];
        foreach ($rows as $row) {
            $widths[] = strlen($row['method']);
        }

        return max($widths);
    }

    private function header(Finding $finding, Severity $severity): string
    {
        $keyword = $severity === Severity::Warning ? 'WARNING' : 'FINDING';

        return $keyword . '  $' . $finding->param . ': '
            . $finding->hops . ' pass-through ' . $this->pluralizer->of($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->pluralizer->of($finding->classes, 'class', 'classes');
    }

    /**
     * @param list<array{0: Finding, 1: Severity}> $reportable
     */
    private function summary(array $reportable): string
    {
        $count = count($reportable);
        if ($this->thresholds->warnLimit === null) {
            return $count . ' ' . $this->pluralizer->of($count, 'finding') . ' (' . $this->limitClause() . ').';
        }

        $errorCount = count(array_filter($reportable, static fn (array $entry): bool => $entry[1] === Severity::Error));
        $warningCount = $count - $errorCount;

        return $count . ' ' . $this->pluralizer->of($count, 'finding') . ' ('
            . $errorCount . ' ' . $this->pluralizer->of($errorCount, 'error') . ', '
            . $warningCount . ' ' . $this->pluralizer->of($warningCount, 'warning') . '; '
            . $this->limitClause() . ').';
    }

    private function limitClause(): string
    {
        $clause = 'limit: ' . $this->thresholds->limit . ' ' . $this->pluralizer->of($this->thresholds->limit, 'hop');
        if ($this->thresholds->warnLimit !== null) {
            $clause .= ', warn-limit: ' . $this->thresholds->warnLimit
                . ' ' . $this->pluralizer->of($this->thresholds->warnLimit, 'hop');
        }
        if ($this->thresholds->minClasses > 0) {
            $clause .= ', min-classes: ' . $this->thresholds->minClasses
                . ' ' . $this->pluralizer->of($this->thresholds->minClasses, 'class', 'classes');
        }

        return $clause;
    }
}
