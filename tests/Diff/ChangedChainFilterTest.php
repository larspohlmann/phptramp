<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Diff;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Diff\ChangedChainFilter;
use PhpTramp\Diff\ChangedLines;
use PHPUnit\Framework\TestCase;

final class ChangedChainFilterTest extends TestCase
{
    /** @return list<Hop> */
    private function threeHopChain(): array
    {
        return [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];
    }

    private function findingWithChain(array $chain): Finding
    {
        return new Finding(
            'config',
            'Demo\A::go',
            'Demo\C::sink',
            TerminalKind::Used,
            2,
            $chain,
            3,
            ['a note'],
            ['a trace line'],
        );
    }

    public function testMiddleHopForwardLineInDiffKeepsFindingAndMarksOnlyThatHop(): void
    {
        $finding = $this->findingWithChain($this->threeHopChain());
        $filter = new ChangedChainFilter(new ChangedLines(['src/B.php' => [11]]));

        $kept = $filter->filter([$finding]);

        self::assertCount(1, $kept);
        $chain = $kept[0]->chain;
        self::assertFalse($chain[0]->changed);
        self::assertTrue($chain[1]->changed);
        self::assertFalse($chain[2]->changed);
    }

    public function testHopDeclarationLineInDiffKeepsFindingAndMarksThatHop(): void
    {
        $finding = $this->findingWithChain($this->threeHopChain());
        $filter = new ChangedChainFilter(new ChangedLines(['src/A.php' => [5]]));

        $kept = $filter->filter([$finding]);

        self::assertCount(1, $kept);
        $chain = $kept[0]->chain;
        self::assertTrue($chain[0]->changed);
        self::assertFalse($chain[1]->changed);
        self::assertFalse($chain[2]->changed);
    }

    public function testOnlyTerminalLineInDiffDropsFinding(): void
    {
        $finding = $this->findingWithChain($this->threeHopChain());
        $filter = new ChangedChainFilter(new ChangedLines(['src/C.php' => [13]]));

        $kept = $filter->filter([$finding]);

        self::assertSame([], $kept);
    }

    public function testNoIntersectionDropsFinding(): void
    {
        $finding = $this->findingWithChain($this->threeHopChain());
        $filter = new ChangedChainFilter(new ChangedLines(['src/Z.php' => [1]]));

        $kept = $filter->filter([$finding]);

        self::assertSame([], $kept);
    }

    public function testEmptyChangedLinesDropsEveryFinding(): void
    {
        $finding = $this->findingWithChain($this->threeHopChain());
        $filter = new ChangedChainFilter(new ChangedLines([]));

        $kept = $filter->filter([$finding]);

        self::assertSame([], $kept);
    }

    public function testKeptFindingPreservesOrderAndEveryNonHopField(): void
    {
        $originalChain = $this->threeHopChain();
        $finding = $this->findingWithChain($originalChain);
        $filter = new ChangedChainFilter(new ChangedLines(['src/A.php' => [5]]));

        $kept = $filter->filter([$finding]);

        self::assertCount(1, $kept);
        $rebuilt = $kept[0];
        self::assertSame($finding->param, $rebuilt->param);
        self::assertSame($finding->origin, $rebuilt->origin);
        self::assertSame($finding->terminal, $rebuilt->terminal);
        self::assertSame($finding->terminalKind, $rebuilt->terminalKind);
        self::assertSame($finding->hops, $rebuilt->hops);
        self::assertSame($finding->classes, $rebuilt->classes);
        self::assertSame($finding->notes, $rebuilt->notes);
        self::assertSame($finding->trace, $rebuilt->trace);

        self::assertCount(3, $rebuilt->chain);
        self::assertSame('Demo\A::go', $rebuilt->chain[0]->fqmn);
        self::assertSame('Demo\B::step', $rebuilt->chain[1]->fqmn);
        self::assertSame('Demo\C::sink', $rebuilt->chain[2]->fqmn);

        // The original Finding/Hops must be untouched — the filter rebuilds
        // rather than mutates.
        self::assertFalse($originalChain[0]->changed);
        self::assertNotSame($originalChain[0], $rebuilt->chain[0]);
    }

    public function testKeepsFindingsThatMatchAndDropsThoseThatDoNotAmongSeveral(): void
    {
        $keptFinding = $this->findingWithChain($this->threeHopChain());
        $droppedFinding = $this->findingWithChain([
            new Hop('Demo\X::go', 'Demo\X', 'src/X.php', 1, 2),
            new Hop('Demo\Y::sink', 'Demo\Y', 'src/Y.php', 3, null),
        ]);
        $filter = new ChangedChainFilter(new ChangedLines(['src/A.php' => [5]]));

        $result = $filter->filter([$droppedFinding, $keptFinding]);

        self::assertCount(1, $result);
        self::assertSame('Demo\A::go', $result[0]->chain[0]->fqmn);
        self::assertTrue($result[0]->chain[0]->changed);
    }
}
