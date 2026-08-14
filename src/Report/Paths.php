<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * Rewrites absolute file paths to be relative to a working directory. Every
 * machine reporter uses this (GitHub annotations, SARIF, JSON) so findings
 * point at workspace-relative paths instead of the analysis machine's layout.
 */
final class Paths
{
    private readonly string $workingDirectory;

    public function __construct(string $workingDirectory)
    {
        $this->workingDirectory = rtrim($workingDirectory, '/');
    }

    public function relativize(string $absolutePath): string
    {
        if ($absolutePath === $this->workingDirectory) {
            return '';
        }

        $prefix = $this->workingDirectory . '/';
        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }
}
