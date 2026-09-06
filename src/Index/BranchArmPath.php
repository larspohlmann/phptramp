<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\If_;

/**
 * Which arm of every enclosing branching construct a syntax node sits in. Two
 * nodes cannot both run on one execution path when some branch names them in
 * different arms.
 */
final class BranchArmPath
{
    /** @param array<int, int|string> $armByBranch arm label keyed by branching node object id */
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
     * Object ids are safe as keys and labels here because the whole method
     * body stays alive for as long as any path derived from it is compared, so
     * no id can be recycled onto a different node in between.
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
     * @return array{Node, int|string}|null the branching node the label belongs to
     *                                  and the label, or null when the child
     *                                  does not sit in an arm of the parent
     */
    private static function armOf(Node $parent, Node $child): ?array
    {
        return match (true) {
            $parent instanceof If_ => in_array($child, $parent->stmts, true) ? [$parent, 'then'] : null,
            $parent instanceof Ternary => self::ternaryArm($parent, $child),
            $parent instanceof ElseIf_,
            $parent instanceof Else_,
            $parent instanceof Case_ => self::armNode($parent, $parent->stmts, $child),
            $parent instanceof MatchArm => self::armNode($parent, [$parent->body], $child),
            default => null,
        };
    }

    /** @return array{Node, int|string}|null */
    private static function ternaryArm(Ternary $branch, Node $child): ?array
    {
        if ($child === $branch->if) {
            return [$branch, 'then'];
        }

        return $child === $branch->else ? [$branch, 'else'] : null;
    }

    /**
     * An arm that is a node of its own (`elseif`, `else`, a match arm, a case)
     * hangs off the branching node as its parent and is labelled by its own
     * identity: labels are only ever compared between arms of one branch, so
     * any value that differs per arm will do, and the object id needs no scan
     * of the arm's siblings.
     *
     * @param array<Node> $body
     *
     * @return array{Node, int|string}|null
     */
    private static function armNode(Node $arm, array $body, Node $child): ?array
    {
        $branch = $arm->getAttribute('parent');
        if (! $branch instanceof Node || ! in_array($child, $body, true)) {
            return null;
        }

        return [$branch, spl_object_id($arm)];
    }
}
