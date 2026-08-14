<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testStoredChainKeepsItsTerminalNodeOutOfTheForwardingHops(): void
    {
        $finding = $this->finding(TerminalKind::Stored, ['Demo\A::go', 'Demo\B::step', 'Demo\C::__construct']);

        self::assertTrue($finding->hasTerminalNode());
        self::assertSame(
            ['Demo\A::go', 'Demo\B::step'],
            array_map(static fn (Hop $hop): string => $hop->fqmn, $finding->forwardingHops()),
        );
    }

    public function testTruncatedChainHasNoTerminalNodeSoEveryEntryForwards(): void
    {
        $finding = $this->finding(TerminalKind::Truncated, ['Demo\A::go', 'Demo\B::step']);

        self::assertFalse($finding->hasTerminalNode());
        self::assertSame(
            ['Demo\A::go', 'Demo\B::step'],
            array_map(static fn (Hop $hop): string => $hop->fqmn, $finding->forwardingHops()),
        );
    }

    /**
     * @param list<string> $fqmns
     */
    private function finding(TerminalKind $kind, array $fqmns): Finding
    {
        $chain = array_map(
            static fn (string $fqmn): Hop => new Hop($fqmn, 'Demo\Klass', '/tmp/Demo.php', 1, 2),
            $fqmns,
        );

        return new Finding('config', $fqmns[0], null, $kind, count($chain), $chain, 1, [], []);
    }
}
