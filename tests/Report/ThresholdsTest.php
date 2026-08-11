<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Report\Severity;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class ThresholdsTest extends TestCase
{
    public function testSeverityOfIsErrorAtLimit(): void
    {
        $thresholds = new Thresholds(3, null);

        self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHops(3)));
    }

    public function testSeverityOfIsErrorAboveLimit(): void
    {
        $thresholds = new Thresholds(3, null);

        self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHops(5)));
    }

    public function testSeverityOfIsWarningBetweenWarnLimitAndLimit(): void
    {
        $thresholds = new Thresholds(3, 2);

        self::assertSame(Severity::Warning, $thresholds->severityOf($this->findingWithHops(2)));
    }

    public function testSeverityOfIsNullBelowWarnLimit(): void
    {
        $thresholds = new Thresholds(3, 2);

        self::assertNull($thresholds->severityOf($this->findingWithHops(1)));
    }

    public function testSeverityOfIsNullBelowLimitWhenWarnLimitIsNull(): void
    {
        $thresholds = new Thresholds(3, null);

        self::assertNull($thresholds->severityOf($this->findingWithHops(2)));
    }

    public function testConstructorThrowsWhenWarnLimitEqualsLimit(): void
    {
        $this->expectException(InvalidArgsException::class);

        new Thresholds(3, 3);
    }

    public function testConstructorThrowsWhenWarnLimitAboveLimit(): void
    {
        $this->expectException(InvalidArgsException::class);

        new Thresholds(2, 3);
    }

    private function findingWithHops(int $hops): Finding
    {
        return new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, $hops, [], 1, [], []);
    }
}
