<?php

declare(strict_types=1);

namespace PhpTramp\Discovery;

use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;

/**
 * Resolves the configured folders and explicit files into a concrete list of
 * `*.php` paths: recursive, deduplicated, sorted, realpath-normalized.
 */
final class FileLocator
{
    /**
     * @return list<string>
     */
    public function locate(Options $options): array
    {
        /** @var array<string, true> $found */
        $found = [];

        foreach ($options->folders as $folder) {
            $this->collectFolder($folder, $found);
        }

        foreach ($options->files as $file) {
            $real = realpath($file);
            if ($real === false || ! is_file($real)) {
                throw new InvalidArgsException("file not found: {$file}");
            }
            $found[$real] = true;
        }

        $paths = array_keys($found);
        sort($paths);

        return $paths;
    }

    /**
     * @param array<string, true> $found
     * @param-out array<string, true> $found
     */
    private function collectFolder(string $folder, array &$found): void
    {
        $real = realpath($folder);
        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgsException("folder not found: {$folder}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (! $fileInfo instanceof \SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }
            $path = $fileInfo->getRealPath();
            if ($path !== false) {
                $found[$path] = true;
            }
        }
    }
}
