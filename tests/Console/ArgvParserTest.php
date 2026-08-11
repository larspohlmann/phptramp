<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Console\ArgvParser;
use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase
{
    private function parse(string ...$args): Options
    {
        return (new ArgvParser())->parse(array_values($args));
    }

    public function testDefaults(): void
    {
        $o = $this->parse();
        self::assertSame([], $o->folders);
        self::assertSame([], $o->files);
        self::assertSame(3, $o->limit);
        self::assertNull($o->warnLimit);
        self::assertSame('text', $o->format);
        self::assertFalse($o->explain);
        self::assertFalse($o->changedOnly);
        self::assertSame('origin/main', $o->gitBase);
        self::assertNull($o->baseline);
        self::assertFalse($o->dumpIndex);
    }

    public function testFolderAccumulates(): void
    {
        self::assertSame(['a', 'b'], $this->parse('--folder', 'a', '--folder', 'b')->folders);
    }

    public function testFilesSplitsOnComma(): void
    {
        self::assertSame(['a.php', 'b.php'], $this->parse('--files', 'a.php,b.php')->files);
    }

    public function testFileAppendsToFiles(): void
    {
        self::assertSame(['a.php', 'b.php'], $this->parse('--file', 'a.php', '--file', 'b.php')->files);
    }

    public function testEqualsSyntaxWorks(): void
    {
        self::assertSame(5, $this->parse('--limit=5')->limit);
    }

    public function testLimitAsSeparateArg(): void
    {
        self::assertSame(7, $this->parse('--limit', '7')->limit);
    }

    public function testNonNumericLimitThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--limit', 'x');
    }

    public function testMissingValueThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--folder');
    }

    public function testUnknownFlagThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--nope');
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--format', 'xml');
    }
}
