<?php

declare(strict_types=1);

namespace PhpTramp\Tests;

use PhpTramp\Chain\ChainBuilder;
use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Console\Application;
use PhpTramp\Console\Options;
use PhpTramp\Discovery\FileLocator;
use PhpTramp\Index\Indexer;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ClassHierarchy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixture harness: runs the real pipeline over every tests/fixtures/<case>/src.
 * A case with expected-index.json pins the classifier's `--dump-index` output; a
 * case with expected-findings.json pins the chain builder's findings (chain
 * flattened to FQMNs — the CLI JSON format arrives in Phase 3). Adding a semantic
 * rule means adding a fixture directory, not touching this test.
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

        self::assertEquals($expected, $this->findings($caseDir . '/src'));
    }

    /**
     * @return array{findings: list<array<string, mixed>>}
     */
    private function findings(string $srcDir): array
    {
        $files = (new FileLocator())->locate(new Options(folders: [$srcDir]));
        $index = (new Indexer())->index($files);
        $resolver = new CallResolver($index, new ClassHierarchy($index));
        $findings = (new ChainBuilder($resolver))->build($index);

        return ['findings' => array_map($this->normalizeFinding(...), $findings)];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFinding(Finding $finding): array
    {
        return [
            'param' => $finding->param,
            'origin' => $finding->origin,
            'terminal' => $finding->terminal,
            'terminalKind' => $finding->terminalKind->value,
            'hops' => $finding->hops,
            'chain' => array_map(static fn (Hop $hop): string => $hop->fqmn, $finding->chain),
            'classes' => $finding->classes,
            'notes' => $finding->notes,
        ];
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
