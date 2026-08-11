<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Ignore;

use PhpTramp\Ignore\SuppressionIndex;
use PHPUnit\Framework\TestCase;

final class SuppressionIndexTest extends TestCase
{
    public function testSuppressesMethodForListedFqmn(): void
    {
        $index = new SuppressionIndex(['Demo\A::go'], [], []);

        self::assertTrue($index->suppressesMethod('Demo\A::go'));
        self::assertFalse($index->suppressesMethod('Demo\A::other'));
    }

    public function testSuppressesParamForExactFqmnAndParamPair(): void
    {
        $index = new SuppressionIndex([], [['Demo\A::go', 'config']], []);

        self::assertTrue($index->suppressesParam('Demo\A::go', 'config'));
        self::assertFalse($index->suppressesParam('Demo\A::go', 'other'));
        self::assertFalse($index->suppressesParam('Demo\A::other', 'config'));
    }

    public function testSuppressesLineForExactFileAndLine(): void
    {
        $index = new SuppressionIndex([], [], ['/path/to/File.php' => [10, 12]]);

        self::assertTrue($index->suppressesLine('/path/to/File.php', 10));
        self::assertTrue($index->suppressesLine('/path/to/File.php', 12));
        self::assertFalse($index->suppressesLine('/path/to/File.php', 11));
        self::assertFalse($index->suppressesLine('/other/File.php', 10));
    }

    public function testEmptyIndexSuppressesNothing(): void
    {
        $index = new SuppressionIndex([], [], []);

        self::assertFalse($index->suppressesMethod('Demo\A::go'));
        self::assertFalse($index->suppressesParam('Demo\A::go', 'config'));
        self::assertFalse($index->suppressesLine('/path/to/File.php', 1));
    }
}
