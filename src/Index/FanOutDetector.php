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
    /** @var list<ForwardOccurrence> */
    private array $forwards = [];

    public function record(ForwardSite $site, BranchArmPath $branchArms): void
    {
        $this->forwards[] = new ForwardOccurrence($site, $branchArms);
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
                if (self::areDistinctOnOnePath($forward, $other)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function areDistinctOnOnePath(ForwardOccurrence $one, ForwardOccurrence $other): bool
    {
        return ! $one->site->callee->isSameAs($other->site->callee)
            && ! $one->branchArms->isExclusiveWith($other->branchArms);
    }
}
