<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Console\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private Application $app;

    protected function setUp(): void
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->app = new Application($stdout, $stderr);
    }

    /**
     * @param resource $stream
     */
    private static function contents($stream): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertIsString($contents);

        return $contents;
    }

    public function testVersionPrintsNameAndVersionAndExitsZero(): void
    {
        self::assertSame(0, $this->app->run(['phptramp', '--version']));
        self::assertStringContainsString(Application::NAME, self::contents($this->stdout));
        self::assertStringContainsString(Application::VERSION, self::contents($this->stdout));
    }

    public function testHelpDocumentsTheCliContract(): void
    {
        self::assertSame(0, $this->app->run(['phptramp', '--help']));

        $help = self::contents($this->stdout);
        foreach (['--folder', '--file', '--files', '--limit', '--format', '--changed-only', '--baseline', 'Exit codes'] as $expected) {
            self::assertStringContainsString($expected, $help);
        }
    }

    public function testNoArgumentsPrintsHelpAndExitsZero(): void
    {
        self::assertSame(0, $this->app->run(['phptramp']));
        self::assertStringContainsString('Usage:', self::contents($this->stdout));
    }

    public function testUnimplementedInvocationExitsWithToolErrorCode(): void
    {
        self::assertSame(2, $this->app->run(['phptramp', '--folder', 'src']));
        self::assertStringContainsString('not implemented', self::contents($this->stderr));
        self::assertSame('', self::contents($this->stdout));
    }
}
