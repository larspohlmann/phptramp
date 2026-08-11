<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;

/**
 * Renders findings as a single JSON document: `{limit, warnLimit, findings}`.
 * Consumed by tooling (CI annotations, dashboards), so the shape is pinned —
 * see docs/plan.md — and covered byte-for-byte by JsonReporterTest.
 */
final class JsonReporter implements Reporter
{
    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
    ) {
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $document = [
            'limit' => $this->thresholds->limit,
            'warnLimit' => $this->thresholds->warnLimit,
            'findings' => $this->findingDocuments($findings),
        ];

        return $this->encode($document) . "\n";
    }

    /**
     * @param list<Finding> $findings
     * @return list<array<string, mixed>>
     */
    private function findingDocuments(array $findings): array
    {
        $documents = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity === null) {
                continue;
            }
            $documents[] = $this->findingDocument($finding, $severity);
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function findingDocument(Finding $finding, Severity $severity): array
    {
        return [
            'param' => $finding->param,
            'severity' => $this->severityLabel($severity),
            'origin' => $finding->origin,
            'terminal' => $finding->terminal,
            'terminalKind' => $finding->terminalKind->value,
            'hops' => $finding->hops,
            'classes' => $finding->classes,
            'chain' => $this->chainDocument($finding),
            'notes' => $finding->notes,
        ];
    }

    private function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chainDocument(Finding $finding): array
    {
        $hasTerminalNode = count($finding->chain) > $finding->hops;

        $chain = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $hasTerminalNode && $index === $finding->hops;
            $chain[] = [
                'method' => $hop->fqmn,
                'role' => $this->role($index, $isTerminal),
                'file' => $this->paths->relativize($hop->file),
                'line' => $hop->line,
                'forwardLine' => $hop->forwardLine,
            ];
        }

        return $chain;
    }

    private function role(int $index, bool $isTerminal): string
    {
        if ($isTerminal) {
            return 'terminal';
        }
        if ($index === 0) {
            return 'origin';
        }

        return 'hop';
    }

    /**
     * @param array<string, mixed> $document
     */
    private function encode(array $document): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Unreachable: $document is built entirely from scalars, strings, and
            // arrays thereof — nothing here can produce a resource or invalid
            // UTF-8 that would make json_encode fail.
            throw new JsonEncodingException('Failed to encode findings as JSON.');
        }

        return $json;
    }
}
