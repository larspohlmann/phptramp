<?php

declare(strict_types=1);

namespace PhpTramp\Diff;

/**
 * The set of added/modified lines per file, as named by a unified diff.
 */
final class ChangedLines
{
    /** @param array<string, list<int>> $linesByFile paths exactly as the diff names them */
    public function __construct(private readonly array $linesByFile)
    {
    }

    public function isEmpty(): bool
    {
        return $this->linesByFile === [];
    }

    public function containsLine(string $file, int $line): bool
    {
        return in_array($line, $this->linesByFile[$file] ?? [], true);
    }

    /**
     * Re-keys every path via realpath($baseDirectory . '/' . $path); paths that
     * do not resolve (deleted files) are dropped. Hop->file is realpath-absolute,
     * so intersection must happen against a resolved instance.
     */
    public function resolveAgainst(string $baseDirectory): self
    {
        $resolvedLinesByFile = [];

        foreach ($this->linesByFile as $file => $lines) {
            $realPath = realpath($baseDirectory . '/' . $file);
            if ($realPath === false) {
                continue;
            }

            $resolvedLinesByFile[$realPath] = $lines;
        }

        return new self($resolvedLinesByFile);
    }
}
