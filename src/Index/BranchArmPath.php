<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
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
     * Only an arm's *body* counts as an arm. A branch condition — `if (…)`,
     * `elseif (…)`, a `match` or `switch` subject, a match arm's or case's
     * condition, a ternary condition — is evaluated on the way into the arms
     * that follow it, so it can never be exclusive with any of them. Recording
     * nothing for a condition lets it share the path with every arm, which is
     * also the conservative direction: a shared path can only yield fewer
     * findings.
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
                [$branch, $label] = $arm;
                $armByBranch[spl_object_id($branch)] = $label;
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

    /**
     * @return array{Node, string}|null the branching node the label belongs to
     *                                  and the label, or null when the child
     *                                  does not sit in an arm of the parent
     */
    private static function armOf(Node $parent, Node $child): ?array
    {
        return match (true) {
            $parent instanceof If_ => self::thenArm($parent, $child),
            $parent instanceof ElseIf_ => self::elseifArm($parent, $child),
            $parent instanceof Else_ => self::elseArm($parent, $child),
            $parent instanceof MatchArm => self::matchArm($parent, $child),
            $parent instanceof Case_ => self::caseArm($parent, $child),
            $parent instanceof Ternary => self::ternaryArm($parent, $child),
            default => null,
        };
    }

    /** @return array{Node, string}|null */
    private static function thenArm(If_ $branch, Node $child): ?array
    {
        return in_array($child, $branch->stmts, true) ? [$branch, 'then'] : null;
    }

    /** @return array{Node, string}|null */
    private static function elseifArm(ElseIf_ $arm, Node $child): ?array
    {
        if (! in_array($child, $arm->stmts, true)) {
            return null;
        }

        $branch = self::enclosingBranch($arm, If_::class);
        if ($branch === null) {
            return null;
        }

        $index = self::indexOf($arm, $branch->elseifs);

        return $index === null ? null : [$branch, 'elseif:' . $index];
    }

    /** @return array{Node, string}|null */
    private static function elseArm(Else_ $arm, Node $child): ?array
    {
        if (! in_array($child, $arm->stmts, true)) {
            return null;
        }

        $branch = self::enclosingBranch($arm, If_::class);

        return $branch === null ? null : [$branch, 'else'];
    }

    /** @return array{Node, string}|null */
    private static function matchArm(MatchArm $arm, Node $child): ?array
    {
        if ($child !== $arm->body) {
            return null;
        }

        $branch = self::enclosingBranch($arm, Match_::class);
        if ($branch === null) {
            return null;
        }

        $index = self::indexOf($arm, $branch->arms);

        return $index === null ? null : [$branch, $index];
    }

    /** @return array{Node, string}|null */
    private static function caseArm(Case_ $arm, Node $child): ?array
    {
        if (! in_array($child, $arm->stmts, true)) {
            return null;
        }

        $branch = self::enclosingBranch($arm, Switch_::class);
        if ($branch === null) {
            return null;
        }

        $index = self::indexOf($arm, $branch->cases);

        return $index === null ? null : [$branch, $index];
    }

    /** @return array{Node, string}|null */
    private static function ternaryArm(Ternary $branch, Node $child): ?array
    {
        if ($child === $branch->if) {
            return [$branch, 'then'];
        }

        return $child === $branch->else ? [$branch, 'else'] : null;
    }

    /**
     * @template TBranch of Node
     *
     * @param class-string<TBranch> $branchType
     *
     * @return TBranch|null the branching node the arm belongs to, or null when
     *                      the arm is not attached to one
     */
    private static function enclosingBranch(Node $arm, string $branchType): ?Node
    {
        $branch = $arm->getAttribute('parent');

        return $branch instanceof $branchType ? $branch : null;
    }

    /**
     * @param array<array-key, Node> $arms
     *
     * @return string|null the arm's position among its siblings, or null when
     *                     it is not one of them
     */
    private static function indexOf(Node $arm, array $arms): ?string
    {
        foreach ($arms as $index => $sibling) {
            if ($sibling === $arm) {
                return (string) $index;
            }
        }

        return null;
    }
}
