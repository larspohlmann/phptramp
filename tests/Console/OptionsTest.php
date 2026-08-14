<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Console\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $options = new Options();

        self::assertSame([], $options->folders);
        self::assertSame([], $options->files);
        self::assertSame(6, $options->limit);
        self::assertSame(4, $options->warnLimit);
        self::assertSame('pretty', $options->format);
        self::assertSame('auto', $options->colorMode);
        self::assertFalse($options->explain);
        self::assertFalse($options->changedOnly);
        self::assertSame('origin/main', $options->gitBase);
        self::assertNull($options->baseline);
        self::assertNull($options->generateBaseline);
        self::assertFalse($options->dumpIndex);
        self::assertFalse($options->help);
        self::assertFalse($options->version);
        self::assertSame([], $options->excludeTerminals);
    }

    /**
     * Every constructor argument is seeded to a non-default value so a
     * forgotten one in withFormat()'s hand-restated 22-parameter call is
     * caught: reverting the format-only change must reproduce $options
     * exactly, which only holds if every other field survived the round trip.
     */
    public function testWithFormatChangesOnlyTheFormat(): void
    {
        $options = new Options(
            folders: ['src'],
            files: ['a.php'],
            limit: 9,
            warnLimit: 2,
            minClasses: 3,
            format: 'json',
            colorMode: 'never',
            explain: true,
            changedOnly: true,
            gitBase: 'origin/develop',
            diff: 'diff.patch',
            baseline: 'baseline.json',
            generateBaseline: 'generated.json',
            dumpIndex: true,
            noConfig: true,
            help: true,
            version: true,
            failOnStale: true,
            exclude: ['vendor'],
            excludeTerminals: ['stored'],
            noCache: true,
            cacheDir: 'build/cache',
        );

        $downgraded = $options->withFormat('text');

        self::assertSame('text', $downgraded->format);
        self::assertEquals($options, $downgraded->withFormat($options->format));
    }
}
