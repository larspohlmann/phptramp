<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;

/**
 * Renders findings as grouped, styled plain text — the "pretty" format. Groups
 * by the first hop's file, sorts findings within a file by the first hop's
 * line, and separates file groups (and the summary) with a 64-char dim divider.
 * Color is the injected Styler's concern; this class never branches on whether
 * color is on.
 *
 * Per-finding text, threshold filtering, terminal detection, and the summary
 * shape mirror TextReporter exactly; this layers file-grouping, styling, and
 * the divider on top.
 */
final class PrettyReporter implements Reporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
    private const MIN_LABEL_WIDTH = 8;
    private const DIVIDER_WIDTH = 64;

    private readonly Pluralizer $pluralizer;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
        private readonly bool $explain,
        private readonly Styler $styler,
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
            return $this->styler->success('No tramp data found (' . $this->limitClause() . ').') . "\n";
        }

        $body = $this->renderFileGroups($this->groupByFile($reportable));

        return $body . "\n\n" . $this->divider() . "\n\n" . $this->summary($reportable) . "\n";
    }

    /**
     * A finding is reportable when it clears the threshold tiers — same filter
     * as TextReporter (delegated to Thresholds::severityOf).
     *
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

    /**
     * @param array<string, list<array{0: Finding, 1: Severity}>> $groups
     */
    private function renderFileGroups(array $groups): string
    {
        $blocks = [];
        foreach ($groups as $file => $findingsInFile) {
            $blocks[] = $this->renderFile($file, $findingsInFile);
        }

        return implode("\n\n" . $this->divider() . "\n\n", $blocks);
    }

    /**
     * Group reportable findings by their first hop's file. File groups are
     * emitted in alphabetical path order so a scrambled input still yields a
     * stable, readable report; within each file, findings are sorted by the
     * first hop's line ascending (stable).
     *
     * @param list<array{0: Finding, 1: Severity}> $reportable
     * @return array<string, list<array{0: Finding, 1: Severity}>>
     */
    private function groupByFile(array $reportable): array
    {
        $groups = [];
        foreach ($reportable as $entry) {
            $groups[$this->firstHopFile($entry[0])][] = $entry;
        }

        ksort($groups, SORT_STRING);
        foreach ($groups as $file => $findingsInFile) {
            usort(
                $findingsInFile,
                fn (array $a, array $b): int => $this->firstHopLine($a[0]) <=> $this->firstHopLine($b[0]),
            );
            $groups[$file] = $findingsInFile;
        }

        return $groups;
    }

    private function firstHopFile(Finding $finding): string
    {
        return $this->paths->relativize($finding->chain[0]->file);
    }

    private function firstHopLine(Finding $finding): int
    {
        return $finding->chain[0]->line;
    }

    /**
     * @param list<array{0: Finding, 1: Severity}> $findingsInFile
     */
    private function renderFile(string $file, array $findingsInFile): string
    {
        $panels = array_map(
            fn (array $entry): string => $this->renderFinding($entry[0], $entry[1]),
            $findingsInFile,
        );

        return $this->styler->fileHeader($file) . "\n\n" . implode("\n\n", $panels);
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
            $lines[] = $this->labelledLine('note', $labelWidth, $this->styler->label($note));
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

        $lines = [self::INDENT . $this->styler->label('explain:')];
        foreach ($finding->trace as $traceLine) {
            $lines[] = self::INDENT . self::INDENT . $this->styler->label($traceLine);
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, fqmn: string, param: string, location: string, annotation: string}>
     */
    private function hopRows(Finding $finding): array
    {
        $hasTerminalNode = count($finding->chain) > $finding->hops;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $hasTerminalNode && $index === $finding->hops;
            $rows[] = [
                'label' => $this->label($index, $isTerminal),
                'fqmn' => $hop->fqmn,
                'param' => $finding->param,
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
        return match (true) {
            $isTerminal => 'terminal',
            $index === 0 => 'origin',
            default => 'hop ' . ($index + 1),
        };
    }

    private function location(Hop $hop): string
    {
        $location = $this->paths->relativize($hop->file) . ':' . $hop->line;
        if ($hop->forwardLine !== null) {
            $location .= '→' . $hop->forwardLine;
        }

        return $location;
    }

    /**
     * @param array{label: string, fqmn: string, param: string, location: string, annotation: string} $row
     */
    private function hopLine(array $row, int $labelWidth, int $methodWidth): string
    {
        $paramFragment = '($' . $this->styler->paramInMethod($row['param']) . ')';
        $paramVisibleWidth = strlen('($' . $row['param'] . ')');
        $fqmnPadding = max(0, $methodWidth - $paramVisibleWidth - strlen($row['fqmn']));
        $rest = $row['fqmn'] . $paramFragment . str_repeat(' ', $fqmnPadding)
            . self::COLUMN_GAP . $this->styler->location($row['location']);
        if ($row['annotation'] !== '') {
            $styledAnnotation = str_starts_with($row['annotation'], '(')
                ? $this->styler->terminalKind($row['annotation'])
                : $this->styler->annotation($row['annotation']);
            $rest .= self::COLUMN_GAP . $styledAnnotation;
        }

        return $this->labelledLine($row['label'], $labelWidth, $rest);
    }

    private function labelledLine(string $label, int $labelWidth, string $rest): string
    {
        $paddedLabel = str_pad($label, $labelWidth);

        return self::INDENT . $this->styler->label($paddedLabel) . self::COLUMN_GAP . $rest;
    }

    /**
     * @param list<array{label: string, fqmn: string, param: string, location: string, annotation: string}> $rows
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
     * @param list<array{label: string, fqmn: string, param: string, location: string, annotation: string}> $rows
     */
    private function methodWidth(array $rows): int
    {
        $widths = [0];
        foreach ($rows as $row) {
            $widths[] = strlen($row['fqmn'] . '($' . $row['param'] . ')');
        }

        return max($widths);
    }

    private function divider(): string
    {
        return $this->styler->divider(str_repeat('-', self::DIVIDER_WIDTH));
    }

    private function header(Finding $finding, Severity $severity): string
    {
        $keyword = $severity === Severity::Warning ? 'WARNING' : 'FINDING';
        $styledKeyword = $this->styler->severity($keyword, $severity);
        $styledParam = $this->styler->param($finding->param);

        return $styledKeyword . '  $' . $styledParam . ': '
            . $finding->hops . ' pass-through ' . $this->pluralizer->of($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->pluralizer->of($finding->classes, 'class', 'classes');
    }

    /**
     * Mirrors TextReporter::summary: when a warn tier is configured, show the
     * error/warning breakdown; otherwise a plain count.
     *
     * @param list<array{0: Finding, 1: Severity}> $reportable
     */
    private function summary(array $reportable): string
    {
        $count = count($reportable);
        if ($this->thresholds->warnLimit === null) {
            return $this->styler->summary(
                $count . ' ' . $this->pluralizer->of($count, 'finding') . ' (' . $this->limitClause() . ').',
            );
        }

        $errorCount = count(array_filter($reportable, static fn (array $entry): bool => $entry[1] === Severity::Error));
        $warningCount = $count - $errorCount;

        return $this->styler->summary(
            $count . ' ' . $this->pluralizer->of($count, 'finding') . ' ('
            . $errorCount . ' ' . $this->pluralizer->of($errorCount, 'error') . ', '
            . $warningCount . ' ' . $this->pluralizer->of($warningCount, 'warning') . '; '
            . $this->limitClause() . ').',
        );
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
