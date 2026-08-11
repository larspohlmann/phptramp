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

    /** @var list<string> */
    private array $folders = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            array_map('unlink', glob($folder . '/*') ?: []);
            if (is_dir($folder)) {
                rmdir($folder);
            }
        }
    }

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
        $documented = [
            '--folder', '--file', '--files', '--limit',
            '--format', '--changed-only', '--baseline', 'Exit codes',
        ];
        foreach ($documented as $expected) {
            self::assertStringContainsString($expected, $help);
        }
    }

    public function testNoArgumentsPrintsHelpAndExitsZero(): void
    {
        self::assertSame(0, $this->app->run(['phptramp']));
        self::assertStringContainsString('Usage:', self::contents($this->stdout));
    }

    public function testDefaultRunReportsChainsAndExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(1, $this->app->run(['phptramp', '--folder', $folder]));
        self::assertStringContainsString('FINDING', self::contents($this->stdout));
    }

    public function testLimitAboveChainLengthReportsNothingAndExitsZero(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(0, $this->app->run(['phptramp', '--folder', $folder, '--limit', '4']));
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    public function testUnsupportedFormatExitsWithToolErrorCode(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(2, $this->app->run(['phptramp', '--folder', $folder, '--format', 'sarif']));
        self::assertStringContainsString('not implemented', self::contents($this->stderr));
        self::assertSame('', self::contents($this->stdout));
    }

    public function testJsonFormatReportsFindingsAndExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(1, $this->app->run(['phptramp', '--folder', $folder, '--format', 'json']));

        $output = self::contents($this->stdout);
        self::assertStringContainsString('"findings"', $output);
        self::assertStringContainsString('"severity": "error"', $output);
    }

    public function testWarnOnlyRunExitsZeroButPrintsWarning(): void
    {
        $folder = $this->fixtureWithTwoHopChain();

        self::assertSame(
            0,
            $this->app->run(['phptramp', '--folder', $folder, '--limit', '3', '--warn-limit', '2']),
        );
        self::assertStringContainsString('WARNING', self::contents($this->stdout));
    }

    private function fixtureWithTwoHopChain(): string
    {
        $folder = sys_get_temp_dir() . '/phptramp-app-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($folder . '/Demo.php', $code);

        return $folder;
    }

    private function fixtureWithThreeHopChain(): string
    {
        $folder = sys_get_temp_dir() . '/phptramp-app-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { (new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($folder . '/Demo.php', $code);

        return $folder;
    }
}
