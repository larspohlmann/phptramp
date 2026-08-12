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
 * The class mirrors TextReporter's per-finding text logic and layers
 * file-grouping, styling, and a divergent severity model (it reports
 * below-limit findings when no warn tier is configured) on top. That is what
 * pushes the cyclomatic complexity past the codesize threshold. The proper fix
 * — extracting the shared per-finding rendering with TextReporter — is a
 * planned separate refactor and is out of scope for the pretty-format task, so
 * the complexity is suppressed here rather than papered over with a premature
 * extraction that would split the duplicated logic across two files.
 *
 * @SuppressWarnings("ExcessiveClassComplexity")
 */
final class PrettyReporter implements Reporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
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
     * A finding is reportable when it clears the min-classes filter and, when a
     * warn tier is configured, reaches at least the warn threshold. Without a
     * warn tier every finding is reported as an error — the "pretty" format is
     * for eyeballing tramp data, so suppression belongs to the CLI/CI gate, not
     * the renderer.
     *
     * @param list<Finding> $findings
     * @return list<array{0: Finding, 1: Severity}>
     */
    private function reportableFindings(array $findings): array
    {
        $reportable = [];
        foreach ($findings as $finding) {
            $severity = $this->severityOf($finding);
            if ($severity !== null) {
                $reportable[] = [$finding, $severity];
            }
        }

        return $reportable;
    }

    private function severityOf(Finding $finding): ?Severity
    {
        $inherent = $this->thresholds->severityOf($finding);
        if ($inherent !== null) {
            return $inherent;
        }

        // Below every threshold: suppressed when a warn tier exists, reported
        // as an error otherwise — the "pretty" view is for eyeballing tramp
        // data, so without a warn tier nothing is hidden from the reader.
        return $this->thresholds->warnLimit === null ? Severity::Error : null;
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
     * @return list<array{label: string, method: string, location: string, annotation: string}>
     */
    private function hopRows(Finding $finding): array
    {
        $hasTerminalNode = $this->hasKeptTerminal($finding->terminalKind);
        $terminalIndex = count($finding->chain) - 1;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $hasTerminalNode && $index === $terminalIndex;
            $rows[] = [
                'label' => $this->label($index, $isTerminal),
                'method' => $hop->fqmn . '($' . $finding->param . ')',
                'location' => $this->location($hop),
                'annotation' => $this->annotation($hop, $finding->terminalKind, $isTerminal),
            ];
        }

        return $rows;
    }

    /**
     * The kept terminal kinds (used/stored/&-terminated/unused-end) carry the
     * terminal node as the last chain element; external/truncated do not.
     */
    private function hasKeptTerminal(TerminalKind $terminalKind): bool
    {
        return ! in_array($terminalKind, [TerminalKind::Truncated, TerminalKind::External], true);
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
        [$fqmn, $paramName] = $this->splitMethod($row['method']);
        $paramFragment = '($' . $this->styler->paramInMethod($paramName) . ')';
        $paramVisibleWidth = strlen('($' . $paramName . ')');
        $fqmnPadding = max(0, $methodWidth - $paramVisibleWidth - strlen($fqmn));
        $rest = $fqmn . $paramFragment . str_repeat(' ', $fqmnPadding)
            . self::COLUMN_GAP . $this->styler->location($row['location']);
        if ($row['annotation'] !== '') {
            $styledAnnotation = str_starts_with($row['annotation'], '(')
                ? $this->styler->terminalKind($row['annotation'])
                : $this->styler->annotation($row['annotation']);
            $rest .= self::COLUMN_GAP . $styledAnnotation;
        }

        return $this->labelledLine($row['label'], $labelWidth, $rest);
    }

    /** @return array{0: string, 1: string} */
    private function splitMethod(string $method): array
    {
        $paren = strpos($method, '($');
        if ($paren === false) {
            return [$method, ''];
        }

        return [substr($method, 0, $paren), substr($method, $paren + 2, -1)];
    }

    private function labelledLine(string $label, int $labelWidth, string $rest): string
    {
        $paddedLabel = str_pad($label, $labelWidth);

        return self::INDENT . $this->styler->label($paddedLabel) . self::COLUMN_GAP . $rest;
    }

    /**
     * @param list<array{label: string, method: string, location: string, annotation: string}> $rows
     * @param list<string> $notes
     */
    private function labelWidth(array $rows, array $notes): int
    {
        $widths = [];
        foreach ($rows as $row) {
            $widths[] = strlen($row['label']);
        }
        if ($notes !== []) {
            $widths[] = strlen('note');
        }

        return $widths === [] ? 0 : max($widths);
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
     * The error/warning breakdown is shown only when a warn tier is configured
     * and the report actually mixes errors and warnings — a single-tier report
     * (or a pure-error/pure-warning one) reads cleaner as a plain count.
     *
     * @param list<array{0: Finding, 1: Severity}> $reportable
     */
    private function summary(array $reportable): string
    {
        $count = count($reportable);
        $errorCount = count(array_filter($reportable, static fn (array $entry): bool => $entry[1] === Severity::Error));
        $warningCount = $count - $errorCount;
        $showsSplit = $this->thresholds->warnLimit !== null && $errorCount > 0 && $warningCount > 0;

        if ($showsSplit) {
            return $this->styler->summary(
                $count . ' ' . $this->pluralizer->of($count, 'finding') . ' ('
                . $errorCount . ' ' . $this->pluralizer->of($errorCount, 'error') . ', '
                . $warningCount . ' ' . $this->pluralizer->of($warningCount, 'warning') . '; '
                . $this->limitClause() . ').'
            );
        }

        return $this->styler->summary(
            $count . ' ' . $this->pluralizer->of($count, 'finding') . ' (' . $this->limitClause() . ').'
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
