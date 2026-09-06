<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * Decides whether the forwards of one parameter fan out: reach two or more
 * distinct callees on a single execution path. A method that does so combines
 * what those callees return, so it consumes the parameter instead of passing
 * it on. Forwards that only ever reach distinct callees in mutually exclusive
 * branch arms are a dispatcher, not a fan-out.
 */
final class FanOutDetector
{
    /** @var list<array{callee: CalleeRef, path: BranchArmPath}> */
    private array $forwards = [];

    public function record(CalleeRef $callee, BranchArmPath $path): void
    {
        $this->forwards[] = ['callee' => $callee, 'path' => $path];
    }

    /**
     * Compares every ordered pair, self-pairs included: a forward is never
     * distinct from itself, so those answer false on their own. There are only
     * ever a handful of forwards per parameter.
     */
    public function fansOut(): bool
    {
        foreach ($this->forwards as $forward) {
            foreach ($this->forwards as $other) {
                if ($this->combineDistinctCallees($forward, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{callee: CalleeRef, path: BranchArmPath} $one
     * @param array{callee: CalleeRef, path: BranchArmPath} $other
     */
    private function combineDistinctCallees(array $one, array $other): bool
    {
        return ! $one['callee']->isSameAs($other['callee'])
            && ! $one['path']->isExclusiveWith($other['path']);
    }
}
