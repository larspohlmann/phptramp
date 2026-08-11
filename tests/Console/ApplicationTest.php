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
        self::assertSame(0, $this->app->run(['phptramp', '--no-config']));
        self::assertStringContainsString('Usage:', self::contents($this->stdout));
    }

    public function testNoArgumentsAnalyzeWhenConfigSuppliesPaths(): void
    {
        $directory = sys_get_temp_dir() . '/phptramp-cwd-' . uniqid();
        mkdir($directory);
        $this->folders[] = $directory;

        file_put_contents($directory . '/phptramp.json', '{"paths": ["."], "limit": 1}');

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($directory . '/Demo.php', $code);

        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($directory);
            $exitCode = $this->app->run(['phptramp']);
        } finally {
            chdir($previousCwd);
        }

        $output = self::contents($this->stdout);
        self::assertSame(1, $exitCode);
        self::assertStringContainsString('FINDING', $output);
        self::assertStringNotContainsString('Usage:', $output);
    }

    public function testDefaultRunReportsChainsAndExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(1, $this->app->run(['phptramp', '--folder', $folder, '--no-config']));
        self::assertStringContainsString('FINDING', self::contents($this->stdout));
    }

    public function testLimitAboveChainLengthReportsNothingAndExitsZero(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(0, $this->app->run(['phptramp', '--folder', $folder, '--no-config', '--limit', '4']));
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    public function testUnknownFormatExitsWithToolErrorCode(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(2, $this->app->run(['phptramp', '--folder', $folder, '--no-config', '--format', 'xml']));
        self::assertStringContainsString('unknown format: xml', self::contents($this->stderr));
        self::assertSame('', self::contents($this->stdout));
    }

    public function testSummaryFormatReportsAllChainsAndExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(1, $this->app->run(['phptramp', '--folder', $folder, '--no-config', '--format', 'summary']));
        self::assertStringContainsString('at or over the limit', self::contents($this->stdout));
    }

    public function testJsonFormatReportsFindingsAndExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        self::assertSame(1, $this->app->run(['phptramp', '--folder', $folder, '--no-config', '--format', 'json']));

        $output = self::contents($this->stdout);
        self::assertStringContainsString('"findings"', $output);
        self::assertStringContainsString('"severity": "error"', $output);
    }

    public function testWarnOnlyRunExitsZeroButPrintsWarning(): void
    {
        $folder = $this->fixtureWithTwoHopChain();

        self::assertSame(
            0,
            $this->app->run(['phptramp', '--folder', $folder, '--no-config', '--limit', '3', '--warn-limit', '2']),
        );
        self::assertStringContainsString('WARNING', self::contents($this->stdout));
    }

    /**
     * Thresholds validation must happen before the codebase is indexed, so an
     * invalid --warn-limit/--limit combination fails fast: the fixture here is
     * a normal, successfully-parsing 1-hop chain, since fail-fast only changes
     * *when* the error surfaces, not whether this particular folder would
     * parse.
     */
    public function testInvalidWarnLimitFailsFastWithThresholdError(): void
    {
        $folder = $this->fixtureWithOneHopChain();

        $exitCode = $this->app->run(
            ['phptramp', '--folder', $folder, '--no-config', '--limit', '3', '--warn-limit', '5'],
        );

        self::assertSame(2, $exitCode);
        self::assertStringContainsString(
            'warn-limit (5) must be lower than limit (3)',
            self::contents($this->stderr),
        );
    }

    public function testConfigFileLimitAppliesWithoutCliFlag(): void
    {
        $directory = sys_get_temp_dir() . '/phptramp-cwd-' . uniqid();
        mkdir($directory);
        $this->folders[] = $directory;

        file_put_contents($directory . '/phptramp.json', '{"limit": 1}');

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($directory . '/Demo.php', $code);

        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($directory);
            $exitCode = $this->app->run(['phptramp', '--folder', $directory]);
        } finally {
            chdir($previousCwd);
        }

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('FINDING', self::contents($this->stdout));
    }

    public function testNoConfigIgnoresConfigFileInWorkingDirectory(): void
    {
        $directory = sys_get_temp_dir() . '/phptramp-cwd-' . uniqid();
        mkdir($directory);
        $this->folders[] = $directory;

        file_put_contents($directory . '/phptramp.json', '{"limit": 1}');

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($directory . '/Demo.php', $code);

        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($directory);
            $exitCode = $this->app->run(['phptramp', '--folder', $directory, '--no-config']);
        } finally {
            chdir($previousCwd);
        }

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    public function testChangedOnlyWithDiffFileTouchingForwardingLineReportsFindingAndExitsOne(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $diffFile = $this->writeDiffFile($folder, 'touches-hop.diff', $this->diffTouchingHopTwoForwardLine());

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', $diffFile,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('FINDING', self::contents($this->stdout));
    }

    public function testChangedOnlyWithDiffFileTouchingUnrelatedLineReportsNothingAndExitsZero(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $diffFile = $this->writeDiffFile($folder, 'touches-unrelated.diff', $this->diffTouchingUnrelatedLine());

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', $diffFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    public function testDiffDashReadsTheSameDiffFromInjectedStdin(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $stdin = $this->memoryStreamContaining($this->diffTouchingHopTwoForwardLine());
        $app = new Application($this->stdout, $this->stderr, $stdin);

        $exitCode = $this->runAppInFolder($app, $folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', '-',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('FINDING', self::contents($this->stdout));
    }

    public function testUnreadableDiffPathExitsWithToolErrorCode(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $missingDiffFile = $folder . '/does-not-exist.diff';

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', $missingDiffFile,
        ]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('phptramp:', self::contents($this->stderr));
    }

    public function testMalformedDiffTextExitsWithToolErrorCode(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $diffFile = $this->writeDiffFile($folder, 'malformed.diff', "not a diff at all\n");

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', $diffFile,
        ]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('phptramp:', self::contents($this->stderr));
    }

    public function testEmptyDiffFileIsSuccessWithNoFindings(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $diffFile = $this->writeDiffFile($folder, 'empty.diff', '');

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', $diffFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    public function testEmptyStdinDiffIsSuccessWithNoFindings(): void
    {
        $folder = $this->fixtureWithLineControlledThreeHopChain();
        $stdin = $this->memoryStreamContaining('');
        $app = new Application($this->stdout, $this->stderr, $stdin);

        $exitCode = $this->runAppInFolder($app, $folder, [
            'phptramp', '--folder', $folder, '--changed-only', '--diff', '-',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($this->stdout));
    }

    /**
     * @param list<string> $argv
     */
    private function runInFolder(string $folder, array $argv): int
    {
        return $this->runAppInFolder($this->app, $folder, $argv);
    }

    /**
     * @param list<string> $argv
     */
    private function runAppInFolder(Application $app, string $folder, array $argv): int
    {
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($folder);

            return $app->run($argv);
        } finally {
            chdir($previousCwd);
        }
    }

    /** @return resource */
    private function memoryStreamContaining(string $contents)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    private function writeDiffFile(string $folder, string $filename, string $contents): string
    {
        $path = $folder . '/' . $filename;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Unified diff whose only hunk touches Demo.php line 21 — the forwarding
     * call site inside ServiceA::process, i.e. hop 2 of the fixture chain.
     */
    private function diffTouchingHopTwoForwardLine(): string
    {
        return <<<'DIFF'
        diff --git a/Demo.php b/Demo.php
        index 1111111..2222222 100644
        --- a/Demo.php
        +++ b/Demo.php
        @@ -21 +21 @@
        -        (new ServiceB())->run($config);
        +        (new ServiceB())->run($config);

        DIFF;
    }

    /**
     * Unified diff whose only hunk touches Demo.php line 6 — the closing brace
     * of class Cfg, which is not any hop's declaration or forwarding line.
     */
    private function diffTouchingUnrelatedLine(): string
    {
        return <<<'DIFF'
        diff --git a/Demo.php b/Demo.php
        index 1111111..2222222 100644
        --- a/Demo.php
        +++ b/Demo.php
        @@ -6 +6 @@
        -{
        +{

        DIFF;
    }

    /**
     * A PHP file whose exact line numbers are hand-controlled: a 3-hop
     * pure-forward chain (Controller -> ServiceA -> ServiceB -> Mailer) where
     * every declaration and forwarding-call-site line is known, so diff
     * fixtures can target them precisely.
     */
    private function fixtureWithLineControlledThreeHopChain(): string
    {
        $folder = sys_get_temp_dir() . '/phptramp-diff-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;

        $lines = [
            '<?php',
            '',
            'namespace Demo;',
            '',
            'class Cfg',
            '{',
            '}',
            '',
            'class Controller',
            '{',
            '    public function handle(Cfg $config): void',
            '    {',
            '        (new ServiceA())->process($config);',
            '    }',
            '}',
            '',
            'class ServiceA',
            '{',
            '    public function process(Cfg $config): void',
            '    {',
            '        (new ServiceB())->run($config);',
            '    }',
            '}',
            '',
            'class ServiceB',
            '{',
            '    public function run(Cfg $config): void',
            '    {',
            '        new Mailer($config);',
            '    }',
            '}',
            '',
            'class Mailer',
            '{',
            '    private Cfg $c;',
            '',
            '    public function __construct(Cfg $config)',
            '    {',
            '        $this->c = $config;',
            '    }',
            '}',
        ];
        file_put_contents($folder . '/Demo.php', implode("\n", $lines) . "\n");

        return $folder;
    }

    private function fixtureWithOneHopChain(): string
    {
        $folder = sys_get_temp_dir() . '/phptramp-app-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($folder . '/Demo.php', $code);

        return $folder;
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
