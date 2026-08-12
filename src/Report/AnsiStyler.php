<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The 8-color Styler: wraps each input with the ANSI sequence for its semantic
 * role (Q8 palette) and appends a reset. The only class in src/ that emits \e.
 * Codes are the guaranteed-safe 8-color baseline; no truecolor, no 256-color,
 * no Unicode, no width handling.
 *
 * One public method per semantic role in the Styler interface (the locked Q8
 * palette); the count is fixed by the contract, not by choice, so the
 * TooManyPublicMethods codesize rule is deliberately suppressed here.
 *
 * @SuppressWarnings("TooManyPublicMethods")
 */
final class AnsiStyler implements Styler
{
    private const RESET = "\e[0m";
    private const BOLD_RED = "\e[1;31m";
    private const BOLD_YELLOW = "\e[1;33m";
    private const BOLD = "\e[1m";
    private const CYAN = "\e[36m";
    private const DIM = "\e[2m";
    private const BOLD_MAGENTA = "\e[1;35m";
    private const DIM_GREEN = "\e[2;32m";
    private const BOLD_BLUE = "\e[1;34m";
    private const GREEN = "\e[32m";

    public function severity(string $word, Severity $severity): string
    {
        $prefix = $severity === Severity::Error ? self::BOLD_RED : self::BOLD_YELLOW;

        return $prefix . $word . self::RESET;
    }

    public function param(string $name): string
    {
        return self::BOLD . $name . self::RESET;
    }

    public function paramInMethod(string $name): string
    {
        return self::CYAN . $name . self::RESET;
    }

    public function label(string $label): string
    {
        return self::DIM . $label . self::RESET;
    }

    public function location(string $location): string
    {
        return self::DIM . $location . self::RESET;
    }

    public function annotation(string $annotation): string
    {
        return self::BOLD_MAGENTA . $annotation . self::RESET;
    }

    public function terminalKind(string $kind): string
    {
        return self::DIM_GREEN . $kind . self::RESET;
    }

    public function fileHeader(string $path): string
    {
        return self::BOLD_BLUE . $path . self::RESET;
    }

    public function divider(string $dashes): string
    {
        return self::DIM . $dashes . self::RESET;
    }

    public function summary(string $text): string
    {
        return self::BOLD . $text . self::RESET;
    }

    public function success(string $text): string
    {
        return self::GREEN . $text . self::RESET;
    }
}
