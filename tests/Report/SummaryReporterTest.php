<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\SummaryReporter;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class SummaryReporterTest extends TestCase
{
    public function testEmptyFindingsRendersNoChainsFoundMessage(): void
    {
        $reporter = new SummaryReporter(new Thresholds(3, null));

        self::assertSame("No chains found.\n", $reporter->render([]));
    }

    /**
     * Twelve findings across four hop lengths (1..4), exercising: ascending
     * hop-length bars, the Top-10 cap (one of three 1-hop findings is
     * excluded), the hops-desc/origin-asc/param-asc tiebreak (the two 4-hop
     * findings share an origin and are ordered by param), both terminal-clause
     * forms (`-> external`/`-> truncated` vs `-> X [kind]`), a most-forwarded
     * count tie broken alphabetically ($alpha and $beta both appear 4x), and
     * findings both under and at/over the limit of 3 hops.
     */
    public function testRendersMixedHopLengthsWithAllFourBlocks(): void
    {
        $findings = [
            $this->finding('beta', 4, 'Demo\Kernel::boot', null, TerminalKind::External),
            $this->finding('alpha', 4, 'Demo\Kernel::boot', null, TerminalKind::External),
            $this->finding('gamma', 3, 'Demo\ServiceA::run', 'Demo\Mailer::__construct', TerminalKind::Stored),
            $this->finding('gamma', 3, 'Demo\ServiceB::run', 'Demo\Logger::log', TerminalKind::Used),
            $this->finding('gamma', 3, 'Demo\ServiceC::run', null, TerminalKind::Truncated),
            $this->finding('alpha', 2, 'Demo\Two::a', 'Demo\Sink::a', TerminalKind::Used),
            $this->finding('beta', 2, 'Demo\Two::b', 'Demo\Sink::b', TerminalKind::Stored),
            $this->finding('alpha', 2, 'Demo\Two::c', null, TerminalKind::External),
            $this->finding('delta', 2, 'Demo\Two::d', null, TerminalKind::Truncated),
            $this->finding('alpha', 1, 'Demo\One::x', 'Demo\Sink::x', TerminalKind::Used),
            $this->finding('beta', 1, 'Demo\One::y', 'Demo\Sink::y', TerminalKind::Used),
            $this->finding('beta', 1, 'Demo\One::z', 'Demo\Sink::z', TerminalKind::Used),
        ];

        $expected = <<<'TXT'
            Chains by length:
              1 hop   ### 3
              2 hops  #### 4
              3 hops  ### 3
              4 hops  ## 2

            Top 10 longest chains:
              4 hops  $alpha  Demo\Kernel::boot -> external
              4 hops  $beta   Demo\Kernel::boot -> external
              3 hops  $gamma  Demo\ServiceA::run -> Demo\Mailer::__construct [stored]
              3 hops  $gamma  Demo\ServiceB::run -> Demo\Logger::log [used]
              3 hops  $gamma  Demo\ServiceC::run -> truncated
              2 hops  $alpha  Demo\Two::a -> Demo\Sink::a [used]
              2 hops  $beta   Demo\Two::b -> Demo\Sink::b [stored]
              2 hops  $alpha  Demo\Two::c -> external
              2 hops  $delta  Demo\Two::d -> truncated
              1 hop   $alpha  Demo\One::x -> Demo\Sink::x [used]

            Most-forwarded parameters:
              4x  $alpha
              4x  $beta
              3x  $gamma
              1x  $delta

            12 chains total; 5 at or over the limit (limit: 3 hops).

            TXT;

        self::assertSame($expected, (new SummaryReporter(new Thresholds(3, null)))->render($findings));
    }

    /**
     * Largest bucket (100) forces scaling; the other three buckets each pin
     * one otherwise-ambiguous edge of `round()`: 1 exercises the `max(1, …)`
     * floor (its raw scaled value rounds down to 0), 13 has a scaled value
     * (5.2) where `ceil()` would disagree with `round()`, and 17 has one
     * (6.8) where `floor()` would disagree with `round()`.
     */
    public function testScalesBarsWhenLargestBucketExceedsFortyHashes(): void
    {
        $findings = [
            ...$this->findingsWithHops(1, 100),
            ...$this->findingsWithHops(2, 1),
            ...$this->findingsWithHops(3, 13),
            ...$this->findingsWithHops(4, 17),
        ];

        $expected = 'Chains by length:' . "\n"
            . '  1 hop   ' . str_repeat('#', 40) . ' 100' . "\n"
            . '  2 hops  # 1' . "\n"
            . '  3 hops  ##### 13' . "\n"
            . '  4 hops  ####### 17';

        $output = (new SummaryReporter(new Thresholds(3, null)))->render($findings);
        $chainsByLengthBlock = explode("\n\n", $output)[0];

        self::assertSame($expected, $chainsByLengthBlock);
    }

    /**
     * @return list<Finding>
     */
    private function findingsWithHops(int $hops, int $count): array
    {
        $findings = [];
        for ($index = 0; $index < $count; $index++) {
            $findings[] = $this->finding('p', $hops, "Demo\\Scale::m{$index}", null, TerminalKind::External);
        }

        return $findings;
    }

    private function finding(
        string $param,
        int $hops,
        string $origin,
        ?string $terminal,
        TerminalKind $terminalKind,
    ): Finding {
        return new Finding($param, $origin, $terminal, $terminalKind, $hops, [], $hops + 1, [], []);
    }
}
