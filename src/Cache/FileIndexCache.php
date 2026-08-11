<?php

declare(strict_types=1);

namespace PhpTramp\Cache;

use PhpTramp\Console\Application;
use PhpTramp\Index\CalleeKind;
use PhpTramp\Index\CalleeRef;
use PhpTramp\Index\ClassInfo;
use PhpTramp\Index\ClassKind;
use PhpTramp\Index\FileIndex;
use PhpTramp\Index\ForwardSite;
use PhpTramp\Index\MethodInfo;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParamInfo;

/**
 * Per-source-file cache of a serialized {@see FileIndex}. An entry is keyed by
 * the sha1 of the source file's real path and is invalidated by mtime, size,
 * application version, and payload format — any drift means a miss, never a
 * stale result.
 *
 * The cache is transparency-critical: no failure path (unreadable entry,
 * corrupt payload, version/format mismatch, unwritable directory) may escape
 * as an exception. Every miss returns null and lets the caller parse fresh.
 */
final class FileIndexCache
{
    /**
     * Bump when {@see FileIndex} or any nested value object changes shape; a
     * cached entry written under an older format is silently ignored.
     */
    private const FORMAT = 1;

    /** Directory permission mode for lazily-created cache directories. */
    private const DIRECTORY_MODE = 0o755;

    /** @var list<class-string> */
    private const ALLOWED_CLASSES = [
        FileIndex::class,
        MethodInfo::class,
        ParamInfo::class,
        ParamFate::class,
        ForwardSite::class,
        CalleeRef::class,
        CalleeKind::class,
        ClassInfo::class,
        ClassKind::class,
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Returns the cached index for the file, or null on any miss — absent
     * entry, unreadable file, version/format/identity mismatch, or corrupt
     * payload. Never throws.
     */
    public function get(string $file): ?FileIndex
    {
        $realpath = realpath($file);
        if ($realpath === false) {
            return null;
        }

        $entryPath = $this->entryPath($realpath);
        $payload = @file_get_contents($entryPath);
        if ($payload === false) {
            return null;
        }

        $data = @unserialize($payload, ['allowed_classes' => self::ALLOWED_CLASSES]);
        if (!is_array($data)) {
            return null;
        }

        $index = $data['index'] ?? null;
        if (!$index instanceof FileIndex) {
            return null;
        }

        $stat = @stat($realpath);
        if ($stat === false) {
            return null;
        }

        if (!$this->payloadIsCurrent($data, $realpath, $stat)) {
            return null;
        }

        return $index;
    }

    /**
     * A payload is current only when format, version, source path, and the
     * file's mtime and size all match what was recorded at write time.
     *
     * @param array<mixed> $data
     * @phpstan-param array<int|string, int> $stat
     */
    private function payloadIsCurrent(array $data, string $realpath, array $stat): bool
    {
        return ($data['format'] ?? null) === self::FORMAT
            && ($data['version'] ?? null) === Application::VERSION
            && ($data['path'] ?? null) === $realpath
            && ($data['mtime'] ?? null) === $stat['mtime']
            && ($data['size'] ?? null) === $stat['size'];
    }

    /**
     * Writes the index for the file, best-effort. A failed write (unwritable
     * directory, full disk) is silently ignored — the next run will simply
     * re-parse. Never throws.
     */
    public function put(string $file, FileIndex $index): void
    {
        $realpath = realpath($file);
        if ($realpath === false) {
            return;
        }

        $stat = @stat($realpath);
        if ($stat === false) {
            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, self::DIRECTORY_MODE, true);
            if (!is_dir($this->directory)) {
                return;
            }
        }

        $payload = serialize([
            'format' => self::FORMAT,
            'version' => Application::VERSION,
            'path' => $realpath,
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'index' => $index,
        ]);

        @file_put_contents($this->entryPath($realpath), $payload);
    }

    private function entryPath(string $realpath): string
    {
        return $this->directory . '/' . sha1($realpath) . '.cache';
    }
}
