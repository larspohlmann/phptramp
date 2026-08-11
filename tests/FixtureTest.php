<?php

declare(strict_types=1);

namespace PhpTramp\Tests;

use PhpTramp\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixture harness: runs the real CLI pipeline over every
 * tests/fixtures/<case>/src and compares its normalized `--dump-index` output
 * against the case's expected-index.json. Adding a semantic rule means adding a
 * fixture directory, not touching this test.
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
