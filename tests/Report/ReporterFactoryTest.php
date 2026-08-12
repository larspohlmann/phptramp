<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;
use PhpTramp\Report\ReporterFactory;
use PHPUnit\Framework\TestCase;

/**
 * Drives every wired {@see Options::$format} through the factory end to end.
 * Each case asserts a format-appropriate marker in the rendered output, so a
 * removed or mistyped `match` arm in ReporterFactory::create() (which would
 * fall through to the `default` arm's exception, or route to the wrong
 * reporter) fails here rather than only in each reporter's own unit tests.
 */
final class ReporterFactoryTest extends TestCase
{
    /**
     * @return list<Finding> a single finding at or above every threshold used
     *                       below (limit: 2)
     */
    private function qualifyingFinding(): array
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];

        return [new Finding('p', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 2, $chain, 2, [], [])];
    }

    public function testGithubFormatRoutesToGithubReporter(): void
    {
        $factory = new ReporterFactory('/not/matching');
        $reporter = $factory->create(new Options(format: 'github', limit: 2, warnLimit: 0));

        self::assertStringContainsString('::error', $reporter->render($this->qualifyingFinding()));
    }

    public function testCheckstyleFormatRoutesToCheckstyleReporter(): void
    {
        $factory = new ReporterFactory('/not/matching');
        $reporter = $factory->create(new Options(format: 'checkstyle', limit: 2, warnLimit: 0));

        self::assertStringContainsString('<checkstyle', $reporter->render($this->qualifyingFinding()));
    }

    public function testSarifFormatRoutesToSarifReporter(): void
    {
        $factory = new ReporterFactory('/not/matching');
        $reporter = $factory->create(new Options(format: 'sarif', limit: 2, warnLimit: 0));

        self::assertStringContainsString('"phptramp.trampData"', $reporter->render($this->qualifyingFinding()));
    }

    public function testSummaryFormatRoutesToSummaryReporter(): void
    {
        $factory = new ReporterFactory('/not/matching');
        $reporter = $factory->create(new Options(format: 'summary', limit: 2, warnLimit: 0));

        self::assertStringContainsString('at or over the limit', $reporter->render($this->qualifyingFinding()));
    }

    /**
     * ArgvParser::validateFormat already rejects any format outside the six
     * valid values before Options ever reaches the factory, so this exercises
     * the `default` arm directly — the only way to reach it at all.
     */
    public function testUnroutableFormatThrows(): void
    {
        $factory = new ReporterFactory('/not/matching');

        $this->expectException(InvalidArgsException::class);
        $this->expectExceptionMessage("format 'xml' is not implemented yet.");

        $factory->create(new Options(format: 'xml'));
    }
}
