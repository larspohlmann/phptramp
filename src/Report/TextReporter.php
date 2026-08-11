<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Renders findings as aligned plain text. Columns are two-space separated; the
 * label column is at least as wide as the widest fixed label ("terminal") and
 * the method column is padded to the longest method entry in each finding.
 */
final class TextReporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
    private const MIN_LABEL_WIDTH = 8;

    public function __construct(private readonly int $limit)
    {
    }

    /**
     * @param list<Finding> $findings already filtered to hops >= limit
     */
    public function render(array $findings): string
    {
        if ($findings === []) {
            return 'No tramp data found (' . $this->limitClause() . ").\n";
        }

        $blocks = array_map($this->renderFinding(...), $findings);

        return implode("\n\n", $blocks) . "\n\n" . $this->summary(count($findings)) . "\n";
    }

    private function renderFinding(Finding $finding): string
    {
        $hopRows = $this->hopRows($finding);
        $labelWidth = $this->labelWidth($hopRows, $finding->notes);
        $methodWidth = $this->methodWidth($hopRows);

        $lines = [$this->header($finding)];
        foreach ($hopRows as $row) {
            $lines[] = $this->hopLine($row, $labelWidth, $methodWidth);
        }
        foreach ($finding->notes as $note) {
            $lines[] = self::INDENT . str_pad('note', $labelWidth) . self::COLUMN_GAP . $note;
        }

        return implode("\n", $lines);
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
                'label' => $this->label($index, $finding->hops, $isTerminal),
                'method' => $hop->fqmn . '($' . $finding->param . ')',
                'location' => $this->location($hop),
                'annotation' => $isTerminal ? '(' . $finding->terminalKind->value . ')' : '',
            ];
        }

        return $rows;
    }

    private function label(int $index, int $hops, bool $isTerminal): string
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
        return $hop->file . ':' . $hop->line;
    }

    /**
     * @param array{label: string, method: string, location: string, annotation: string} $row
     */
    private function hopLine(array $row, int $labelWidth, int $methodWidth): string
    {
        $line = self::INDENT
            . str_pad($row['label'], $labelWidth)
            . self::COLUMN_GAP
            . str_pad($row['method'], $methodWidth)
            . self::COLUMN_GAP
            . $row['location'];

        return $row['annotation'] === '' ? $line : $line . self::COLUMN_GAP . $row['annotation'];
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

    private function header(Finding $finding): string
    {
        return 'FINDING  $' . $finding->param . ': '
            . $finding->hops . ' pass-through ' . $this->plural($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->plural($finding->classes, 'class', 'classes');
    }

    private function summary(int $count): string
    {
        return $count . ' ' . $this->plural($count, 'finding') . ' (' . $this->limitClause() . ').';
    }

    private function limitClause(): string
    {
        return 'limit: ' . $this->limit . ' ' . $this->plural($this->limit, 'hop');
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 1) {
            return $singular;
        }

        return $plural ?? $singular . 's';
    }
}
