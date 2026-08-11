<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\GithubReporter;
use PhpTramp\Report\Paths;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class GithubReporterTest extends TestCase
{
    /**
     * Working directory that is not a prefix of any fixture file path below, so
     * Paths::relativize leaves the already-relative "src/..." files unchanged.
     */
    private function paths(): Paths
    {
        return new Paths('/not/matching');
    }

    public function testEmitsErrorAnnotationAtOriginAndNoticePerSubsequentHop(): void
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

        $expected = "::error file=src/Demo.php,line=12,title=phptramp::\$config: 3 pass-through hops across 4 "
            . "classes (terminal: Demo\\Mailer::__construct [stored])\n"
            . "::notice file=src/Demo.php,line=18,title=phptramp::hop 2 of \$config chain from "
            . "Demo\\Controller::handle\n"
            . "::notice file=src/Demo.php,line=24,title=phptramp::hop 3 of \$config chain from "
            . "Demo\\Controller::handle\n";

        $reporter = new GithubReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testEmitsWarningAnnotationAndOmitsNoticeWhenNoSubsequentHops(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        $expected = "::warning file=src/A.php,line=5,title=phptramp::\$p: 1 pass-through hop across 1 class "
            . "(terminal: Demo\\A::sink [used])\n";

        $reporter = new GithubReporter(new Thresholds(3, 1), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testOmitsBelowThresholdFindingEntirely(): void
    {
        $chain = [
            new Hop('Demo\C::go', 'Demo\C', 'src/C.php', 5, null),
        ];
        $finding = new Finding('q', 'Demo\C::go', 'Demo\C::go', TerminalKind::Used, 1, $chain, 1, [], []);

        $reporter = new GithubReporter(new Thresholds(3, 2), $this->paths());

        self::assertSame('', $reporter->render([$finding]));
    }

    public function testEscapesPercentCarriageReturnAndNewlineInAnnotationData(): void
    {
        $chain = [
            new Hop("Demo\\A::go\r\nInjected", 'Demo\A', 'src/100%/A.php', 5, null),
        ];
        $finding = new Finding(
            'p',
            "Demo\\A::go\r\nInjected",
            "Demo\\A::go\r\nInjected",
            TerminalKind::Used,
            1,
            $chain,
            1,
            [],
            [],
        );

        $expected = '::error file=src/100%25/A.php,line=5,title=phptramp::$p: 1 pass-through hop across 1 class '
            . "(terminal: Demo\\A::go%0D%0AInjected [used])\n";

        $reporter = new GithubReporter(new Thresholds(1, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    /**
     * Findings are not sorted by hop count, so a below-threshold finding can
     * precede an above-threshold one in real input order. This pins that the
     * per-finding `continue` (skip this finding, keep scanning) is not a
     * `break` in disguise (stop scanning entirely) — a `break` here would
     * silently drop every qualifying finding that comes after the first
     * skipped one.
     */
    public function testStillReportsQualifyingFindingAfterAnEarlierBelowThresholdFindingIsSkipped(): void
    {
        $belowChain = [
            new Hop('Demo\Z::go', 'Demo\Z', 'src/Z.php', 1, null),
        ];
        $belowFinding = new Finding('z', 'Demo\Z::go', 'Demo\Z::go', TerminalKind::Used, 1, $belowChain, 1, [], []);

        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20),
            new Hop('Demo\ServiceB::run', 'Demo\ServiceB', 'src/Demo.php', 24, 26),
            new Hop('Demo\Mailer::__construct', 'Demo\Mailer', 'src/Demo.php', 32, null),
        ];
        $aboveFinding = new Finding(
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

        $expected = "::error file=src/Demo.php,line=12,title=phptramp::\$config: 3 pass-through hops across 4 "
            . "classes (terminal: Demo\\Mailer::__construct [stored])\n"
            . "::notice file=src/Demo.php,line=18,title=phptramp::hop 2 of \$config chain from "
            . "Demo\\Controller::handle\n"
            . "::notice file=src/Demo.php,line=24,title=phptramp::hop 3 of \$config chain from "
            . "Demo\\Controller::handle\n";

        $reporter = new GithubReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([$belowFinding, $aboveFinding]));
    }

    public function testEmptyRunRendersEmptyString(): void
    {
        $reporter = new GithubReporter(new Thresholds(3, null), $this->paths());

        self::assertSame('', $reporter->render([]));
    }
}
