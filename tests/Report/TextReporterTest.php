<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\TextReporter;
use PHPUnit\Framework\TestCase;

final class TextReporterTest extends TestCase
{
    public function testRendersStoredChainWithAlignedColumns(): void
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

        $expected = <<<'TXT'
            FINDING  $config: 3 pass-through hops across 4 classes
              origin    Demo\Controller::handle($config)   src/Demo.php:12
              hop 2     Demo\ServiceA::process($config)    src/Demo.php:18
              hop 3     Demo\ServiceB::run($config)        src/Demo.php:24
              terminal  Demo\Mailer::__construct($config)  src/Demo.php:32  (stored)

            1 finding (limit: 3 hops).

            TXT;

        self::assertSame($expected, (new TextReporter(3))->render([$finding]));
    }

    public function testRendersTruncatedChainWithNoteLineAndNoTerminal(): void
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

        $expected = <<<'TXT'
            FINDING  $cfg: 2 pass-through hops across 2 classes
              origin    Demo\A::go($cfg)    src/A.php:5
              hop 2     Demo\B::step($cfg)  src/B.php:9
              note      truncated: 2 implementations

            1 finding (limit: 3 hops).

            TXT;

        self::assertSame($expected, (new TextReporter(3))->render([$finding]));
    }

    public function testRendersSingularFormsForOneHopOneClassOneFinding(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        $expected = <<<'TXT'
            FINDING  $p: 1 pass-through hop across 1 class
              origin    Demo\A::go($p)    src/A.php:5
              terminal  Demo\A::sink($p)  src/A.php:9  (used)

            1 finding (limit: 1 hop).

            TXT;

        self::assertSame($expected, (new TextReporter(1))->render([$finding]));
    }

    public function testExplainAppendsTraceLinesUnderHeader(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, null),
        ];
        $trace = ['Demo\A::go: method:step -> Demo\B::step'];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\B::step', TerminalKind::Used, 1, $chain, 2, [], $trace);

        $expected = <<<'TXT'
            FINDING  $p: 1 pass-through hop across 2 classes
              origin    Demo\A::go($p)    src/A.php:5
              terminal  Demo\B::step($p)  src/B.php:9  (used)
              explain:
                Demo\A::go: method:step -> Demo\B::step

            1 finding (limit: 3 hops).

            TXT;

        self::assertSame($expected, (new TextReporter(3, true))->render([$finding]));
    }

    public function testExplainDisabledOmitsTraceEvenWhenPresent(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, null),
        ];
        $trace = ['Demo\A::go: method:step -> Demo\B::step'];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\B::step', TerminalKind::Used, 1, $chain, 2, [], $trace);

        self::assertStringNotContainsString('explain:', (new TextReporter(3))->render([$finding]));
    }

    public function testEmptyFindingsRendersReassuringLine(): void
    {
        self::assertSame(
            "No tramp data found (limit: 3 hops).\n",
            (new TextReporter(3))->render([]),
        );
    }
}
