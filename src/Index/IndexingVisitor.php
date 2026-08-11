<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitorAbstract;
use PhpTramp\Ignore\SuppressionIndex;

/**
 * Accumulates class-hierarchy facts and a list of pending function/method
 * nodes across every file traversed. Parameter classification is deferred to
 * the Indexer once the whole traversal (including NameResolver) has run, so
 * that call targets inside bodies are already fully qualified.
 *
 * Relies on NameResolver (FQ names) and ParentConnectingVisitor (the `parent`
 * attribute) running before it in the traverser.
 *
 * @phpstan-type ClassFacts array{kind: ClassKind, parent: ?string, interfaces: list<string>, traits: list<string>}
 * @phpstan-type PendingMethod array{fqmn: string, file: string, line: int, class: ?string, node: FunctionLike}
 */
final class IndexingVisitor extends NodeVisitorAbstract
{
    /** @var list<PendingMethod> */
    private array $pending = [];

    /** @var array<string, ClassFacts> */
    private array $classes = [];

    /** @var list<string> */
    private array $suppressedMethods = [];

    /** @var list<string> */
    private array $suppressedClasses = [];

    /** @var list<array{string, string}> */
    private array $suppressedParams = [];

    /** @var array<string, list<int>> */
    private array $ignoreLines = [];

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
     * @return list<PendingMethod>
     */
    public function pending(): array
    {
        return $this->pending;
    }

    public function recordIgnoreComments(string $code): void
    {
        foreach (explode("\n", $code) as $index => $lineText) {
            if (str_contains($lineText, '// phptramp-ignore')) {
                $this->ignoreLines[$this->file][] = $index + 1;
            }
        }
    }

    public function suppressions(): SuppressionIndex
    {
        $methods = $this->suppressedMethods;
        foreach ($this->pending as $entry) {
            if ($entry['class'] !== null && in_array($entry['class'], $this->suppressedClasses, true)) {
                $methods[] = $entry['fqmn'];
            }
        }

        return new SuppressionIndex($methods, $this->suppressedParams, $this->ignoreLines);
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
                $facts['kind'],
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
            'kind' => $this->kindOf($node),
            'parent' => $parent,
            'interfaces' => $interfaces,
            'traits' => [],
        ];

        if ($this->hasTrampIgnore($node->attrGroups)) {
            $this->suppressedClasses[] = $fqcn;
        }
    }

    private function kindOf(ClassLike $node): ClassKind
    {
        return match (true) {
            $node instanceof Interface_ => ClassKind::Interface_,
            $node instanceof Trait_ => ClassKind::Trait_,
            $node instanceof Enum_ => ClassKind::Enum_,
            $node instanceof Class_ && $node->isAbstract() => ClassKind::AbstractClass,
            default => ClassKind::ConcreteClass,
        };
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
        $this->pending[] = [
            'fqmn' => $fqmn,
            'file' => $this->file,
            'line' => $node->getStartLine(),
            'class' => $fqcn,
            'node' => $node,
        ];

        $this->recordFunctionLikeSuppressions($fqmn, $node);
    }

    private function recordFunction(Function_ $node): void
    {
        $fqmn = $node->namespacedName?->toString();
        if ($fqmn === null) {
            return;
        }

        $this->pending[] = [
            'fqmn' => $fqmn,
            'file' => $this->file,
            'line' => $node->getStartLine(),
            'class' => null,
            'node' => $node,
        ];

        $this->recordFunctionLikeSuppressions($fqmn, $node);
    }

    private function recordFunctionLikeSuppressions(string $fqmn, FunctionLike $node): void
    {
        if ($this->hasTrampIgnore($node->getAttrGroups())) {
            $this->suppressedMethods[] = $fqmn;
        }

        foreach ($node->getParams() as $param) {
            if ($this->hasTrampIgnore($param->attrGroups)) {
                $this->recordSuppressedParam($fqmn, $param);
            }
        }
    }

    private function recordSuppressedParam(string $fqmn, Param $param): void
    {
        if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
            return;
        }

        $this->suppressedParams[] = [$fqmn, $param->var->name];
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    private function hasTrampIgnore(array $attrGroups): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === 'TrampIgnore') {
                    return true;
                }
            }
        }

        return false;
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
