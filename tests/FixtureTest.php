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

        $expectedExitCode = $expected['findings'] === [] ? 0 : 1;
        self::assertSame($expectedExitCode, $exitCode);
        self::assertEquals($expected, $this->normalizeFindingsDocument($decoded, $caseDir));
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runFindingsJson(string $caseDir): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $argv = ['phptramp', '--folder', $caseDir . '/src', '--format', 'json', '--limit', '1'];
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

        $exit = (new Application($stdout, $stderr))->run(['phptramp', '--folder', $srcDir, '--dump-index']);
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
