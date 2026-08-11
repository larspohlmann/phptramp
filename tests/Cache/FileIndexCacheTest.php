<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Cache;

use PhpTramp\Cache\FileIndexCache;
use PhpTramp\Console\Application;
use PhpTramp\Index\FileIndex;
use PhpTramp\Index\FileIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see FileIndexCache} round-trips a {@see FileIndex} and that
 * every failure path — absent entry, mtime/size drift, corrupt payload, format
 * mismatch, unwritable directory — degrades to a silent miss rather than an
 * exception. Transparency is the cache's central contract: a corrupt or stale
 * entry must never change findings.
 */
final class FileIndexCacheTest extends TestCase
{
    private const SAMPLE = <<<'PHP'
        <?php

        namespace Demo;

        class Service
        {
            public function handle(string $unused): void
            {
            }
        }
        PHP;

    /** @var list<string> */
    private array $directories = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach ($this->directories as $directory) {
            if (is_dir($directory)) {
                @chmod($directory, 0o755);
                $this->removeTree($directory);
            }
        }
    }

    public function testPutThenGetRoundTripsAnEqualFileIndex(): void
    {
        $cache = new FileIndexCache($this->cacheDirectory());
        $file = $this->sourceFile(self::SAMPLE);
        $index = (new FileIndexer())->index($file);

        $cache->put($file, $index);
        $hit = $cache->get($file);

        self::assertNotNull($hit);
        self::assertEquals($index, $hit);
    }

    public function testGetOnNeverPutFileReturnsNull(): void
    {
        $cache = new FileIndexCache($this->cacheDirectory());
        $file = $this->sourceFile(self::SAMPLE);

        self::assertNull($cache->get($file));
    }

    public function testGetReturnsNullWhenSourceMtimeAdvances(): void
    {
        $cache = new FileIndexCache($this->cacheDirectory());
        $file = $this->sourceFile(self::SAMPLE);
        $index = (new FileIndexer())->index($file);

        $cache->put($file, $index);

        touch($file, time() + 10);

        self::assertNull($cache->get($file));
    }

    public function testGetReturnsNullWhenSourceSizeChanges(): void
    {
        $cache = new FileIndexCache($this->cacheDirectory());
        $file = $this->sourceFile(self::SAMPLE);
        $index = (new FileIndexer())->index($file);

        $cache->put($file, $index);

        // Capture to the second — stat() records integer mtime, so sub-second
        // drift is irrelevant; restoring to the exact cached second neutralizes
        // mtime so the size check is the only differing field.
        $originalMtime = filemtime($file);

        file_put_contents($file, self::SAMPLE . "\n// appended\n");
        touch($file, $originalMtime);

        // Restoring mtime to the second the file already carries is a no-op
        // touch, which on some platforms (PHP 8.2) does not flush PHP's stat
        // cache — so the stat here would report the pre-write size. Flush it so
        // get() sees the grown file, exactly as a fresh process would.
        clearstatcache();

        self::assertNull(
            $cache->get($file),
            'size mismatch alone (mtime restored to cached value) must invalidate the entry',
        );
    }

    public function testGetReturnsNullOnCorruptPayload(): void
    {
        $directory = $this->cacheDirectory();
        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);

        $cache->put($file, (new FileIndexer())->index($file));

        $entryPath = $directory . '/' . sha1(realpath($file)) . '.cache';
        file_put_contents($entryPath, 'this is not serialized payload');

        self::assertNull($cache->get($file));
    }

    public function testGetReturnsNullOnFormatMismatch(): void
    {
        $directory = $this->cacheDirectory();
        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);

        $realpath = realpath($file);
        $stat = stat($file);
        $payload = serialize([
            'format' => 0,
            'version' => Application::VERSION,
            'path' => $realpath,
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'index' => (new FileIndexer())->index($file),
        ]);

        $entryPath = $directory . '/' . sha1($realpath) . '.cache';
        file_put_contents($entryPath, $payload);

        self::assertNull($cache->get($file));
    }

    public function testPutIntoReadOnlyDirectoryDoesNotThrow(): void
    {
        $directory = $this->cacheDirectory();
        chmod($directory, 0o500);

        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);

        $cache->put($file, (new FileIndexer())->index($file));

        $this->expectNotToPerformAssertions();
    }

    public function testGetReturnsNullWhenPayloadUnserializesToANonArray(): void
    {
        $directory = $this->cacheDirectory();
        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);

        $realpath = realpath($file);
        $entryPath = $directory . '/' . sha1($realpath) . '.cache';
        // A valid serialization of a non-array scalar: unserialize returns the
        // scalar (not false), so the `=== false` guard alone must not let it
        // through — the `!is_array` half of the check is what catches it.
        file_put_contents($entryPath, serialize(42));

        self::assertNull($cache->get($file));
    }

    public function testGetReturnsNullWhenPayloadUnserializesToAnObject(): void
    {
        $directory = $this->cacheDirectory();
        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);

        $realpath = realpath($file);
        $entryPath = $directory . '/' . sha1($realpath) . '.cache';
        // A non-allowed top-level object becomes __PHP_Incomplete_Class, whose
        // array access THROWS an Error that ?? does not swallow. Without the
        // `!is_array` guard, get() would let the Error escape — violating the
        // transparency contract. This test pins that the guard catches it.
        file_put_contents($entryPath, serialize((object) ['x' => 1]));

        self::assertNull($cache->get($file));
    }

    public function testPutCreatesCacheDirectoryLazily(): void
    {
        $directory = $this->uncreatedCacheDirectory();
        $cache = new FileIndexCache($directory);
        $file = $this->sourceFile(self::SAMPLE);
        $index = (new FileIndexer())->index($file);

        $cache->put($file, $index);

        self::assertEquals($index, $cache->get($file));
    }

    private function cacheDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('phptramp-cache-', true);
        mkdir($directory, 0o755, true);
        $this->directories[] = $directory;

        return $directory;
    }

    /**
     * Returns a cache directory path whose parent exists but which itself does
     * not yet exist — exercises {@see FileIndexCache::put()}'s lazy `mkdir`.
     */
    private function uncreatedCacheDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/' . uniqid('phptramp-cache-', true);
        $this->directories[] = $directory;

        return $directory;
    }

    private function sourceFile(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phptramp-src') . '.php';
        file_put_contents($file, $code);
        $this->files[] = $file;

        return $file;
    }

    private function removeTree(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeTree($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($directory);
    }
}
