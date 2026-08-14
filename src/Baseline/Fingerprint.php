<?php

declare(strict_types=1);

namespace PhpTramp\Baseline;

use PhpTramp\Chain\Finding;

/**
 * Refactor-stable fingerprint of a tramp-data finding: a sha1 over only the
 * semantic identity (origin, param, terminal token) — never over the chain
 * shape or source locations, so shortening a chain, moving a file, or shifting
 * a line number does not "re-open" a baselined finding.
 */
final class Fingerprint
{
    /** sha1(origin . "\0" . param . "\0" . terminalToken) — see terminalToken(). */
    public static function of(Finding $finding): string
    {
        return sha1(
            $finding->origin
            . "\0" . $finding->param
            . "\0" . self::terminalToken($finding),
        );
    }

    /**
     * The baseline file line for a finding: "<sha1> <origin> $<param> -> <terminalToken>".
     * Everything after the first space is human context; parsing ignores it.
     */
    public static function line(Finding $finding): string
    {
        return self::of($finding)
            . ' ' . $finding->origin
            . ' $' . $finding->param
            . ' -> ' . self::terminalToken($finding);
    }

    /** Terminal FQMN when the chain has one, else the TerminalKind backing value. */
    private static function terminalToken(Finding $finding): string
    {
        return $finding->terminal ?? $finding->terminalKind->value;
    }
}
