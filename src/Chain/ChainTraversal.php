<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

use PhpTramp\Index\CalleeKind;
use PhpTramp\Index\ForwardSite;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Index\MethodInfo;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParamInfo;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ExternalTarget;
use PhpTramp\Resolve\Resolution;
use PhpTramp\Resolve\ResolvedTarget;
use PhpTramp\Resolve\TruncatedResolution;

/**
 * One depth-first run over the forward graph of a single index. Every
 * root-to-terminal path becomes a {@see Finding}. Origins are pure-forward nodes
 * with no incoming edge; entry-less cycles are picked up in a second pass so a
 * mutually-recursive pair with no external caller is still reported once. The
 * current path is the visited set, so cycles end a branch with a `(recursive)`
 * note instead of looping.
 *
 * @phpstan-type Node array{0: MethodInfo, 1: ParamInfo}
 */
final class ChainTraversal
{
    private const KEY_SEPARATOR = "\0";

    /** @var list<Finding> */
    private array $findings = [];

    /** @var array<string, true> */
    private array $visited = [];

    public function __construct(
        private readonly MethodIndex $index,
        private readonly CallResolver $resolver,
    ) {
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $nodes = $this->pureForwardNodes();
        $incoming = $this->incomingTargets($nodes);

        foreach ($nodes as [$method, $param]) {
            if (! isset($incoming[$this->key($method->fqmn, $param->name)])) {
                $this->walk($method, $param, new PartialChain($param->name));
            }
        }

        foreach ($nodes as [$method, $param]) {
            if (! isset($this->visited[$this->key($method->fqmn, $param->name)])) {
                $this->walk($method, $param, new PartialChain($param->name));
            }
        }

        $this->sortFindings();

        return $this->findings;
    }

    /**
     * @return list<Node>
     */
    private function pureForwardNodes(): array
    {
        $nodes = [];
        foreach ($this->index->all() as $method) {
            foreach ($method->params as $param) {
                if ($param->fate === ParamFate::PureForward) {
                    $nodes[] = [$method, $param];
                }
            }
        }

        return $nodes;
    }

    /**
     * @param list<Node> $nodes
     * @return array<string, true>
     */
    private function incomingTargets(array $nodes): array
    {
        $incoming = [];
        foreach ($nodes as [$method, $param]) {
            foreach ($param->forwards as $forward) {
                $resolution = $this->resolver->resolve($forward, $method);
                if ($resolution instanceof ResolvedTarget) {
                    $incoming[$this->key($resolution->fqmn, $resolution->boundParam)] = true;
                }
            }
        }

        return $incoming;
    }

    private function walk(MethodInfo $method, ParamInfo $param, PartialChain $chain): void
    {
        $key = $this->key($method->fqmn, $param->name);
        $this->visited[$key] = true;

        foreach ($param->forwards as $forward) {
            $resolution = $this->resolver->resolve($forward, $method);
            $hop = new Hop(
                $method->fqmn,
                $method->class,
                $method->file,
                $method->line,
                $forward->line,
                false,
                $param->name,
                $this->isParentDelegation($forward),
            );
            $advanced = $chain->append($hop, $this->traceLine($method->fqmn, $forward, $resolution), $key);
            $this->follow($resolution, $advanced);
        }
    }

    /**
     * A `parent::` call hands the value to the same object's base class, not to
     * a collaborator. Detected syntactically: php-parser's NameResolver leaves
     * `parent` unresolved as a special class name, which is also why
     * CallResolver matches the literal string.
     */
    private function isParentDelegation(ForwardSite $forward): bool
    {
        return $forward->callee->kind === CalleeKind::StaticCall
            && $forward->callee->receiverHint === 'parent';
    }

    private function follow(Resolution $resolution, PartialChain $chain): void
    {
        if ($resolution instanceof ResolvedTarget) {
            $this->followTarget($resolution, $chain);

            return;
        }

        if ($resolution instanceof ExternalTarget) {
            $this->record($chain, new Terminal(null, null, TerminalKind::External));

            return;
        }

        if ($resolution instanceof TruncatedResolution) {
            $note = 'truncated: ' . $resolution->reason;
            $this->record($chain, new Terminal(null, null, TerminalKind::Truncated, [$note]));
        }
    }

