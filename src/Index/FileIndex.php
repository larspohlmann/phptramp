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
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $classes,
        public readonly SuppressionParts $suppression,
    ) {
    }
}
