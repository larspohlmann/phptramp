<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/**
 * Classifies each parameter of one method as PureForward, Used,
 * ByRefTerminated, or Unused, following the frozen core semantics. The method
 * body must already have been name-resolved by the Indexer's main traversal.
 */
final class UsageClassifier
{
    /**
     * @param array<Param> $params
     * @param array<Node\Stmt>|null $stmts null for abstract / interface methods
     *
     * @return list<ParamInfo>
     */
    public function classify(array $params, ?array $stmts): array
    {
        if ($stmts !== null) {
            // Connect parents once for the whole body; every per-parameter walk
            // below then reads the same `parent` attributes.
            $connector = new NodeTraverser();
            $connector->addVisitor(new ParentConnectingVisitor());
            $connector->traverse($stmts);
        }

        $out = [];
        foreach ($params as $position => $param) {
            $out[] = $this->classifyParam($param, $position, $stmts);
        }

        return $out;
    }

    /**
     * @param array<Node\Stmt>|null $stmts
     */
    private function classifyParam(Param $param, int $position, ?array $stmts): ParamInfo
    {
        $name = ($param->var instanceof Variable && is_string($param->var->name)) ? $param->var->name : '';
        $type = $this->typeToString($param->type);
        $forwards = [];
        $storedOnly = false;

        if ($param->byRef) {
            $fate = ParamFate::ByRefTerminated;
        } elseif ($param->isPromoted()) {
            $fate = ParamFate::Used;
            $storedOnly = true;
        } elseif ($stmts === null || $name === '') {
            $fate = ParamFate::Unused;
        } else {
            [$fate, $forwards, $storedOnly] = $this->analyzeBody($name, $param->variadic, $stmts);
        }

        return new ParamInfo($name, $position, $fate, $forwards, $param->byRef, $param->variadic, $type, $storedOnly);
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array{0: ParamFate, 1: list<ForwardSite>, 2: bool}
     */
    private function analyzeBody(string $name, bool $variadic, array $stmts): array
    {
        $collector = new ForwardCollector($name, $variadic);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        if ($collector->used) {
            return [ParamFate::Used, $collector->forwards, $collector->storesOnly()];
        }
        if ($collector->forwards === []) {
            return [ParamFate::Unused, [], false];
        }

        return [ParamFate::PureForward, $collector->forwards, false];
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
}
