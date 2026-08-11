<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Console\BoolFlags;
use PHPUnit\Framework\TestCase;

final class BoolFlagsTest extends TestCase
{
    public function testEveryFlagDefaultsToFalse(): void
    {
        $flags = new BoolFlags();

        self::assertFalse($flags->explain);
        self::assertFalse($flags->dumpIndex);
        self::assertFalse($flags->noConfig);
        self::assertFalse($flags->help);
        self::assertFalse($flags->version);
        self::assertFalse($flags->failOnStale);
    }
}
