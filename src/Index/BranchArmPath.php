<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Switch_;

/**
 * Which arm of every enclosing branching construct a syntax node sits in. Two
 * nodes cannot both run on one execution path when some branch names them in
 * different arms.
 */
final class BranchArmPath
{
    /** @param array<int, string> $armByBranch arm label keyed by branching node object id */
    private function __construct(private readonly array $armByBranch)
    {
    }

    /**
     * Requires the `parent` attribute to be set on the node and its ancestors.
     *
     * Object ids are safe as keys here because the whole method body stays
     * alive for as long as any path derived from it is compared, so no id can
     * be recycled onto a different node in between.
     */
    public static function of(Node $node): self
    {
        $armByBranch = [];
        $child = $node;

        while (($parent = $child->getAttribute('parent')) instanceof Node) {
            $arm = self::armOf($parent, $child);
            if ($arm !== null) {
                $armByBranch[spl_object_id($parent)] = $arm;
            }
            $child = $parent;
        }

        return new self($armByBranch);
    }

    public function isExclusiveWith(self $other): bool
    {
        foreach ($this->armByBranch as $branch => $arm) {
            if (isset($other->armByBranch[$branch]) && $other->armByBranch[$branch] !== $arm) {
                return true;
            }
        }

        return false;
    }

    private static function armOf(Node $parent, Node $child): ?string
    {
        return match (true) {
            $parent instanceof If_ => self::ifArm($parent, $child),
            $parent instanceof Match_ => self::indexedArm($child, $parent->arms),
            $parent instanceof Switch_ => self::indexedArm($child, $parent->cases),
            $parent instanceof Ternary => self::ternaryArm($parent, $child),
            default => null,
        };
    }

    private static function ifArm(If_ $branch, Node $child): ?string
    {
        if (in_array($child, $branch->stmts, true)) {
            return 'then';
        }
        if ($child instanceof Else_) {
            return 'else';
        }

        $elseif = self::indexedArm($child, $branch->elseifs);

        return $elseif === null ? null : 'elseif:' . $elseif;
    }

    private static function ternaryArm(Ternary $branch, Node $child): ?string
    {
        if ($child === $branch->if) {
            return 'then';
        }

        return $child === $branch->else ? 'else' : null;
    }

    /**
     * @param array<array-key, Node> $arms
     *
     * @return string|null the arm's index, or null when the child is the
     *                     branch's subject rather than one of its arms
     */
    private static function indexedArm(Node $child, array $arms): ?string
    {
        foreach ($arms as $index => $arm) {
            if ($arm === $child) {
                return (string) $index;
            }
        }

        return null;
    }
}
