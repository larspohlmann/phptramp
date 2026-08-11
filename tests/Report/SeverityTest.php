<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\Severity;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testErrorLabelIsError(): void
    {
        self::assertSame('error', Severity::Error->label());
    }

    public function testWarningLabelIsWarning(): void
    {
        self::assertSame('warning', Severity::Warning->label());
    }
}
