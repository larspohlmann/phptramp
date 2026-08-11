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
    private readonly Pluralizer $pluralizer;

    public function __construct()
    {
        $this->pluralizer = new Pluralizer();
    }

    public function describe(Finding $finding): string
    {
        return '$' . $finding->param . ': ' . $finding->hops . ' pass-through '
            . $this->pluralizer->of($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->pluralizer->of($finding->classes, 'class', 'classes')
            . ' (terminal: ' . $this->terminalClause($finding) . ')';
    }

    /** Shared with {@see SummaryReporter}'s "Top 10 longest chains" column. */
    public function terminalClause(Finding $finding): string
    {
        if ($finding->terminal === null) {
            return $finding->terminalKind->value;
        }

        return $finding->terminal . ' [' . $finding->terminalKind->value . ']';
    }
}
