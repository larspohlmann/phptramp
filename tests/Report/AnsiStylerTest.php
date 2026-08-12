<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\AnsiStyler;
use PhpTramp\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * AnsiStyler is the 8-color implementation. Every method wraps its input with
 * the exact ANSI sequence from the Q8 palette and appends a reset. These
 * exact-string assertions are the only place the escape sequences are pinned;
 * every other test injects NullStyler and is ANSI-free.
 */
final class AnsiStylerTest extends TestCase
{
    private AnsiStyler $styler;

    protected function setUp(): void
    {
        $this->styler = new AnsiStyler();
    }

    public function testSeverityErrorIsBoldRed(): void
    {
        self::assertSame("\e[1;31mFINDING\e[0m", $this->styler->severity('FINDING', Severity::Error));
    }

    public function testSeverityWarningIsBoldYellow(): void
    {
        self::assertSame("\e[1;33mWARNING\e[0m", $this->styler->severity('WARNING', Severity::Warning));
    }

    public function testParamIsBold(): void
    {
        self::assertSame("\e[1mconfig\e[0m", $this->styler->param('config'));
    }

    public function testParamInMethodIsCyan(): void
    {
        self::assertSame("\e[36mconfig\e[0m", $this->styler->paramInMethod('config'));
    }

    public function testLabelIsDim(): void
    {
        self::assertSame("\e[2morigin\e[0m", $this->styler->label('origin'));
    }

    public function testLocationIsDim(): void
    {
        self::assertSame("\e[2msrc/Demo.php:12\e[0m", $this->styler->location('src/Demo.php:12'));
    }

    public function testAnnotationIsBoldMagenta(): void
    {
        self::assertSame("\e[1;35m*YOURS*\e[0m", $this->styler->annotation('*YOURS*'));
    }

    public function testTerminalKindIsDimGreen(): void
    {
        self::assertSame("\e[2;32m(stored)\e[0m", $this->styler->terminalKind('(stored)'));
    }

    public function testFileHeaderIsBoldBlue(): void
    {
        self::assertSame("\e[1;34msrc/Demo.php\e[0m", $this->styler->fileHeader('src/Demo.php'));
    }

    public function testDividerIsDim(): void
    {
        $dashes = str_repeat('-', 64);
        self::assertSame("\e[2m{$dashes}\e[0m", $this->styler->divider($dashes));
    }

    public function testSummaryIsBold(): void
    {
        self::assertSame("\e[1m1 finding (limit: 3 hops).\e[0m", $this->styler->summary('1 finding (limit: 3 hops).'));
    }

    public function testSuccessIsGreen(): void
    {
        $text = 'No tramp data found (limit: 3 hops).';
        self::assertSame("\e[32m{$text}\e[0m", $this->styler->success($text));
    }
}
