<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Baseline;

use PhpTramp\Baseline\Baseline;
use PhpTramp\Baseline\BaselineException;
use PhpTramp\Baseline\Fingerprint;
use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\TestCase;

final class BaselineTest extends TestCase
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

        return new Finding($param, $origin, $terminal, $terminalKind, $hops, $chain, $classes, $notes, $trace);
    }

    private function findingAlpha(): Finding
    {
        return $this->finding(
            param: 'p',
            origin: 'Demo\A::a',
            terminal: 'Demo\B::b',
            chain: [
                new Hop('Demo\A::a', 'Demo\A', 'src/A.php', 5, 7),
                new Hop('Demo\B::b', 'Demo\B', 'src/B.php', 9, null),
            ],
        );
    }

    private function findingBeta(): Finding
    {
        return $this->finding(
            param: 'q',
            origin: 'Demo\X::x',
            terminal: 'Demo\Y::y',
            chain: [
                new Hop('Demo\X::x', 'Demo\X', 'src/X.php', 1, 2),
                new Hop('Demo\Y::y', 'Demo\Y', 'src/Y.php', 3, null),
            ],
        );
    }

    private function findingGamma(): Finding
    {
        return $this->finding(
            param: 'r',
            origin: 'Demo\M::m',
            terminal: 'Demo\N::n',
            chain: [
                new Hop('Demo\M::m', 'Demo\M', 'src/M.php', 10, 12),
                new Hop('Demo\N::n', 'Demo\N', 'src/N.php', 14, null),
            ],
        );
    }

    public function testRoundTripGeneratesParsesAndMatchesAllFindings(): void
    {
        $document = Baseline::generate([$this->findingAlpha(), $this->findingBeta()]);
        $baseline = Baseline::fromJson($document);

        self::assertTrue($baseline->has($this->findingAlpha()));
        self::assertTrue($baseline->has($this->findingBeta()));
        self::assertFalse($baseline->has($this->findingGamma()));
    }

    public function testGenerateIsByteStableAcrossInputOrder(): void
    {
        $first = Baseline::generate([$this->findingBeta(), $this->findingAlpha()]);
        $second = Baseline::generate([$this->findingAlpha(), $this->findingBeta()]);

        self::assertSame($first, $second);
    }

    public function testGenerateProducesSortedPrettyDocumentWithTrailingNewline(): void
    {
        $findings = [$this->findingBeta(), $this->findingAlpha()];
        $lines = [
            Fingerprint::line($this->findingAlpha()),
            Fingerprint::line($this->findingBeta()),
        ];
        sort($lines);

        $expected = json_encode(
            ['fingerprints' => $lines],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";

        self::assertSame($expected, Baseline::generate($findings));
    }

    public function testHashExtractionIgnoresContextAfterFirstWhitespace(): void
    {
        $finding = $this->findingAlpha();
        $hash = Fingerprint::of($finding);
        $document = json_encode(
            ['fingerprints' => ["{$hash} anything at all"]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        self::assertTrue(Baseline::fromJson($document)->has($finding));
    }

    public function testHashExtractionHandlesEntryWithNoContext(): void
    {
        $finding = $this->findingAlpha();
        $hash = Fingerprint::of($finding);
        $document = json_encode(
            ['fingerprints' => [$hash]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        self::assertTrue(Baseline::fromJson($document)->has($finding));
    }

    public function testStaleEntriesReturnsUnmatchedRawLines(): void
    {
        $matched = $this->findingAlpha();
        $unmatched = $this->findingBeta();
        $matchedLine = Fingerprint::line($matched);
        $unmatchedLine = Fingerprint::line($unmatched);
        $document = json_encode(
            ['fingerprints' => [$matchedLine, $unmatchedLine]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $baseline = Baseline::fromJson($document);

        self::assertSame([$unmatchedLine], $baseline->staleEntries([$matched]));
    }

    public function testStaleEntriesReturnsEveryUnmatchedLineInDocumentOrder(): void
    {
        $matched = $this->findingAlpha();
        $firstUnmatched = $this->findingBeta();
        $secondUnmatched = $this->findingGamma();
        $matchedLine = Fingerprint::line($matched);
        $firstStaleLine = Fingerprint::line($firstUnmatched);
        $secondStaleLine = Fingerprint::line($secondUnmatched);
        $document = json_encode(
            ['fingerprints' => [$matchedLine, $firstStaleLine, $secondStaleLine]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $baseline = Baseline::fromJson($document);

        self::assertSame([$firstStaleLine, $secondStaleLine], $baseline->staleEntries([$matched]));
    }

    public function testStaleEntriesIsEmptyWhenEveryEntryMatches(): void
    {
        $alpha = $this->findingAlpha();
        $beta = $this->findingBeta();
        $baseline = Baseline::fromJson(Baseline::generate([$alpha, $beta]));

        self::assertSame([], $baseline->staleEntries([$alpha, $beta]));
    }

    public function testUnknownTopLevelKeyThrowsBaselineException(): void
    {
        try {
            Baseline::fromJson('{"fingerprint": []}');
            self::fail('expected BaselineException');
        } catch (BaselineException $e) {
            self::assertSame('unknown baseline key: fingerprint', $e->getMessage());
        }
    }

    public function testUnknownTopLevelKeyAlongsideFingerprintsThrowsBeforeParsingEntries(): void
    {
        // A document that carries both a valid "fingerprints" key and an
        // unknown one must still reject the unknown key — the key-validation
        // foreach runs before the entries are read, so a bad second key never
        // silently un-gates CI.
        try {
            Baseline::fromJson('{"fingerprints": [], "extra": 1}');
            self::fail('expected BaselineException');
        } catch (BaselineException $e) {
            self::assertSame('unknown baseline key: extra', $e->getMessage());
        }
    }

    public function testNonListFingerprintsThrowsBaselineException(): void
    {
        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('"fingerprints" must be a list of strings, got string');

        Baseline::fromJson('{"fingerprints": "x"}');
    }

    public function testNonStringEntryThrowsBaselineException(): void
    {
        $this->expectException(BaselineException::class);
        $this->expectExceptionMessage('non-string entry at index 0: int');

        Baseline::fromJson('{"fingerprints": [1]}');
    }

    public function testInvalidJsonThrowsBaselineExceptionWithExactMessage(): void
    {
        try {
            Baseline::fromJson('{"fingerprints":');
            self::fail('expected BaselineException');
        } catch (BaselineException $e) {
            self::assertSame('baseline document is not valid JSON: Syntax error', $e->getMessage());
        }
    }
}
