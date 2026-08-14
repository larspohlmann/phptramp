<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpTramp\Ignore\SuppressionIndex;

/**
 * The whole-project index: fully-qualified method name -> MethodInfo, plus
 * per-class hierarchy facts and collected suppressions. Immutable; produced by
 * {@see Indexer}.
 */
final class MethodIndex
{
    /**
     * @param array<string, MethodInfo> $methods
     * @param array<string, ClassInfo> $classes
     */
    public function __construct(
        private readonly array $methods,
        private readonly array $classes = [],
        private readonly SuppressionIndex $suppressions = new SuppressionIndex([], [], []),
    ) {
    }

    public function suppressions(): SuppressionIndex
    {
        return $this->suppressions;
    }

    public function get(string $fqmn): ?MethodInfo
    {
        return $this->methods[$fqmn] ?? null;
    }

    /**
     * @return iterable<string, MethodInfo>
     */
    public function all(): iterable
    {
        return $this->methods;
    }

    public function classInfo(string $fqcn): ?ClassInfo
    {
        return $this->classes[$fqcn] ?? null;
    }

    /**
     * @return iterable<string, ClassInfo>
     */
    public function allClasses(): iterable
    {
        return $this->classes;
    }
}
