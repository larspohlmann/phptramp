<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The no-op Styler: every method returns its input unchanged. Injected when
 * --color=never, when --color=auto on a non-TTY, when NO_COLOR is set in auto
 * mode, and by every PrettyReporter test (so layout fixtures carry no ANSI).
 *
 * One public method per semantic role in the Styler interface (the locked Q8
 * palette); the count is fixed by the contract, not by choice, so the
 * TooManyPublicMethods codesize rule is deliberately suppressed here.
 *
 * @SuppressWarnings("TooManyPublicMethods")
 */
final class NullStyler implements Styler
{
    public function severity(string $word, Severity $severity): string
    {
        return $word;
    }

    public function param(string $name): string
    {
        return $name;
    }

    public function paramInMethod(string $name): string
    {
        return $name;
    }

    public function label(string $label): string
    {
        return $label;
    }

    public function location(string $location): string
    {
        return $location;
    }

    public function annotation(string $annotation): string
    {
        return $annotation;
    }

    public function terminalKind(string $kind): string
    {
        return $kind;
    }

    public function fileHeader(string $path): string
    {
        return $path;
    }

    public function divider(string $dashes): string
    {
        return $dashes;
    }

    public function summary(string $text): string
    {
        return $text;
    }

    public function success(string $text): string
    {
        return $text;
    }
}
