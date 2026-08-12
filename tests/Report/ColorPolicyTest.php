<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\AnsiStyler;
use PhpTramp\Report\ColorPolicy;
use PhpTramp\Report\NullStyler;
use PHPUnit\Framework\TestCase;

/**
 * ColorPolicy is the pure 3-input → Styler resolver. No env/TTY access lives
 * here; the caller (Application) reads stream_isatty/getenv and passes the
 * booleans. This is the exhaustive Q11 truth table: always/never are absolute
 * over NO_COLOR; auto honors NO_COLOR and the TTY.
 */
final class ColorPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool, 3: string}>
     *     Each case: [mode, tty, noColorSet, expectedStylerClass].
     */
    public static function cases(): iterable
    {
        // always is absolute: ANSI regardless of tty / NO_COLOR.
        yield 'always, tty, no NO_COLOR' => ['always', true, false, AnsiStyler::class];
        yield 'always, tty, NO_COLOR set' => ['always', true, true, AnsiStyler::class];
        yield 'always, non-tty, no NO_COLOR' => ['always', false, false, AnsiStyler::class];
        yield 'always, non-tty, NO_COLOR set' => ['always', false, true, AnsiStyler::class];

        // never is absolute: NullStyler regardless of tty / NO_COLOR.
        yield 'never, tty, no NO_COLOR' => ['never', true, false, NullStyler::class];
        yield 'never, tty, NO_COLOR set' => ['never', true, true, NullStyler::class];
        yield 'never, non-tty, no NO_COLOR' => ['never', false, false, NullStyler::class];
        yield 'never, non-tty, NO_COLOR set' => ['never', false, true, NullStyler::class];

        // auto honors NO_COLOR and the TTY.
        yield 'auto, tty, no NO_COLOR' => ['auto', true, false, AnsiStyler::class];
        yield 'auto, tty, NO_COLOR set' => ['auto', true, true, NullStyler::class];
        yield 'auto, non-tty, no NO_COLOR' => ['auto', false, false, NullStyler::class];
        yield 'auto, non-tty, NO_COLOR set' => ['auto', false, true, NullStyler::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testResolvesStylerPerTruthTable(string $mode, bool $tty, bool $noColorSet, string $expected): void
    {
        self::assertInstanceOf($expected, ColorPolicy::from($mode, $tty, $noColorSet));
    }
}
