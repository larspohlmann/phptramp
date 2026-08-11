<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Baseline\Baseline;
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

    /** @var list<string> */
    private array $directoriesToRemove = [];

    protected function tearDown(): void
    {
        foreach ($this->folders as $folder) {
            $this->removeTree($folder);
        }
        foreach ($this->directoriesToRemove as $directory) {
            $this->removeTree($directory);
        }
    }

    /**
     * Recursively removes a directory and its contents. Fixture folders now
     * hold a `.phptramp.cache/` subdirectory (the default cache is on), so the
     * cleanup must descend one level rather than only unlink direct children.
     */
    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        // scandir (not glob) so dotfiles like `.phptramp.cache/` are cleared.
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
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

    public function testGenerateBaselineWritesDocumentWithExpectedFingerprintsAndExitsZero(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $folder . '/baseline.json';

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $baselineFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame('', self::contents($this->stdout));
        self::assertStringContainsString(
            'baseline written: ' . $baselineFile . ' (1 findings)' . "\n",
            self::contents($this->stderr),
        );

        $baseline = Baseline::fromJson((string) file_get_contents($baselineFile));
        self::assertCount(1, $baseline->staleEntries([]));
    }

    public function testGenerateBaselineIncludesWarningsWhenWarnLimitIsSet(): void
    {
        $folder = $this->fixtureWithTwoHopChain();
        $baselineFile = $folder . '/baseline.json';

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config',
            '--limit', '3', '--warn-limit', '2',
            '--generate-baseline', $baselineFile,
        ]);

        self::assertSame(0, $exitCode);
        $baseline = Baseline::fromJson((string) file_get_contents($baselineFile));
        self::assertCount(1, $baseline->staleEntries([]));
    }

    public function testGenerateBaselineOnUnwritableTargetExitsTwoWithStderrMessage(): void
    {
        $folder = $this->fixtureWithThreeHopChain();

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $folder,
        ]);

        self::assertSame(2, $exitCode);
        self::assertSame(
            'phptramp: unable to write baseline to ' . $folder . "\n",
            self::contents($this->stderr),
        );
        self::assertSame('', self::contents($this->stdout));
    }

    public function testGenerateBaselineIgnoresConsumeBaselineFlagWithStderrNote(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $folder . '/baseline.json';

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config',
            '--baseline', $folder . '/old-baseline.json',
            '--generate-baseline', $baselineFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString(
            '--baseline ignored while generating a new baseline' . "\n",
            self::contents($this->stderr),
        );
        self::assertFileExists($baselineFile);
    }

    public function testBaselineConsumesRoundTripGeneratedFromSameTreeExitsZero(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $folder . '/baseline.json';

        $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $baselineFile,
        ]);

        [$stdout, $stderr] = $this->freshStreams();
        $consume = new Application($stdout, $stderr);

        $exitCode = $consume->run([
            'phptramp', '--folder', $folder, '--no-config', '--baseline', $baselineFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($stdout));
    }

    public function testBaselineConsumesKnownFindingButReportsNewChainExitsOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $folder . '/baseline.json';

        $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $baselineFile,
        ]);

        file_put_contents($folder . '/NewDemo.php', $this->newThreeHopChainCode());

        [$stdout, $stderr] = $this->freshStreams();
        $consume = new Application($stdout, $stderr);

        $exitCode = $consume->run([
            'phptramp', '--folder', $folder, '--no-config', '--baseline', $baselineFile,
        ]);

        self::assertSame(1, $exitCode);
        $output = self::contents($stdout);
        self::assertStringContainsString('NewController', $output);
        self::assertStringNotContainsString('Demo\Controller::handle', $output);
    }

    public function testBaselineConsumptionWithCorruptBaselineFileExitsTwo(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $folder . '/baseline.json';
        file_put_contents($baselineFile, '{"fingerprint": []}');

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--baseline', $baselineFile,
        ]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('phptramp:', self::contents($this->stderr));
        self::assertSame('', self::contents($this->stdout));
    }

    public function testBaselineConsumptionWithUnreadableBaselineFileExitsTwo(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $missingBaselineFile = $folder . '/does-not-exist.json';

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--baseline', $missingBaselineFile,
        ]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('phptramp:', self::contents($this->stderr));
        self::assertSame('', self::contents($this->stdout));
    }

    public function testConfigFileBaselineKeyAppliesWithoutCliFlag(): void
    {
        $directory = sys_get_temp_dir() . '/phptramp-cwd-' . uniqid();
        mkdir($directory);
        $this->folders[] = $directory;

        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { (new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
        file_put_contents($directory . '/Demo.php', $code);

        [$genStdout, $genStderr] = $this->freshStreams();
        $generate = new Application($genStdout, $genStderr);
        $generate->run([
            'phptramp', '--folder', $directory, '--no-config',
            '--generate-baseline', $directory . '/baseline.json',
        ]);

        file_put_contents($directory . '/phptramp.json', '{"baseline": "baseline.json"}');

        [$stdout, $stderr] = $this->freshStreams();
        $consume = new Application($stdout, $stderr);
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($directory);
            $exitCode = $consume->run(['phptramp', '--folder', $directory]);
        } finally {
            chdir($previousCwd);
        }

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No tramp data found', self::contents($stdout));
    }

    public function testStaleBaselineEntryPrintedToStderrAndExitUnchanged(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $this->baselineWithOneRealAndOneFabricatedEntry($folder);

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--baseline', $baselineFile,
        ]);

        self::assertSame(0, $exitCode);
        $stderr = self::contents($this->stderr);
        self::assertStringEndsWith(
            "phptramp: stale baseline entry: 0000000000000000000000000000000000000000 fabricated stale entry\n",
            $stderr,
        );
        self::assertSame([], $this->linesStartingWith($stderr, 'phptramp: stale suppression:'));
    }

    public function testStaleBaselineEntryWithFailOnStaleFlipsExitToOne(): void
    {
        $folder = $this->fixtureWithThreeHopChain();
        $baselineFile = $this->baselineWithOneRealAndOneFabricatedEntry($folder);

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config',
            '--baseline', $baselineFile, '--fail-on-stale',
        ]);

        self::assertSame(1, $exitCode);
    }

    public function testUnusedTrampIgnorePrintsStaleSuppressionLineAndExitUnchanged(): void
    {
        $folder = $this->fixtureWithChainAndUnusedIgnore();

        $exitCode = $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--limit', '3',
        ]);

        // The real chain is reported at limit 3, so the base exit is 1; the
        // stale suppression line does not change it.
        self::assertSame(1, $exitCode);
        $stderr = self::contents($this->stderr);
        $suppressionLines = $this->linesStartingWith($stderr, 'phptramp: stale suppression:');
        self::assertCount(1, $suppressionLines);
        self::assertStringContainsString(
            'method:Demo\Unused::neverCalled',
            $suppressionLines[0],
        );
        self::assertSame([], $this->linesStartingWith($stderr, 'phptramp: stale baseline entry:'));
    }

    public function testChangedOnlySkipsStaleDetectionEntirely(): void
    {
        $folder = $this->fixtureWithChainAndUnusedIgnore();
        $diffFile = $this->writeDiffFile($folder, 'empty.diff', '');

        $exitCode = $this->runInFolder($folder, [
            'phptramp', '--folder', $folder, '--no-config', '--limit', '3',
            '--changed-only', '--diff', $diffFile, '--fail-on-stale',
        ]);

        self::assertSame(0, $exitCode);
        $stderr = self::contents($this->stderr);
        self::assertSame([], $this->linesStartingWith($stderr, 'phptramp: stale'));
    }

    /**
     * Two consecutive runs against the same cache dir must produce
     * byte-identical stdout (cache transparency) and leave one `.cache` entry
     * per indexed source file. The cache dir lives outside the analyzed cwd
     * so the file locator never sees it.
     */
    public function testTwoRunsShareCacheAndProduceByteIdenticalStdoutWithOneEntryPerSourceFile(): void
    {
        [$cwd, $cacheDir] = $this->cacheableCwdWithThreeHopChain();

        $firstStdout = $this->runInCwd($cwd);
        self::assertStringContainsString('FINDING', $firstStdout);

        $secondStdout = $this->runInCwd($cwd);

        self::assertSame($firstStdout, $secondStdout);
        self::assertCount(1, glob($cacheDir . '/*.cache') ?: []);
    }

    /**
     * Editing a source file between runs invalidates its cache entry by
     * mtime/size, so the re-parse surfaces the new chain. Proves the cache
     * never masks a real change.
     */
    public function testEditedSourceFileInvalidatesCacheAndSurfacesNewFinding(): void
    {
        [$cwd, $cacheDir] = $this->cacheableCwdWithOneHopChain();

        $firstStdout = $this->runInCwd($cwd);
        self::assertStringContainsString('No tramp data found', $firstStdout);

        file_put_contents($cwd . '/Demo.php', $this->threeHopChainCode());

        $secondStdout = $this->runInCwd($cwd);
        self::assertStringContainsString('FINDING', $secondStdout);
    }

    /**
     * --no-cache disables the cache entirely: even with a configured cache dir,
     * no cache directory is ever created on disk.
     */
    public function testNoCacheFlagLeavesCacheDirAbsent(): void
    {
        $folder = sys_get_temp_dir() . '/phptramp-cache-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;
        file_put_contents($folder . '/Demo.php', $this->threeHopChainCode());

        $cacheDir = $folder . '/.phptramp.cache';

        self::assertSame(1, $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--no-cache',
        ]));
        self::assertDirectoryDoesNotExist($cacheDir);
    }

    /**
     * Builds a temp cwd containing a 3-hop chain Demo.php plus a phptramp.json
     * that points the cache at a separate temp dir, and returns both paths.
     *
     * @return array{0: string, 1: string} [cwd, cacheDir]
     */
    private function cacheableCwdWithThreeHopChain(): array
    {
        return $this->cacheableCwd($this->threeHopChainCode());
    }

    /** @return array{0: string, 1: string} [cwd, cacheDir] */
    private function cacheableCwdWithOneHopChain(): array
    {
        $oneHop = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';

        return $this->cacheableCwd($oneHop);
    }

    /**
     * @return array{0: string, 1: string} [cwd, cacheDir]
     */
    private function cacheableCwd(string $sourceCode): array
    {
        $cwd = sys_get_temp_dir() . '/phptramp-cwd-' . uniqid();
        $cacheDir = sys_get_temp_dir() . '/phptramp-cache-' . uniqid();
        mkdir($cwd);
        $this->folders[] = $cwd;
        $this->directoriesToRemove[] = $cacheDir;

        file_put_contents($cwd . '/Demo.php', $sourceCode);
        file_put_contents(
            $cwd . '/phptramp.json',
            '{"paths": ["."], "cache": ' . json_encode($cacheDir) . '}',
        );

        return [$cwd, $cacheDir];
    }

    /**
     * Runs a fresh Application with cwd set to $cwd and returns its stdout.
     */
    private function runInCwd(string $cwd): string
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $app = new Application($stdout, $stderr);
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            chdir($cwd);
            $app->run(['phptramp']);
        } finally {
            chdir($previousCwd);
        }

        return self::contents($stdout);
    }

    private function threeHopChainCode(): string
    {
        return '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { (new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } }';
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

    /** @return array{0: resource, 1: resource} */
    private function freshStreams(): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        return [$stdout, $stderr];
    }

    private function newThreeHopChainCode(): string
    {
        return '<?php namespace Demo; class NewCfg {} '
            . 'class NewController { public function handle(NewCfg $config): void '
            . '{ (new NewServiceA())->process($config); } } '
            . 'class NewServiceA { public function process(NewCfg $config): void '
            . '{ (new NewServiceB())->run($config); } } '
            . 'class NewServiceB { public function run(NewCfg $config): void '
            . '{ new NewMailer($config); } } '
            . 'class NewMailer { private NewCfg $c; '
            . 'public function __construct(NewCfg $config) { $this->c = $config; } }';
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

    /**
     * A real 3-hop chain plus an unused #[TrampIgnore] method that no chain
     * passes through — its method-key never fires, so it is a stale
     * suppression on a full run.
     */
    private function fixtureWithChainAndUnusedIgnore(): string
    {
        $folder = sys_get_temp_dir() . '/phptramp-app-' . uniqid();
        mkdir($folder);
        $this->folders[] = $folder;

        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { (new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; public function __construct(Cfg $config) { $this->c = $config; } } '
            . 'class Unused { #[TrampIgnore] public function neverCalled(Cfg $p): void { $p->x(); } }';
        file_put_contents($folder . '/Demo.php', $code);

        return $folder;
    }

    /**
     * Generate the real baseline for the folder's single chain, then append a
     * fabricated entry whose hash matches no finding — it is the stale one.
     */
    private function baselineWithOneRealAndOneFabricatedEntry(string $folder): string
    {
        $baselineFile = $folder . '/baseline.json';

        $this->app->run([
            'phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $baselineFile,
        ]);

        $document = json_decode((string) file_get_contents($baselineFile), true);
        self::assertIsArray($document);
        self::assertArrayHasKey('fingerprints', $document);
        $document['fingerprints'][] = '0000000000000000000000000000000000000000 fabricated stale entry';
        file_put_contents(
            $baselineFile,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return $baselineFile;
    }

    /**
     * @return list<string>
     */
    private function linesStartingWith(string $haystack, string $prefix): array
    {
        $matched = [];
        foreach (explode("\n", $haystack) as $line) {
            if (str_starts_with($line, $prefix)) {
                $matched[] = $line;
            }
        }

        return $matched;
    }
}
