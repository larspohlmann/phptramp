<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\FindingMessage;
use PHPUnit\Framework\TestCase;

final class FindingMessageTest extends TestCase
{
    public function testDescribesStoredTerminalWithPluralHopsAndClasses(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20),
            new Hop('Demo\ServiceB::run', 'Demo\ServiceB', 'src/Demo.php', 24, 26),
            new Hop('Demo\Mailer::__construct', 'Demo\Mailer', 'src/Demo.php', 32, null),
        ];
        $finding = new Finding(
            'config',
            'Demo\Controller::handle',
            'Demo\Mailer::__construct',
            TerminalKind::Stored,
            3,
            $chain,
            4,
            [],
            [],
        );

        self::assertSame(
            '$config: 3 pass-through hops across 4 classes (terminal: Demo\Mailer::__construct [stored])',
            (new FindingMessage())->describe($finding),
        );
    }

    public function testDescribesSingularHopAndClass(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        self::assertSame(
            '$p: 1 pass-through hop across 1 class (terminal: Demo\A::sink [used])',
            (new FindingMessage())->describe($finding),
        );
    }

    public function testDescribesNullTerminalWithKindOnlyClause(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
        ];
        $finding = new Finding(
            'cfg',
            'Demo\A::go',
            null,
            TerminalKind::Truncated,
            2,
            $chain,
            2,
            ['truncated: 2 implementations'],
            [],
        );

        self::assertSame(
            '$cfg: 2 pass-through hops across 2 classes (terminal: truncated)',
            (new FindingMessage())->describe($finding),
        );
    }
}
