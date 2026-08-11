<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Baseline\Baseline;
use PhpTramp\Baseline\Fingerprint;
use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Console\StaleReporter;
use PhpTramp\Ignore\SuppressionIndex;
use PHPUnit\Framework\TestCase;

final class StaleReporterTest extends TestCase
{
    public function testStaleSuppressionsAreKeysMinusFiredKeysInConfigurationOrder(): void
    {
        // Three configured suppression keys, one of which fired — the other two
        // are stale and must be reported (array_diff, not all keys).
        $suppressions = new SuppressionIndex(
            ['Demo\A::go', 'Demo\B::step'],
            [['Demo\C::run', 'config']],
            [],
        );
        $reporter = new StaleReporter(null, $suppressions);

        $firedKey = SuppressionIndex::methodKey('Demo\A::go');
        $lines = $reporter->lines(false, [], [$firedKey]);

        self::assertSame([
            'phptramp: stale suppression: ' . SuppressionIndex::methodKey('Demo\B::step'),
            'phptramp: stale suppression: ' . SuppressionIndex::paramKey('Demo\C::run', 'config'),
        ], $lines);
    }

    public function testStaleBaselineAndSuppressionLinesAreReportedTogetherInOrder(): void
    {
        // Two stale baseline entries plus two stale suppression keys — every
        // line must appear, proving the lines list is not truncated to one.
        $matched = $this->finding('p', 'Demo\A::a', 'Demo\B::b');
        $firstStale = $this->finding('q', 'Demo\X::x', 'Demo\Y::y');
        $secondStale = $this->finding('r', 'Demo\M::m', 'Demo\N::n');
        $matchedLine = Fingerprint::line($matched);
        $firstStaleLine = Fingerprint::line($firstStale);
        $secondStaleLine = Fingerprint::line($secondStale);
        $document = json_encode(
            ['fingerprints' => [$matchedLine, $firstStaleLine, $secondStaleLine]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );
        $baseline = Baseline::fromJson($document);

        $suppressions = new SuppressionIndex(
            ['Demo\Unused::one', 'Demo\Unused::two'],
            [],
            [],
        );
        $reporter = new StaleReporter($baseline, $suppressions);

        $lines = $reporter->lines(false, [$matched], []);

        self::assertSame([
            'phptramp: stale baseline entry: ' . $firstStaleLine,
            'phptramp: stale baseline entry: ' . $secondStaleLine,
            'phptramp: stale suppression: ' . SuppressionIndex::methodKey('Demo\Unused::one'),
            'phptramp: stale suppression: ' . SuppressionIndex::methodKey('Demo\Unused::two'),
        ], $lines);
    }

    private function finding(
        string $param,
        string $origin,
        ?string $terminal,
    ): Finding {
        $chain = [
            new Hop($origin, null, 'src/Origin.php', 1, 2),
            new Hop($terminal ?? 'Demo\T::t', null, 'src/Terminal.php', 3, null),
        ];

        return new Finding($param, $origin, $terminal, TerminalKind::Used, 1, $chain, 2, [], []);
    }
}
