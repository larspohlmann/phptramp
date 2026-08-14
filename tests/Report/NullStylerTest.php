<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\NullStyler;
use PhpTramp\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * NullStyler is the no-op Styler: every method returns its input unchanged.
 * It is the color-off implementation injected when --color=never, when
 * --color=auto on a non-TTY, or when NO_COLOR is set in auto mode. It is also
 * what every PrettyReporter test injects, so layout fixtures assert pure text
 * with zero ANSI noise.
 */
final class NullStylerTest extends TestCase
{
    public function testSeverityReturnsInputUnchanged(): void
    {
        self::assertSame('FINDING', (new NullStyler())->severity('FINDING', Severity::Error));
        self::assertSame('WARNING', (new NullStyler())->severity('WARNING', Severity::Warning));
    }

    public function testParamReturnsInputUnchanged(): void
    {
        self::assertSame('config', (new NullStyler())->param('config'));
    }

    public function testParamInMethodReturnsInputUnchanged(): void
    {
        self::assertSame('config', (new NullStyler())->paramInMethod('config'));
    }

    public function testLabelReturnsInputUnchanged(): void
    {
        self::assertSame('origin', (new NullStyler())->label('origin'));
    }

    public function testLocationReturnsInputUnchanged(): void
    {
        self::assertSame('src/Demo.php:12', (new NullStyler())->location('src/Demo.php:12'));
    }

    public function testAnnotationReturnsInputUnchanged(): void
    {
        self::assertSame('*YOURS*', (new NullStyler())->annotation('*YOURS*'));
    }

    public function testTerminalKindReturnsInputUnchanged(): void
    {
        self::assertSame('(stored)', (new NullStyler())->terminalKind('(stored)'));
    }

    public function testFileHeaderReturnsInputUnchanged(): void
    {
        self::assertSame('src/Demo.php', (new NullStyler())->fileHeader('src/Demo.php'));
    }

    public function testDividerReturnsInputUnchanged(): void
    {
        self::assertSame(str_repeat('-', 64), (new NullStyler())->divider(str_repeat('-', 64)));
    }

    public function testSummaryReturnsInputUnchanged(): void
    {
        self::assertSame('1 finding (limit: 3 hops).', (new NullStyler())->summary('1 finding (limit: 3 hops).'));
    }

    public function testSuccessReturnsInputUnchanged(): void
    {
        $text = 'No tramp data found (limit: 3 hops).';
        self::assertSame($text, (new NullStyler())->success($text));
    }
}
