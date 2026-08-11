<?php

declare(strict_types=1);

namespace PhpTramp\Console;

/**
 * The `--changed-only`/`--git-base`/`--diff` flags travel together as a unit
 * throughout parsing (`--diff` even sets two of them at once), so ArgvParser
 * holds them behind one field instead of three — keeping its own field count
 * under PHPMD's ceiling without losing the 1:1 mapping onto Options.
 */
final class DiffAwareFlags
{
    public function __construct(
        public bool $changedOnly = false,
        public string $gitBase = 'origin/main',
        public ?string $diff = null,
    ) {
    }
}
