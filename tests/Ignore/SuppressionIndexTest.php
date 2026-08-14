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

    public function testSuppressesParamForMultipleDistinctPairsIndependently(): void
    {
        $index = new SuppressionIndex([], [
            ['Demo\A::go', 'config'],
            ['Demo\B::step', 'other'],
        ], []);

        self::assertTrue($index->suppressesParam('Demo\A::go', 'config'));
        self::assertTrue($index->suppressesParam('Demo\B::step', 'other'));
        self::assertFalse($index->suppressesParam('Demo\B::step', 'config'));
    }

    public function testSuppressesLineAcrossMultipleFilesIndependently(): void
    {
        $index = new SuppressionIndex([], [], [
            '/path/to/A.php' => [10],
            '/path/to/B.php' => [20],
        ]);

        self::assertTrue($index->suppressesLine('/path/to/A.php', 10));
        self::assertTrue($index->suppressesLine('/path/to/B.php', 20));
        self::assertFalse($index->suppressesLine('/path/to/B.php', 10));
    }

    public function testEmptyIndexSuppressesNothing(): void
    {
        $index = new SuppressionIndex([], [], []);

        self::assertFalse($index->suppressesMethod('Demo\A::go'));
        self::assertFalse($index->suppressesParam('Demo\A::go', 'config'));
        self::assertFalse($index->suppressesLine('/path/to/File.php', 1));
    }

    public function testMethodKeyIsLiteralMethodPrefixFollowedByFqmn(): void
    {
        self::assertSame(
            'method:Demo\ServiceA::process',
            SuppressionIndex::methodKey('Demo\ServiceA::process'),
        );
    }

    public function testParamKeyIsLiteralParamPrefixFqmnAndParam(): void
    {
        self::assertSame(
            'param:Demo\A::go::$config',
            SuppressionIndex::paramKey('Demo\A::go', 'config'),
        );
    }

    public function testLineKeyIsLiteralLinePrefixFileAndLine(): void
    {
        self::assertSame(
            'line:/path/to/File.php:42',
            SuppressionIndex::lineKey('/path/to/File.php', 42),
        );
    }

    public function testKeysReturnsPublicFormatKeysInConfigurationOrder(): void
    {
        $index = new SuppressionIndex(
            ['Demo\A::go'],
            [['Demo\A::go', 'config']],
            ['/path/to/File.php' => [10, 12]],
        );

        self::assertSame([
            'method:Demo\A::go',
            'param:Demo\A::go::$config',
            'line:/path/to/File.php:10',
            'line:/path/to/File.php:12',
        ], $index->keys());
    }
}
