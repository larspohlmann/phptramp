<?php

declare(strict_types=1);

namespace PhpTramp\Tests;

use PhpTramp\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixture harness: runs the real pipeline over every tests/fixtures/<case>/src.
 * A case with expected-index.json pins the classifier's `--dump-index` output; a
 * case with expected-findings.json pins the real CLI's `--format json` output
 * (via `Application::run`), byte-for-byte modulo `file` path normalization.
 * Adding a semantic rule means adding a fixture directory, not touching this test.
 *
 * A findings case runs in one of two modes, chosen by what sits next to its
 * expected-findings.json: a `phptramp.json` routes it through config mode
 * (chdir into the fixture, no `--folder`); otherwise it runs in folder mode
 * (`--folder <case>/src`), with CLI args from an optional `phptramp-args.json`
 * or the default `--limit 1`.
 */
final class FixtureTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function fixtureProvider(): iterable
    {
        $pattern = __DIR__ . '/fixtures/*/expected-index.json';
        foreach (glob($pattern) ?: [] as $expectedFile) {
            $caseDir = dirname($expectedFile);
            yield basename($caseDir) => [$caseDir];
        }
    }

    #[DataProvider('fixtureProvider')]
    public function testDumpIndexMatchesExpectation(string $caseDir): void
    {
        $real = realpath($caseDir);
        self::assertIsString($real);

        $expectedJson = file_get_contents($caseDir . '/expected-index.json');
        self::assertIsString($expectedJson);
        $expected = json_decode($expectedJson, true);

        $actual = $this->normalize($this->dumpIndex($caseDir . '/src'), $real);

        self::assertEquals($expected, $actual);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function findingsFixtureProvider(): iterable
    {
        $pattern = __DIR__ . '/fixtures/*/expected-findings.json';
        foreach (glob($pattern) ?: [] as $expectedFile) {
            $caseDir = dirname($expectedFile);
            yield basename($caseDir) => [$caseDir];
        }
    }

    #[DataProvider('findingsFixtureProvider')]
    public function testFindingsMatchExpectation(string $caseDir): void
    {
        $expectedJson = file_get_contents($caseDir . '/expected-findings.json');
        self::assertIsString($expectedJson);
        $expected = json_decode($expectedJson, true);
        self::assertIsArray($expected);

        [$exitCode, $decoded] = $this->runFindingsJson($caseDir);

        self::assertSame($this->expectedExitCode($expected), $exitCode);
        self::assertEquals($expected, $decoded);
    }

    /**
     * A finding at or over `--limit` reports `severity: "error"` and must fail
     * the run; anything else (all-warnings, or no findings) must not.
     *
     * @param array<string, mixed> $expected
     */
    private function expectedExitCode(array $expected): int
    {
        $findings = $expected['findings'] ?? [];
        self::assertIsArray($findings);

        foreach ($findings as $finding) {
            self::assertIsArray($finding);
            if ($finding['severity'] === 'error') {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Routes to config mode (a `phptramp.json` sits in the fixture directory) or
     * folder mode (plain `--folder <case>/src`), each producing an
     * already-normalized findings document ready to compare against
     * expected-findings.json.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runFindingsJson(string $caseDir): array
    {
        if (is_file($caseDir . '/phptramp.json')) {
            return $this->runConfigMode($caseDir);
        }

        return $this->runFolderMode($caseDir);
    }

    /**
     * Folder mode: `--folder <case>/src` plus either the fixture's
     * `phptramp-args.json` extra CLI args or the default `--limit 1`. The
     * reporter relativizes `file` against getcwd() (the repo root at test
     * time), so JSON `file` values arrive repo-relative, e.g.
     * "tests/fixtures/<case>/src/Demo.php" — normalize back to case-relative.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runFolderMode(string $caseDir): array
    {
        $argv = [
            'phptramp', '--folder', $caseDir . '/src', '--no-config', '--format', 'json',
            ...$this->extraCliArgs($caseDir),
        ];
        [$exitCode, $decoded] = $this->runApplication($argv);

        return [$exitCode, $this->normalizeFindingsDocument($decoded, $caseDir)];
    }

    /**
     * Config mode: chdir into the fixture directory so its `phptramp.json`
     * supplies paths/exclude/limit, then run with no `--folder` at all. Always
     * restores the previous cwd — other tests depend on it. Findings' `file`
     * values come out already fixture-relative (cwd = fixture dir at render
     * time), so no prefix-strip is needed.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runConfigMode(string $caseDir): array
    {
        $previousCwd = getcwd();
        self::assertIsString($previousCwd);

        try {
            self::assertTrue(chdir($caseDir));

            return $this->runApplication(['phptramp', '--format', 'json']);
        } finally {
            chdir($previousCwd);
        }
    }

    /**
     * @return list<string>
     */
    private function extraCliArgs(string $caseDir): array
    {
        $argsFile = $caseDir . '/phptramp-args.json';
        if (! is_file($argsFile)) {
            return ['--limit', '1'];
        }

        $json = file_get_contents($argsFile);
        self::assertIsString($json);
        $args = json_decode($json, true);
        self::assertIsArray($args);

        $cliArgs = [];
        foreach ($args as $arg) {
            self::assertIsString($arg);
            $cliArgs[] = $arg;
        }

        return $cliArgs;
    }

    /**
     * @param list<string> $argv
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runApplication(array $argv): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exitCode = (new Application($stdout, $stderr))->run($argv);

        rewind($stdout);
        $json = stream_get_contents($stdout);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return [$exitCode, $decoded];
    }

    /**
     * The reporter relativizes `file` against getcwd() (the repo root at test
     * time), so JSON `file` values arrive repo-relative, e.g.
     * "tests/fixtures/<case>/src/Demo.php". Expected files use case-relative
     * paths, e.g. "src/Demo.php" — strip the case-dir prefix, computed relative
     * to getcwd(), from every chain entry's `file`.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalizeFindingsDocument(array $decoded, string $caseDir): array
    {
        $realCaseDir = realpath($caseDir);
        $cwd = getcwd();
        self::assertIsString($realCaseDir);
        self::assertIsString($cwd);

        $prefix = null;
        if (str_starts_with($realCaseDir, $cwd . '/')) {
            $prefix = substr($realCaseDir, strlen($cwd) + 1) . '/';
        }

        $findings = $decoded['findings'] ?? [];
        self::assertIsArray($findings);
        $decoded['findings'] = array_map(
            fn (array $finding): array => $this->normalizeFindingDocument($finding, $prefix),
            $findings,
        );

        return $decoded;
    }

    /**
     * @param array<string, mixed> $finding
     * @return array<string, mixed>
     */
    private function normalizeFindingDocument(array $finding, ?string $prefix): array
    {
        $chain = $finding['chain'] ?? [];
        self::assertIsArray($chain);

        $finding['chain'] = array_map(
            function (array $hop) use ($prefix): array {
                $file = $hop['file'];
                self::assertIsString($file);
                if ($prefix !== null && str_starts_with($file, $prefix)) {
                    $hop['file'] = substr($file, strlen($prefix));
                }

                return $hop;
            },
            $chain,
        );

        return $finding;
    }

    /**
     * @return array<string, mixed>
     */
    private function dumpIndex(string $srcDir): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exit = (new Application($stdout, $stderr))->run(
            ['phptramp', '--folder', $srcDir, '--no-config', '--dump-index'],
        );
        self::assertSame(0, $exit);

        rewind($stdout);
        $json = stream_get_contents($stdout);
        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function normalize(array $decoded, string $caseDir): array
    {
        $prefix = $caseDir . '/';
        $methods = $decoded['methods'] ?? [];
        self::assertIsArray($methods);

        foreach ($methods as $fqmn => $method) {
            self::assertIsArray($method);
            $file = $method['file'];
            self::assertIsString($file);
            if (str_starts_with($file, $prefix)) {
                $method['file'] = substr($file, strlen($prefix));
            }
            $methods[$fqmn] = $method;
        }
        $decoded['methods'] = $methods;

        return $decoded;
    }
}
