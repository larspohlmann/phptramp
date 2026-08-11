<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * Everything the index learns from one source file. A self-contained,
 * serializable unit so Task 2's per-file cache can persist and reload it
 * without re-parsing.
 */
final class FileIndex
{
    /**
     * @param array<string, MethodInfo> $methods keyed by FQMN
     * @param array<string, ClassInfo> $classes keyed by FQCN
     * @param list<string> $suppressedMethods FQMNs suppressed by a method- or class-level attribute
     * @param list<array{string, string}> $suppressedParams [fqmn, paramName] pairs
     * @param array<string, list<int>> $suppressedLines file -> lines carrying an ignore comment
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $classes,
        public readonly array $suppressedMethods,
        public readonly array $suppressedParams,
        public readonly array $suppressedLines,
    ) {
    }
}
