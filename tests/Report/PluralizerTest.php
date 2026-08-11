<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\Pluralizer;
use PHPUnit\Framework\TestCase;

final class PluralizerTest extends TestCase
{
    public function testReturnsSingularFormWhenCountIsOne(): void
    {
        self::assertSame('hop', (new Pluralizer())->of(1, 'hop'));
    }

    public function testAppendsDefaultSSuffixWhenCountIsNotOne(): void
    {
        self::assertSame('hops', (new Pluralizer())->of(2, 'hop'));
        self::assertSame('hops', (new Pluralizer())->of(0, 'hop'));
    }

    public function testUsesExplicitIrregularPluralWhenCountIsNotOne(): void
    {
        self::assertSame('classes', (new Pluralizer())->of(4, 'class', 'classes'));
    }

    public function testExplicitPluralIsIgnoredWhenCountIsOne(): void
    {
        self::assertSame('class', (new Pluralizer())->of(1, 'class', 'classes'));
    }
}
