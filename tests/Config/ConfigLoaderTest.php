<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Config;

use PhpTramp\Config\ConfigException;
use PhpTramp\Config\ConfigLoader;
use PhpTramp\Console\Options;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/phptramp-config-' . uniqid();
        mkdir($directory);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    private function writeConfig(string $filename, string $contents): void
    {
        file_put_contents($this->directory . '/' . $filename, $contents);
    }

    public function testMissingBothFilesReturnsPlainDefaults(): void
    {
        $options = (new ConfigLoader())->load($this->directory);

        self::assertEquals(new Options(), $options);
    }

    public function testReadsPhptrampJson(): void
    {
        $this->writeConfig('phptramp.json', '{"limit": 5}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(5, $options->limit);
    }

    public function testFallsBackToDistFileWhenPrimaryAbsent(): void
    {
        $this->writeConfig('phptramp.dist.json', '{"limit": 7}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(7, $options->limit);
    }

    public function testPrefersNonDistFileWhenBothExist(): void
    {
        $this->writeConfig('phptramp.json', '{"limit": 5}');
        $this->writeConfig('phptramp.dist.json', '{"limit": 7}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(5, $options->limit);
    }

    public function testUnknownKeyThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"nope": true}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown config key: nope');

        (new ConfigLoader())->load($this->directory);
    }

    public function testPathsSplitsPhpEntriesIntoFilesAndRestIntoFolders(): void
    {
        $this->writeConfig('phptramp.json', '{"paths": ["src", "a.php", "tests", "b.php"]}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(['src', 'tests'], $options->folders);
        self::assertSame(['a.php', 'b.php'], $options->files);
    }

    public function testPathsMustBeAList(): void
    {
        $this->writeConfig('phptramp.json', '{"paths": "src"}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testPathsWithNonStringEntryThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"paths": ["src", 3]}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testWrongTypeForLimitThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"limit": "three"}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testWrongTypeForWarnLimitThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"warnLimit": "two"}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testWrongTypeForFormatThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"format": 3}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testWrongTypeForBaselineThrows(): void
    {
        $this->writeConfig('phptramp.json', '{"baseline": 3}');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testMalformedJsonThrows(): void
    {
        $this->writeConfig('phptramp.json', '{not valid json');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testTopLevelJsonArrayThrows(): void
    {
        $this->writeConfig('phptramp.json', '[1, 2, 3]');

        $this->expectException(ConfigException::class);

        (new ConfigLoader())->load($this->directory);
    }

    public function testExcludeKeyIsAllowedButNotYetMapped(): void
    {
        $this->writeConfig('phptramp.json', '{"exclude": ["vendor/*"]}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertEquals(new Options(), $options);
    }

    public function testFormatAndBaselineAreMapped(): void
    {
        $this->writeConfig('phptramp.json', '{"format": "json", "baseline": "baseline.json"}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame('json', $options->format);
        self::assertSame('baseline.json', $options->baseline);
    }

    public function testWarnLimitIsMapped(): void
    {
        $this->writeConfig('phptramp.json', '{"warnLimit": 2, "limit": 3}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(2, $options->warnLimit);
    }
}
