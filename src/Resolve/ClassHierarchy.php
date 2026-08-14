<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

use PhpTramp\Index\ClassInfo;
use PhpTramp\Index\ClassKind;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Index\MethodInfo;

/**
 * Answers the hierarchy questions the CallResolver needs: where a method
 * actually lives (direct declaration, then used traits, then the parent chain),
 * whether a type is abstract, and which concrete classes implement an interface
 * or extend an abstract class. All walks carry a visited set so malformed input
 * with a cyclic hierarchy cannot hang the analyzer.
 */
final class ClassHierarchy
{
    public function __construct(private readonly MethodIndex $index)
    {
    }

    /** Direct declaration, then used traits, then the parent chain — first hit wins. */
    public function methodOn(string $fqcn, string $method): ?MethodInfo
    {
        return $this->lookupMethod($fqcn, $method, []);
    }

    /** True for Interface_ and AbstractClass kinds. */
    public function isAbstractType(string $fqcn): bool
    {
        $kind = $this->index->classInfo($fqcn)?->kind;

        return $kind === ClassKind::Interface_ || $kind === ClassKind::AbstractClass;
    }

    /**
     * Concrete classes/enums that implement the interface (transitively, incl.
     * via parents) or extend the abstract class (transitively). Sorted for
     * determinism.
     *
     * @return list<string>
     */
    public function implementationsOf(string $fqcn): array
    {
        if ($this->index->classInfo($fqcn) === null) {
            return [];
        }

        $implementations = [];
        foreach ($this->concreteClasses() as $candidate) {
            if (in_array($fqcn, $this->supertypesOf($candidate->name, []), true)) {
                $implementations[] = $candidate->name;
            }
        }

        sort($implementations);

        return $implementations;
    }

    /**
     * @param list<string> $visited
     */
    private function lookupMethod(string $fqcn, string $method, array $visited): ?MethodInfo
    {
        if (in_array($fqcn, $visited, true)) {
            return null;
        }

        $class = $this->index->classInfo($fqcn);
        if ($class === null) {
            return null;
        }

        $seen = [...$visited, $fqcn];

        return $this->index->get($fqcn . '::' . $method)
            ?? $this->methodInTraits($class->traits, $method, $seen)
            ?? $this->methodOnParent($class->parent, $method, $seen);
    }

    /**
     * @param list<string> $traits
     * @param list<string> $visited
     */
    private function methodInTraits(array $traits, string $method, array $visited): ?MethodInfo
    {
        foreach ($traits as $trait) {
            $found = $this->lookupMethod($trait, $method, $visited);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<string> $visited
     */
    private function methodOnParent(?string $parent, string $method, array $visited): ?MethodInfo
    {
        if ($parent === null) {
            return null;
        }

        return $this->lookupMethod($parent, $method, $visited);
    }

    /**
     * Transitive parents and interfaces (interfaces record their `extends` in
     * the interface list), never the type itself.
     *
     * @param list<string> $visited
     * @return list<string>
     */
    private function supertypesOf(string $fqcn, array $visited): array
    {
        if (in_array($fqcn, $visited, true)) {
            return [];
        }

        $class = $this->index->classInfo($fqcn);
        if ($class === null) {
            return [];
        }

        $seen = [...$visited, $fqcn];
        $direct = $class->parent === null ? $class->interfaces : [$class->parent, ...$class->interfaces];

        $supertypes = $direct;
        foreach ($direct as $supertype) {
            $supertypes = [...$supertypes, ...$this->supertypesOf($supertype, $seen)];
        }

        return $supertypes;
    }

    /**
     * @return list<ClassInfo>
     */
    private function concreteClasses(): array
    {
        $concrete = [];
        foreach ($this->index->allClasses() as $class) {
            if ($class->kind === ClassKind::ConcreteClass || $class->kind === ClassKind::Enum_) {
                $concrete[] = $class;
            }
        }

        return $concrete;
    }
}
