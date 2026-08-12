<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Renders findings as a single JSON document: `{limit, warnLimit, findings}`.
 * Consumed by tooling (CI annotations, dashboards), so the shape is pinned —
 * see docs/plan.md — and covered byte-for-byte by JsonReporterTest.
 */
final class JsonReporter implements Reporter
{
    private readonly JsonEncoder $encoder;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
        private readonly bool $changedOnly = false,
    ) {
        $this->encoder = new JsonEncoder();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $document = [
            'limit' => $this->thresholds->limit,
            'warnLimit' => $this->thresholds->warnLimit,
            'minClasses' => $this->thresholds->minClasses,
            'findings' => $this->findingDocuments($findings),
        ];

        return $this->encoder->encode($document, 'JSON') . "\n";
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
            'severity' => $severity->label(),
            'origin' => $finding->origin,
            'terminal' => $finding->terminal,
            'terminalKind' => $finding->terminalKind->value,
            'hops' => $finding->hops,
            'classes' => $finding->classes,
            'chain' => $this->chainDocument($finding),
            'notes' => $finding->notes,
        ];
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
            $chain[] = $this->hopDocument($hop, $index, $isTerminal);
        }

        return $chain;
    }

    /**
     * @return array<string, mixed>
     */
    private function hopDocument(Hop $hop, int $index, bool $isTerminal): array
    {
        $document = [
            'method' => $hop->fqmn,
            'role' => $this->role($index, $isTerminal),
            'file' => $this->paths->relativize($hop->file),
            'line' => $hop->line,
            'forwardLine' => $hop->forwardLine,
        ];
        if ($this->changedOnly) {
            $document['changed'] = $hop->changed;
        }

        return $document;
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
}