    private function followTarget(ResolvedTarget $target, PartialChain $chain): void
    {
        $method = $this->index->get($target->fqmn);
        $param = $method?->paramNamed($target->boundParam);
        if ($method === null || $param === null) {
            $this->record($chain, new Terminal(null, null, TerminalKind::External));

            return;
        }

        if ($param->fate !== ParamFate::PureForward) {
            $this->record($chain, $this->terminalUse($method, $param));

            return;
        }

        if ($chain->hasKey($this->key($target->fqmn, $target->boundParam))) {
            $this->record($chain, new Terminal(null, null, TerminalKind::Truncated, ['(recursive)']));

            return;
        }

        $this->walk($method, $param, $chain);
    }

    private function terminalUse(MethodInfo $method, ParamInfo $param): Terminal
    {
        $hop = new Hop(
            $method->fqmn,
            $method->class,
            $method->file,
            $method->line,
            null,
            false,
            $param->name,
        );

        return new Terminal($hop, $method->fqmn, $this->terminalKind($param));
    }

    private function terminalKind(ParamInfo $param): TerminalKind
    {
        if ($param->fate === ParamFate::ByRefTerminated) {
            return TerminalKind::ByRef;
        }

        if ($param->fate === ParamFate::Unused) {
            return TerminalKind::Unused;
        }

        return $param->storedOnly ? TerminalKind::Stored : TerminalKind::Used;
    }

    private function record(PartialChain $chain, Terminal $terminal): void
    {
        $fullChain = $terminal->hop === null ? $chain->hops : [...$chain->hops, $terminal->hop];

        $this->findings[] = new Finding(
            $chain->originParam,
            $chain->hops[0]->fqmn,
            $terminal->fqmn,
            $terminal->kind,
            $this->scoredHops($chain->hops),
            $fullChain,
            $this->distinctClasses($fullChain),
            $terminal->notes,
            $chain->trace,
        );
    }

    /**
     * The hop score: forwarding nodes that handed the value to a collaborator.
     * `parent::` delegators stay in the rendered chain but never score — they
     * are the same object as the node they delegate into.
     *
     * @param list<Hop> $hops
     */
    private function scoredHops(array $hops): int
    {
        $scored = 0;
        foreach ($hops as $hop) {
            if (! $hop->viaParent) {
                $scored++;
            }
        }

        return $scored;
    }

    /**
     * The class count: distinct declaring classes the value visited. A
     * `parent::` delegator is skipped because the base it delegates into is the
     * same object and stands in for it — but only when that base is actually in
     * the chain. A chain ending at a delegator (its base is outside the index,
     * e.g. `extends \RuntimeException`) has nothing downstream to represent it,
     * so its class counts; the value really did cross into it.
     *
     * @param list<Hop> $chain
     */
    private function distinctClasses(array $chain): int
    {
        $classes = [];
        $lastIndex = count($chain) - 1;
        foreach ($chain as $index => $hop) {
            if ($hop->class === null || ($hop->viaParent && $index !== $lastIndex)) {
                continue;
            }
            $classes[$hop->class] = true;
        }

        return count($classes);
    }

    private function traceLine(string $callerFqmn, ForwardSite $forward, Resolution $resolution): string
    {
        $callee = $forward->callee;
        $edge = $callee->kind->value . ':' . $callee->name;

        return $callerFqmn . ': ' . $edge . ' -> ' . $resolution->describe();
    }

    private function sortFindings(): void
    {
        usort($this->findings, static function (Finding $left, Finding $right): int {
            return [$left->chain[0]->file, $left->chain[0]->line, $left->param]
                <=> [$right->chain[0]->file, $right->chain[0]->line, $right->param];
        });
    }

    private function key(string $fqmn, string $param): string
    {
        return $fqmn . self::KEY_SEPARATOR . $param;
    }
}
