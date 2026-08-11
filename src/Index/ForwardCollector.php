<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks one method body classifying every occurrence of a single parameter as
 * either a pure whole-argument forward or a real use. Subtrees that open a new
 * scope (closures, arrow functions, nested classes/functions) are not
 * descended into; instead the frozen closure/arrow-capture rules are applied
 * at the boundary. Requires the `parent` attribute to be set on visited nodes.
 */
final class ForwardCollector extends NodeVisitorAbstract
{
    public bool $used = false;

    /** @var list<ForwardSite> */
    public array $forwards = [];

    private bool $sawStore = false;

    private bool $sawNonStore = false;

    public function __construct(
        private readonly string $name,
        private readonly bool $variadic,
    ) {
    }

    /** True when every occurrence of the parameter is a `$this->prop = $p` store. */
    public function storesOnly(): bool
    {
        return $this->sawStore && ! $this->sawNonStore;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Closure) {
            $this->handleClosure($node);

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof ArrowFunction) {
            $this->handleArrowFunction($node);

            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof ClassLike || $node instanceof Function_) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Variable && $node->name === $this->name) {
            $this->handleVariable($node);
        }

        return null;
    }

    private function handleClosure(Closure $node): void
    {
        foreach ($node->uses as $use) {
            if ($use->var->name === $this->name) {
                $this->markUse(false);
            }
        }
    }

    private function handleArrowFunction(ArrowFunction $node): void
    {
        $hit = (new NodeFinder())->findFirst(
            $node->expr,
            fn (Node $n): bool => $n instanceof Variable && $n->name === $this->name,
        );
        if ($hit !== null) {
            $this->markUse(false);
        }
    }

    private function handleVariable(Variable $node): void
    {
        $forward = $this->asForward($node);
        if ($forward === null) {
            $this->markUse($this->isThisPropertyStore($node));

            return;
        }

        $this->forwards[] = $forward;
        $this->sawNonStore = true;
    }

    private function markUse(bool $isStore): void
    {
        $this->used = true;
        if ($isStore) {
            $this->sawStore = true;

            return;
        }

        $this->sawNonStore = true;
    }

    private function isThisPropertyStore(Variable $var): bool
    {
        $assign = $var->getAttribute('parent');
        if (! $assign instanceof Assign || $assign->expr !== $var) {
            return false;
        }

        $target = $assign->var;

        return $target instanceof PropertyFetch
            && $target->var instanceof Variable
            && $target->var->name === 'this';
    }

    private function asForward(Variable $var): ?ForwardSite
    {
        $arg = $var->getAttribute('parent');
        if (! $arg instanceof Arg || $arg->value !== $var) {
            return null;
        }
        if ($arg->unpack && ! $this->variadic) {
            return null;
        }

        $call = $arg->getAttribute('parent');
        if (! $call instanceof CallLike) {
            return null;
        }

        $callee = $this->calleeRef($call);
        if ($callee === null) {
            return null;
        }

        return new ForwardSite($callee, $this->argKey($arg, $call), $var->getStartLine());
    }

    private function calleeRef(CallLike $call): ?CalleeRef
    {
        return match (true) {
            $call instanceof FuncCall => $call->name instanceof Name
                ? new CalleeRef(CalleeKind::Func, $call->name->toString())
                : null,
            $call instanceof New_ => $call->class instanceof Name
                ? new CalleeRef(CalleeKind::Instantiation, $call->class->toString())
                : null,
            $call instanceof StaticCall => $call->class instanceof Name && $call->name instanceof Identifier
                ? new CalleeRef(CalleeKind::StaticCall, $call->name->toString(), $call->class->toString())
                : null,
            $call instanceof MethodCall || $call instanceof NullsafeMethodCall => $call->name instanceof Identifier
                ? new CalleeRef(CalleeKind::Method, $call->name->toString(), $this->receiverHint($call->var))
                : null,
            default => null,
        };
    }

    private function receiverHint(Expr $receiver): string
    {
        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return $receiver->name;
        }
        if ($receiver instanceof New_ && $receiver->class instanceof Name) {
            return $receiver->class->toString();
        }

        return 'raw';
    }

    /**
     * @return int|string positional index, or the name for a named argument
     */
    private function argKey(Arg $arg, CallLike $call): int|string
    {
        if ($arg->name !== null) {
            return $arg->name->toString();
        }

        $position = 0;
        foreach ($call->getRawArgs() as $index => $candidate) {
            if ($candidate === $arg) {
                $position = $index;
                break;
            }
        }

        return $position;
    }
}
