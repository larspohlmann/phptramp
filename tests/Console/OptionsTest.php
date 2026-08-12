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
    }
}
