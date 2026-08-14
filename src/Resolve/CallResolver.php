<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

use PhpTramp\Index\CalleeKind;
use PhpTramp\Index\ForwardSite;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Index\MethodInfo;
use PhpTramp\Index\ParamInfo;

/**
 * Resolves one syntactic {@see ForwardSite} against the whole-project index to a
 * concrete target method, an external target, or an honest truncation, following
 * the frozen Phase 2 resolution rules. Receiver typing is delegated to
 * {@see ReceiverTyper}; hierarchy walks to {@see ClassHierarchy}.
 */
final class CallResolver
{
    private readonly ReceiverTyper $receiverTyper;

    public function __construct(
        private readonly MethodIndex $index,
        private readonly ClassHierarchy $hierarchy,
    ) {
        $this->receiverTyper = new ReceiverTyper();
    }

    public function resolve(ForwardSite $site, MethodInfo $caller): Resolution
    {
        return match ($site->callee->kind) {
            CalleeKind::Func => $this->resolveFunction($site, $caller),
            CalleeKind::StaticCall => $this->resolveStatic($site, $caller),
            CalleeKind::Method => $this->resolveMethod($site, $caller),
            CalleeKind::Instantiation => $this->resolveNew($site),
        };
    }

    private function resolveFunction(ForwardSite $site, MethodInfo $caller): Resolution
    {
        $target = $this->functionTarget($site->callee->name, $this->namespaceOf($caller));
        if ($target === null) {
            return new ExternalTarget('function not in index');
        }

        return $this->bind($target, $site);
    }

    private function functionTarget(string $name, string $callerNamespace): ?MethodInfo
    {
        foreach ($this->functionCandidates($name, $callerNamespace) as $candidate) {
            $target = $this->index->get($candidate);
            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /**
     * An unqualified call in a namespace resolves to the namespaced function if
     * one exists, else falls back to the global one (PHP's function fallback).
     *
     * @return list<string>
     */
    private function functionCandidates(string $name, string $callerNamespace): array
    {
        if (str_contains($name, '\\')) {
            return [$name];
        }

        if ($callerNamespace === '') {
            return [$name];
        }

        return [$callerNamespace . '\\' . $name, $name];
    }

    private function namespaceOf(MethodInfo $caller): string
    {
        $symbol = $caller->class ?? $caller->fqmn;
        $separator = strrpos($symbol, '\\');

        return $separator === false ? '' : substr($symbol, 0, $separator);
    }

    private function resolveStatic(ForwardSite $site, MethodInfo $caller): Resolution
    {
        $class = $this->staticClass($site->callee->receiverHint, $caller);
        if ($class === null || $this->index->classInfo($class) === null) {
            return new ExternalTarget('class not in index');
        }

        return $this->resolveOnConcreteClass($class, $site);
    }

    private function staticClass(?string $hint, MethodInfo $caller): ?string
    {
        return match ($hint) {
            'self', 'static' => $caller->class,
            'parent' => $this->parentOf($caller),
            default => $hint,
        };
    }

    private function parentOf(MethodInfo $caller): ?string
    {
        if ($caller->class === null) {
            return null;
        }

        return $this->index->classInfo($caller->class)?->parent;
    }

    private function resolveMethod(ForwardSite $site, MethodInfo $caller): Resolution
    {
        $hint = $site->callee->receiverHint;
        $type = $this->receiverTyper->type($hint, $caller);
        if ($type === null) {
            return new TruncatedResolution($this->untypeableReason($hint));
        }

        if ($this->index->classInfo($type) === null) {
            return new ExternalTarget('receiver type not in index');
        }

        if ($this->hierarchy->isAbstractType($type)) {
            return $this->resolveAbstract($type, $site);
        }

        return $this->resolveOnConcreteClass($type, $site);
    }

    private function untypeableReason(?string $hint): string
    {
        return ($hint === null || $hint === 'raw') ? 'untyped receiver' : 'unresolvable type';
    }

    private function resolveAbstract(string $type, ForwardSite $site): Resolution
    {
        $implementations = $this->hierarchy->implementationsOf($type);
        $count = count($implementations);

        if ($count === 0) {
            return new ExternalTarget('no implementation in index');
        }

        if ($count > 1) {
            return new TruncatedResolution($count . ' implementations');
        }

        return $this->resolveOnConcreteClass($implementations[0], $site);
    }

    private function resolveOnConcreteClass(string $class, ForwardSite $site): Resolution
    {
        $target = $this->hierarchy->methodOn($class, $site->callee->name);
        if ($target === null) {
            return new TruncatedResolution('method not found');
        }

        return $this->bind($target, $site);
    }

    private function resolveNew(ForwardSite $site): Resolution
    {
        $class = $site->callee->name;
        if ($this->index->classInfo($class) === null) {
            return new ExternalTarget('class not in index');
        }

        $constructor = $this->hierarchy->methodOn($class, '__construct');
        if ($constructor === null) {
            return new ExternalTarget('no constructor in index');
        }

        return $this->bind($constructor, $site);
    }

    private function bind(MethodInfo $target, ForwardSite $site): Resolution
    {
        $param = $this->boundParam($target, $site->argKey);
        if ($param === null) {
            return new TruncatedResolution('argument does not bind to a parameter');
        }

        return new ResolvedTarget($target->fqmn, $param->name);
    }

    private function boundParam(MethodInfo $target, int|string $argKey): ?ParamInfo
    {
        if (is_string($argKey)) {
            return $target->paramNamed($argKey);
        }

        return $this->paramByPosition($target, $argKey);
    }

    private function paramByPosition(MethodInfo $target, int $position): ?ParamInfo
    {
        foreach ($target->params as $param) {
            if ($param->position === $position) {
                return $param;
            }

            if ($param->variadic && $position >= $param->position) {
                return $param;
            }
        }

        return null;
    }
}
