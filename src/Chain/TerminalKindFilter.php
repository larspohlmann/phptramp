<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

use PhpTramp\Console\InvalidArgsException;

/**
 * Drops findings whose chain ends in a terminal kind the user excluded
 * (`--exclude-terminal` / the `excludeTerminals` config key). Default is to
 * exclude nothing.
 *
 * A team that tightens its thresholds may not want chains that end by handing
 * the value to a constructor or value object — the parameter has arrived at
 * its home, which is the refactoring phptramp itself recommends. That is a
 * project-level policy, not a per-site one, so it is one config line rather
 * than a #[TrampIgnore] on every factory.
 *
 * The name -> enum mapping lives here alone; Application resolves it before
 * building the index so a typo fails fast rather than after the expensive work.
 */
final class TerminalKindFilter
{
    /**
     * @param list<TerminalKind> $excluded
     */
    private function __construct(private readonly array $excluded)
    {
    }

    /**
     * @param list<string> $names TerminalKind backing values
     *
     * @throws InvalidArgsException on a name that is not a terminal kind
     */
    public static function fromNames(array $names): self
    {
        $excluded = [];
        foreach ($names as $name) {
            $kind = TerminalKind::tryFrom($name);
            if ($kind === null) {
                throw new InvalidArgsException(
                    "unknown terminal kind: {$name} (expected " . implode('|', self::kindNames()) . ')',
                );
            }
            $excluded[] = $kind;
        }

        return new self($excluded);
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings): array
    {
        if ($this->excluded === []) {
            return $findings;
        }

        $kept = [];
        foreach ($findings as $finding) {
            if (! in_array($finding->terminalKind, $this->excluded, true)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    /**
     * @return list<string>
     */
    private static function kindNames(): array
    {
        return array_map(static fn (TerminalKind $kind): string => $kind->value, TerminalKind::cases());
    }
}
