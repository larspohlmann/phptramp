<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * Raw class-hierarchy facts for one class-like declaration. Names are already
 * fully qualified by php-parser's NameResolver; hierarchy *semantics* (trait
 * flattening, implementation lookup) are Phase 2's ClassHierarchy.
 */
final class ClassInfo
{
    /**
     * @param list<string> $interfaces implemented interfaces / extended parent interfaces
     * @param list<string> $traits used traits
     */
    public function __construct(
        public readonly string $name,
        public readonly ClassKind $kind,
        public readonly ?string $parent,
        public readonly array $interfaces,
        public readonly array $traits,
    ) {
    }
}
