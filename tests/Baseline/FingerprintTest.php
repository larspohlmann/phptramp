<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Baseline;

use PhpTramp\Baseline\Fingerprint;
use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    /** @param list<Hop> $chain */
    private function finding(
        string $param = 'p',
        string $origin = 'A::a',
        ?string $terminal = 'B::b',
        TerminalKind $terminalKind = TerminalKind::Used,
        int $hops = 1,
        array $chain = [],
        int $classes = 2,
        array $notes = ['a note'],
        array $trace = ['a trace line'],
    ): Finding {
        if ($chain === []) {
            $chain = [
                new Hop('A::a', 'A', 'src/A.php', 5, 7),
                new Hop('B::b', 'B', 'src/B.php', 9, null),
            ];
        }

        return new Finding(
            $param,
            $origin,
            $terminal,
            $terminalKind,
            $hops,
            $chain,
            $classes,
            $notes,
            $trace,
        );
    }

    public function testKnownInputHashMatchesSha1OfOriginParamTerminalJoinedByNullBytes(): void
    {
        $finding = $this->finding(param: 'p', origin: 'A::a', terminal: 'B::b');

        self::assertSame(sha1("A::a\0p\0B::b"), Fingerprint::of($finding));
    }

    public function testCollidesWhenOnlyHopCountDiffers(): void
    {
        $longer = $this->finding(
            hops: 2,
            chain: [
                new Hop('A::a', 'A', 'src/A.php', 5, 7),
                new Hop('M::m', 'M', 'src/M.php', 11, 13),
                new Hop('B::b', 'B', 'src/B.php', 9, null),
            ],
        );
        $shorter = $this->finding(
            hops: 1,
            chain: [
                new Hop('A::a', 'A', 'src/A.php', 5, 7),
                new Hop('B::b', 'B', 'src/B.php', 9, null),
            ],
        );

        self::assertSame(Fingerprint::of($longer), Fingerprint::of($shorter));
    }

    public function testCollidesWhenOnlyLineNumbersAndFilesChange(): void
    {
        $moved = $this->finding(
            chain: [
                new Hop('A::a', 'A', 'elsewhere/A.php', 50, 70),
                new Hop('B::b', 'B', 'elsewhere/B.php', 90, null),
            ],
        );
        $original = $this->finding(
            chain: [
                new Hop('A::a', 'A', 'src/A.php', 5, 7),
                new Hop('B::b', 'B', 'src/B.php', 9, null),
            ],
        );

        self::assertSame(Fingerprint::of($moved), Fingerprint::of($original));
    }

    public function testCollidesWhenOnlyNotesAndTraceChange(): void
    {
        $withNotes = $this->finding(notes: ['one note'], trace: ['one trace line']);
        $withOtherNotes = $this->finding(notes: ['different note'], trace: ['different trace line']);

        self::assertSame(Fingerprint::of($withNotes), Fingerprint::of($withOtherNotes));
    }

    public function testCollidesWhenOnlyChangedFlagsChange(): void
    {
        $marked = $this->finding(
            chain: [
                new Hop('A::a', 'A', 'src/A.php', 5, 7, true),
                new Hop('B::b', 'B', 'src/B.php', 9, null, true),
            ],
        );
        $unmarked = $this->finding(
            chain: [
                new Hop('A::a', 'A', 'src/A.php', 5, 7, false),
                new Hop('B::b', 'B', 'src/B.php', 9, null, false),
            ],
        );

        self::assertSame(Fingerprint::of($marked), Fingerprint::of($unmarked));
    }

    public function testDiffersWhenOriginDiffers(): void
    {
        $one = $this->finding(origin: 'A::a');
        $other = $this->finding(origin: 'A::other');

        self::assertNotSame(Fingerprint::of($one), Fingerprint::of($other));
    }

    public function testDiffersWhenParamDiffers(): void
    {
        $one = $this->finding(param: 'p');
        $other = $this->finding(param: 'q');

        self::assertNotSame(Fingerprint::of($one), Fingerprint::of($other));
    }

    public function testDiffersWhenTerminalDiffers(): void
    {
        $one = $this->finding(terminal: 'B::b');
        $other = $this->finding(terminal: 'B::other');

        self::assertNotSame(Fingerprint::of($one), Fingerprint::of($other));
    }

    public function testTruncatedAndExternalFromSameOriginAndParamDiffer(): void
    {
        $truncated = $this->finding(
            terminal: null,
            terminalKind: TerminalKind::Truncated,
        );
        $external = $this->finding(
            terminal: null,
            terminalKind: TerminalKind::External,
        );

        self::assertNotSame(Fingerprint::of($truncated), Fingerprint::of($external));
    }

    public function testNullTerminalWithTruncatedKindUsesTokenTruncated(): void
    {
        $finding = $this->finding(
            terminal: null,
            terminalKind: TerminalKind::Truncated,
            origin: 'A::a',
            param: 'p',
        );

        self::assertSame(sha1("A::a\0p\0truncated"), Fingerprint::of($finding));
    }

    public function testLineFormatIsHashSpaceOriginDollarParamArrowTerminalToken(): void
    {
        $finding = $this->finding(
            param: 'p',
            origin: 'Demo\A::a',
            terminal: 'Demo\B::b',
        );

        $expected = sha1("Demo\A::a\0p\0Demo\B::b") . ' Demo\A::a $p -> Demo\B::b';

        self::assertSame($expected, Fingerprint::line($finding));
    }
}
