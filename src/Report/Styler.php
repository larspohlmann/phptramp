<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The color/style seam for PrettyReporter. One method per semantic role in
 * the pretty layout (Q8 palette); the implementation decides whether to emit
 * ANSI escapes (AnsiStyler) or return the input unchanged (NullStyler).
 *
 * PrettyReporter never branches on "is color on?" — it calls these methods
 * and lets the injected Styler decide. This keeps rendering free of the
 * boolean-flag-selects-behaviour smell and lets --color=always|auto|never
 * and NO_COLOR be resolved entirely at the ColorPolicy seam.
 *
 * `param` and `paramInMethod` are distinct roles in the locked palette: the
 * header's `$param` fragment is bold, while the per-row `($param)` fragment
 * is cyan and not bold. Splitting them keeps the palette decisions in the
 * Styler implementation, not at the call site.
 */
interface Styler
{
    public function severity(string $word, Severity $severity): string;

    public function param(string $name): string;

    public function paramInMethod(string $name): string;

    public function label(string $label): string;

    public function location(string $location): string;

    public function annotation(string $annotation): string;

    public function terminalKind(string $kind): string;

    public function fileHeader(string $path): string;

    public function divider(string $dashes): string;

    public function summary(string $text): string;

    public function success(string $text): string;
}
