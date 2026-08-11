<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitorAbstract;

/**
 * Accumulates methods, functions, and class-hierarchy facts across every file
 * traversed. Relies on NameResolver (FQ names) and ParentConnectingVisitor
 * (the `parent` attribute) running before it in the traverser.
 *
 * @phpstan-type ClassFacts array{parent: ?string, interfaces: list<string>, traits: list<string>}
 */
final class IndexingVisitor extends NodeVisitorAbstract
{
    /** @var array<string, MethodInfo> */
    private array $methods = [];

    /** @var array<string, ClassFacts> */
    private array $classes = [];

    private string $file = '';

    public function setFile(string $file): void
    {
        $this->file = $file;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ClassLike) {
            $this->recordClass($node);
        } elseif ($node instanceof TraitUse) {
            $this->recordTraitUse($node);
        } elseif ($node instanceof ClassMethod) {
            $this->recordMethod($node);
        } elseif ($node instanceof Function_) {
            $this->recordFunction($node);
        }

        return null;
    }

    /**
     * @return array<string, MethodInfo>
     */
    public function methods(): array
    {
        return $this->methods;
    }

    /**
     * @return array<string, ClassInfo>
     */
    public function classes(): array
    {
        $out = [];
        foreach ($this->classes as $fqcn => $facts) {
            $out[$fqcn] = new ClassInfo(
                $fqcn,
                $facts['parent'],
                $facts['interfaces'],
                $facts['traits'],
            );
        }

        return $out;
    }

    private function recordClass(ClassLike $node): void
    {
        $fqcn = $node->namespacedName?->toString();
        if ($fqcn === null) {
            return;
        }

        $parent = null;
        $interfaces = [];

        if ($node instanceof Class_) {
            $parent = $node->extends?->toString();
            $interfaces = $this->namesToStrings($node->implements);
        } elseif ($node instanceof Interface_) {
            $interfaces = $this->namesToStrings($node->extends);
        } elseif ($node instanceof Enum_) {
            $interfaces = $this->namesToStrings($node->implements);
        }

        $this->classes[$fqcn] = [
            'parent' => $parent,
            'interfaces' => $interfaces,
            'traits' => [],
        ];
    }

    private function recordTraitUse(TraitUse $node): void
    {
        $fqcn = $this->enclosingClassName($node);
        if ($fqcn === null || ! isset($this->classes[$fqcn])) {
            return;
        }

        foreach ($node->traits as $trait) {
            $this->classes[$fqcn]['traits'][] = $trait->toString();
        }
    }

    private function recordMethod(ClassMethod $node): void
    {
        $fqcn = $this->enclosingClassName($node);
        if ($fqcn === null) {
            return;
        }

        $fqmn = $fqcn . '::' . $node->name->toString();
        $this->methods[$fqmn] = new MethodInfo(
            $fqmn,
            $this->file,
            $node->getStartLine(),
            $this->buildParams($node->params),
            $fqcn,
        );
    }

    private function recordFunction(Function_ $node): void
    {
        $fqmn = $node->namespacedName?->toString();
        if ($fqmn === null) {
            return;
        }

        $this->methods[$fqmn] = new MethodInfo(
            $fqmn,
            $this->file,
            $node->getStartLine(),
            $this->buildParams($node->params),
            null,
        );
    }

    private function enclosingClassName(Node $node): ?string
    {
        $parent = $node->getAttribute('parent');
        if (! $parent instanceof ClassLike) {
            return null;
        }

        return $parent->namespacedName?->toString();
    }

    /**
     * @param array<Param> $params
     * @return list<ParamInfo>
     */
    private function buildParams(array $params): array
    {
        $out = [];
        foreach ($params as $position => $param) {
            $var = $param->var;
            if (! $var instanceof Node\Expr\Variable || ! is_string($var->name)) {
                continue;
            }

            $out[] = new ParamInfo(
                name: $var->name,
                position: $position,
                fate: ParamFate::Unused,
                forwards: [],
                byRef: $param->byRef,
                variadic: $param->variadic,
                type: $this->typeToString($param->type),
            );
        }

        return $out;
    }

    private function typeToString(?Node $type): ?string
    {
        if ($type instanceof Name || $type instanceof Identifier) {
            return $type->toString();
        }
        if ($type instanceof NullableType) {
            return $this->typeToString($type->type);
        }

        return null;
    }

    /**
     * @param array<Name> $names
     * @return list<string>
     */
    private function namesToStrings(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[] = $name->toString();
        }

        return $out;
    }
}
