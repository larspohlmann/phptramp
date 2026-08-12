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

    public function testMinClassesGatesBothTiersWhenClassesBelowMinimum(): void
    {
        $thresholds = new Thresholds(3, 2, minClasses: 5);

        self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(5, 4)));
    }

    public function testMinClassesBoundaryReportsAtExactlyMinClasses(): void
    {
        $thresholds = new Thresholds(3, 2, minClasses: 4);

        self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHopsAndClasses(3, 4)));
    }

    public function testMinClassesZeroIsOffAndReportsNormally(): void
    {
        $thresholds = new Thresholds(3, null, minClasses: 0);

        self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHopsAndClasses(3, 1)));
    }

    public function testLimitZeroDisablesErrorTierButWarnTierStillFires(): void
    {
        $thresholds = new Thresholds(0, 4);

        self::assertSame(Severity::Warning, $thresholds->severityOf($this->findingWithHopsAndClasses(5, 1)));
    }

    public function testLimitZeroDisablesErrorTierAndExitCodeStaysZero(): void
    {
        $thresholds = new Thresholds(0, null);

        self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(99, 1)));
    }

    public function testWarnLimitZeroIsNormalizedToNullAndDisablesWarnTier(): void
    {
        $thresholds = new Thresholds(6, 0);

        self::assertNull($thresholds->warnLimit);
        self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(4, 1)));
    }

    public function testGuardAllowsLimitZeroWithPositiveWarnLimit(): void
    {
        new Thresholds(0, 4);

        $this->expectNotToPerformAssertions();
    }

    public function testGuardAllowsWarnLimitZeroWithPositiveLimit(): void
    {
        new Thresholds(6, 0);

        $this->expectNotToPerformAssertions();
    }

    public function testGuardStillThrowsWhenBothPositiveAndWarnLimitAtLimit(): void
    {
        $this->expectException(InvalidArgsException::class);

        new Thresholds(6, 6);
    }

    public function testGuardStillThrowsWhenBothPositiveAndWarnLimitAboveLimit(): void
    {
        $this->expectException(InvalidArgsException::class);

        new Thresholds(3, 5);
    }

    private function findingWithHops(int $hops): Finding
    {
        return new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, $hops, [], 1, [], []);
    }

    private function findingWithHopsAndClasses(int $hops, int $classes): Finding
    {
        return new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, $hops, [], $classes, [], []);
    }
}
