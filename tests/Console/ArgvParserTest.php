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

    public function testWarnLimitParsed(): void
    {
        self::assertSame(2, $this->parse('--warn-limit', '2')->warnLimit);
    }

    public function testFormatParsed(): void
    {
        self::assertSame('json', $this->parse('--format', 'json')->format);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validFormatProvider(): iterable
    {
        foreach (['text', 'json', 'github', 'checkstyle', 'sarif', 'summary'] as $format) {
            yield $format => [$format];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validFormatProvider')]
    public function testEveryDocumentedFormatIsAccepted(string $format): void
    {
        self::assertSame($format, $this->parse('--format', $format)->format);
    }

    public function testGitBaseParsed(): void
    {
        self::assertSame('develop', $this->parse('--git-base', 'develop')->gitBase);
    }

    public function testBaselineParsed(): void
    {
        self::assertSame('bl.json', $this->parse('--baseline', 'bl.json')->baseline);
    }

    public function testGenerateBaselineParsed(): void
    {
        self::assertSame('out.json', $this->parse('--generate-baseline', 'out.json')->generateBaseline);
    }

    public function testExplainFlag(): void
    {
        self::assertTrue($this->parse('--explain')->explain);
    }

    public function testChangedOnlyFlag(): void
    {
        self::assertTrue($this->parse('--changed-only')->changedOnly);
    }

    public function testDumpIndexFlag(): void
    {
        self::assertTrue($this->parse('--dump-index')->dumpIndex);
    }

    public function testHelpFlags(): void
    {
        self::assertTrue($this->parse('--help')->help);
        self::assertTrue($this->parse('-h')->help);
    }

    public function testVersionFlags(): void
    {
        self::assertTrue($this->parse('--version')->version);
        self::assertTrue($this->parse('-V')->version);
    }

    public function testNonNumericWarnLimitThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--warn-limit', 'x');
    }

    public function testUnknownFormatMessageNamesTheValue(): void
    {
        try {
            $this->parse('--format', 'xml');
            self::fail('expected InvalidArgsException');
        } catch (InvalidArgsException $e) {
            self::assertStringContainsString('xml', $e->getMessage());
        }
    }

    public function testMissingValueMessageNamesTheFlag(): void
    {
        try {
            $this->parse('--limit');
            self::fail('expected InvalidArgsException');
        } catch (InvalidArgsException $e) {
            self::assertStringContainsString('--limit', $e->getMessage());
        }
    }
}
