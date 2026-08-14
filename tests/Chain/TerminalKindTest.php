<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalKindTest extends TestCase
{
    /**
     * @return iterable<string, array{0: TerminalKind, 1: bool}>
     */
    public static function terminalKindProvider(): iterable
    {
        yield 'used keeps its node' => [TerminalKind::Used, true];
        yield 'stored keeps its node' => [TerminalKind::Stored, true];
        yield 'by-ref keeps its node' => [TerminalKind::ByRef, true];
        yield 'unused-end keeps its node' => [TerminalKind::Unused, true];
        yield 'external has no node' => [TerminalKind::External, false];
        yield 'truncated has no node' => [TerminalKind::Truncated, false];
    }

    #[DataProvider('terminalKindProvider')]
    public function testKeepsTerminalNode(TerminalKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->keepsTerminalNode());
    }
}
