<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;

/**
 * The one-line human-readable description of a finding, shared verbatim by
 * every machine reporter (GitHub annotation title, Checkstyle message, SARIF
 * message.text) so the wording never drifts between them.
 */
final class FindingMessage
{
    public function describe(Finding $finding): string
    {
        return '$' . $finding->param . ': ' . $finding->hops . ' pass-through ' . $this->plural($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->plural($finding->classes, 'class', 'classes')
            . ' (terminal: ' . $this->terminalClause($finding) . ')';
    }

    private function terminalClause(Finding $finding): string
    {
        if ($finding->terminal === null) {
            return $finding->terminalKind->value;
        }

        return $finding->terminal . ' [' . $finding->terminalKind->value . ']';
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 1) {
            return $singular;
        }

        return $plural ?? $singular . 's';
    }
}
