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

        if ($param->byRef) {
            return new ParamInfo($name, $position, ParamFate::ByRefTerminated, [], true, $param->variadic, $type);
        }

        if ($param->isPromoted()) {
            return new ParamInfo($name, $position, ParamFate::Used, [], false, $param->variadic, $type);
        }

        if ($stmts === null || $name === '') {
            return new ParamInfo($name, $position, ParamFate::Unused, [], false, $param->variadic, $type);
        }

        [$fate, $forwards] = $this->analyzeBody($name, $param->variadic, $stmts);

        return new ParamInfo($name, $position, $fate, $forwards, false, $param->variadic, $type);
    }

    /**
     * @param array<Node\Stmt> $stmts
     *
     * @return array{0: ParamFate, 1: list<ForwardSite>}
     */
    private function analyzeBody(string $name, bool $variadic, array $stmts): array
    {
        $collector = new ForwardCollector($name, $variadic);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor($collector);
        $traverser->traverse($stmts);

        if ($collector->used) {
            return [ParamFate::Used, $collector->forwards];
        }
        if ($collector->forwards === []) {
            return [ParamFate::Unused, []];
        }

        return [ParamFate::PureForward, $collector->forwards];
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
