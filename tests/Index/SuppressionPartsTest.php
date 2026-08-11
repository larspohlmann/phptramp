<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpTramp\Index\SuppressionParts;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see SuppressionParts} folds the per-file suppression facts —
 * concatenating methods and params, unioning ignore lines by file — and hands
 * the merged result to a {@see \PhpTramp\Ignore\SuppressionIndex}.
 */
final class SuppressionPartsTest extends TestCase
{
    public function testMergeConcatenatesMethodsAndParamsAndUnionsLinesByFile(): void
    {
        $first = new SuppressionParts(
            ['A::one'],
            [['A::one', 'x']],
            ['a.php' => [3]],
        );
        $second = new SuppressionParts(
            ['B::two'],
            [['B::two', 'y']],
            ['b.php' => [7, 9]],
        );

        $merged = SuppressionParts::merge($first, $second);

        self::assertSame(['A::one', 'B::two'], $merged->methods);
        self::assertSame([['A::one', 'x'], ['B::two', 'y']], $merged->params);
        self::assertSame(['a.php' => [3], 'b.php' => [7, 9]], $merged->lines);
    }

    public function testMergeOfNoPartsIsEmpty(): void
    {
        $merged = SuppressionParts::merge();

        self::assertSame([], $merged->methods);
        self::assertSame([], $merged->params);
        self::assertSame([], $merged->lines);
    }

    public function testToSuppressionIndexExposesEveryMergedPart(): void
    {
        $parts = new SuppressionParts(
            ['A::one'],
            [['A::one', 'x']],
            ['a.php' => [3]],
        );

        $index = $parts->toSuppressionIndex();

        self::assertTrue($index->suppressesMethod('A::one'));
        self::assertTrue($index->suppressesParam('A::one', 'x'));
        self::assertTrue($index->suppressesLine('a.php', 3));
    }
}
