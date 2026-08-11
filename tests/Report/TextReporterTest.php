<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\Paths;
use PhpTramp\Report\TextReporter;
use PhpTramp\Report\Thresholds;
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

        $reporter = new TextReporter(new Thresholds(3, null), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$finding]));
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

            1 finding (limit: 2 hops).

            TXT;

        $reporter = new TextReporter(new Thresholds(2, null), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$finding]));
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

        $reporter = new TextReporter(new Thresholds(1, null), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$finding]));
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

            1 finding (limit: 1 hop).

            TXT;

        $reporter = new TextReporter(new Thresholds(1, null), new Paths('/nonexistent-root'), true);

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testExplainDisabledOmitsTraceEvenWhenPresent(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, null),
        ];
        $trace = ['Demo\A::go: method:step -> Demo\B::step'];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\B::step', TerminalKind::Used, 1, $chain, 2, [], $trace);

        $reporter = new TextReporter(new Thresholds(1, null), new Paths('/nonexistent-root'));

        self::assertStringNotContainsString('explain:', $reporter->render([$finding]));
    }

    public function testEmptyFindingsRendersReassuringLine(): void
    {
        self::assertSame(
            "No tramp data found (limit: 3 hops).\n",
            (new TextReporter(new Thresholds(3, null), new Paths('/nonexistent-root')))->render([]),
        );
    }

    public function testRendersSingleWarningFindingAndFiltersBelowWarnLimit(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];
        $warningFinding = new Finding('p', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 2, $chain, 2, [], []);
        $belowWarnLimitChain = [
            new Hop('Demo\C::go', 'Demo\C', 'src/C.php', 5, null),
        ];
        $belowWarnLimitFinding = new Finding(
            'q',
            'Demo\C::go',
            'Demo\C::go',
            TerminalKind::Used,
            1,
            $belowWarnLimitChain,
            1,
            [],
            [],
        );

        $expected = <<<'TXT'
            WARNING  $p: 2 pass-through hops across 2 classes
              origin    Demo\A::go($p)    src/A.php:5
              hop 2     Demo\B::step($p)  src/B.php:9
              terminal  Demo\C::sink($p)  src/C.php:13  (used)

            1 finding (0 errors, 1 warning; limit: 3 hops, warn-limit: 2 hops).

            TXT;

        $reporter = new TextReporter(new Thresholds(3, 2), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$warningFinding, $belowWarnLimitFinding]));
    }

    public function testRelativizesAbsoluteFilePathUnderWorkingDirectory(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', '/tmp/project/src/Demo.php', 5, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::go', TerminalKind::Used, 1, $chain, 1, [], []);

        $reporter = new TextReporter(new Thresholds(1, null), new Paths('/tmp/project'));

        self::assertStringContainsString('src/Demo.php:5', $reporter->render([$finding]));
    }

    public function testRendersMixedErrorAndWarningFindingsWithCombinedSummary(): void
    {
        $errorChain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\Y::step', 'Demo\Y', 'src/Y.php', 15, 17),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];
        $errorFinding = new Finding('p', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 3, $errorChain, 3, [], []);
        $warningChain = [
            new Hop('Demo\D::go', 'Demo\D', 'src/D.php', 5, 7),
            new Hop('Demo\E::step', 'Demo\E', 'src/E.php', 9, 11),
            new Hop('Demo\F::sink', 'Demo\F', 'src/F.php', 13, null),
        ];
        $warningFinding = new Finding(
            'q',
            'Demo\D::go',
            'Demo\F::sink',
            TerminalKind::Used,
            2,
            $warningChain,
            2,
            [],
            [],
        );

        $expected = <<<'TXT'
            FINDING  $p: 3 pass-through hops across 3 classes
              origin    Demo\A::go($p)    src/A.php:5
              hop 2     Demo\B::step($p)  src/B.php:9
              hop 3     Demo\Y::step($p)  src/Y.php:15
              terminal  Demo\C::sink($p)  src/C.php:13  (used)

            WARNING  $q: 2 pass-through hops across 2 classes
              origin    Demo\D::go($q)    src/D.php:5
              hop 2     Demo\E::step($q)  src/E.php:9
              terminal  Demo\F::sink($q)  src/F.php:13  (used)

            2 findings (1 error, 1 warning; limit: 3 hops, warn-limit: 2 hops).

            TXT;

        $reporter = new TextReporter(new Thresholds(3, 2), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$errorFinding, $warningFinding]));
    }
}
