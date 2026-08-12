<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The pure color-mode → Styler resolver. Given the --color mode (always|auto|
 * never), whether STDOUT is a TTY, and whether NO_COLOR is set, returns the
 * Styler to inject into PrettyReporter. No env/TTY access lives here — the
 * caller reads stream_isatty/getenv and passes the booleans, so this class is
 * trivially testable with the exhaustive Q11 truth table.
 *
 * Precedence: always/never are absolute over NO_COLOR; auto honors NO_COLOR
 * (any non-empty value) and the TTY.
 */
final class ColorPolicy
{
    public static function from(string $mode, bool $tty, bool $noColorSet): Styler
    {
        return match ($mode) {
            'always' => new AnsiStyler(),
            'never' => new NullStyler(),
            'auto' => $tty && ! $noColorSet ? new AnsiStyler() : new NullStyler(),
            // Unreachable: ArgvParser::validateColorMode rejects any mode
            // outside ['always','auto','never'] at parse time.
            default => new NullStyler(),
        };
    }
}
