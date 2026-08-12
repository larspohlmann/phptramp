<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\NullStyler;
use PhpTramp\Report\Paths;
use PhpTramp\Report\PrettyReporter;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

/**
 * PrettyReporter layout fixtures, injected with NullStyler so they assert
 * pure text (grouping, dividers, file headers, labels, summary) with zero
 * ANSI noise. The color codes themselves are pinned in AnsiStylerTest.
 *
 * The method column is aligned exactly like TextReporter: each row's
 * "FQMN($param)" is padded to the longest method in the finding, then a
 * 2-space gap, then the location. The fixtures below were regenerated from
 * the reporter to be byte-for-byte consistent with that rule (earlier drafts
 * had hand-typed spacing and a self-contradictory $b label in the mixed
 * report — the summary counted it as a warning while the header read FINDING).
 */
final class PrettyReporterTest extends TestCase
{
    public function testEmptyFindingsRendersSuccessLine(): void
    {
        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame("No tramp data found (limit: 3 hops).\n", $reporter->render([]));
    }

    public function testRendersSingleFindingWithFileHeaderAndDividerAndSummary(): void
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
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)   src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)    src/Demo.php:18
  hop 3     Demo\ServiceB::run($config)        src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)  src/Demo.php:32  (stored)

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testRendersWarningHeaderBelowLimitAtWarnLimit(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, null),
        ];
        $finding = new Finding('cfg', 'Demo\A::go', 'Demo\B::step', TerminalKind::Used, 1, $chain, 2, [], []);

        $expected = <<<'TXT'
src/A.php

WARNING  $cfg: 1 pass-through hop across 2 classes
  origin    Demo\A::go($cfg)    src/A.php:5
  terminal  Demo\B::step($cfg)  src/B.php:9  (used)

----------------------------------------------------------------

1 finding (limit: 3 hops, warn-limit: 1 hop).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, 1),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testMarksChangedNonTerminalHopWithYoursAnnotation(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20, true),
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
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)   src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)    src/Demo.php:18  *YOURS*
  hop 3     Demo\ServiceB::run($config)        src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)  src/Demo.php:32  (stored)

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testGroupsByFileAndSortsWithinFileByFirstHopLine(): void
    {
        // Two findings in src/Demo.php (lines 12 and 5), one in src/Other.php.
        // Input order is scrambled: Other first, then Demo-line-12, then line-5.
        // Expected output: Demo.php group (line 5 finding first, then line 12),
        // then Other.php group. File groups are alphabetical; within a file,
        // findings are sorted by the first hop's line.
        $demoEarly = new Finding(
            'early',
            'Demo\A::go',
            'Demo\B::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\A::go', 'Demo\A', 'src/Demo.php', 5, 7),
                new Hop('Demo\B::sink', 'Demo\B', 'src/Demo.php', 9, null),
            ],
            2,
            [],
            [],
        );
        $demoLate = new Finding(
            'late',
            'Demo\C::run',
            'Demo\D::end',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\C::run', 'Demo\C', 'src/Demo.php', 12, 14),
                new Hop('Demo\D::end', 'Demo\D', 'src/Demo.php', 16, null),
            ],
            2,
            [],
            [],
        );
        $other = new Finding(
            'flag',
            'Demo\X::go',
            'Demo\Y::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\X::go', 'Demo\X', 'src/Other.php', 5, 7),
                new Hop('Demo\Y::sink', 'Demo\Y', 'src/Other.php', 9, null),
            ],
            2,
            [],
            [],
        );

        $expected = <<<'TXT'
src/Demo.php

FINDING  $early: 1 pass-through hop across 2 classes
  origin    Demo\A::go($early)    src/Demo.php:5
  terminal  Demo\B::sink($early)  src/Demo.php:9  (used)

FINDING  $late: 1 pass-through hop across 2 classes
  origin    Demo\C::run($late)  src/Demo.php:12
  terminal  Demo\D::end($late)  src/Demo.php:16  (used)

----------------------------------------------------------------

src/Other.php

FINDING  $flag: 1 pass-through hop across 2 classes
  origin    Demo\X::go($flag)    src/Other.php:5
  terminal  Demo\Y::sink($flag)  src/Other.php:9  (used)

----------------------------------------------------------------

3 findings (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$other, $demoLate, $demoEarly]));
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
src/A.php

FINDING  $cfg: 2 pass-through hops across 2 classes
  origin  Demo\A::go($cfg)    src/A.php:5
  hop 2   Demo\B::step($cfg)  src/B.php:9
  note    truncated: 2 implementations

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testExplainBlockRenderedWhenExplainTrue(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::sink', 'Demo\B', 'src/A.php', 9, null),
        ];
        $finding = new Finding(
            'cfg',
            'Demo\A::go',
            'Demo\B::sink',
            TerminalKind::Used,
            1,
            $chain,
            2,
            [],
            ['resolved Demo\A::go -> Demo\B::sink via interface Demo\I'],
        );

        $expected = <<<'TXT'
src/A.php

FINDING  $cfg: 1 pass-through hop across 2 classes
  origin    Demo\A::go($cfg)    src/A.php:5
  terminal  Demo\B::sink($cfg)  src/A.php:9  (used)
  explain:
    resolved Demo\A::go -> Demo\B::sink via interface Demo\I

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, null),
            new Paths('/nonexistent-root'),
            true,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testWarnVsErrorSummarySplit(): void
    {
        $error = new Finding(
            'a',
            'Demo\A::go',
            'Demo\C::sink',
            TerminalKind::Used,
            3,
            [
                new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
                new Hop('Demo\B::step', 'Demo\B', 'src/A.php', 9, 11),
                new Hop('Demo\C::sink', 'Demo\C', 'src/A.php', 13, null),
            ],
            3,
            [],
            [],
        );
        $warning = new Finding(
            'b',
            'Demo\A::go',
            'Demo\C::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
                new Hop('Demo\C::sink', 'Demo\C', 'src/A.php', 13, null),
            ],
            2,
            [],
            [],
        );

        $expected = <<<'TXT'
src/A.php

FINDING  $a: 3 pass-through hops across 3 classes
  origin    Demo\A::go($a)    src/A.php:5
  hop 2     Demo\B::step($a)  src/A.php:9
  terminal  Demo\C::sink($a)  src/A.php:13  (used)

WARNING  $b: 1 pass-through hop across 2 classes
  origin    Demo\A::go($b)    src/A.php:5
  terminal  Demo\C::sink($b)  src/A.php:13  (used)

----------------------------------------------------------------

2 findings (1 error, 1 warning; limit: 3 hops, warn-limit: 1 hop).

TXT;

        $reporter = new PrettyReporter(
            new Thresholds(3, 1),
            new Paths('/nonexistent-root'),
            false,
            new NullStyler(),
        );

        self::assertSame($expected, $reporter->render([$error, $warning]));
    }
}
