<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpTramp\Index\CalleeKind;
use PhpTramp\Index\Indexer;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParamInfo;
use PhpTramp\Index\UsageClassifier;
use PHPUnit\Framework\TestCase;

final class UsageClassifierTest extends TestCase
{
    /** @return array<string, ParamInfo> keyed by param name, for method C::m */
    private function classify(string $body, string $params = 'Cfg $p'): array
    {
        $code = "<?php class Cfg {} class C { public function m({$params}) { {$body} } }";
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('C::m');
            self::assertNotNull($method);
            $byName = [];
            foreach ($method->params as $param) {
                $byName[$param->name] = $param;
            }

            return $byName;
        } finally {
            unlink($file);
        }
    }

    public function testPureSingleForwardIsPureForward(): void
    {
        $p = $this->classify('other($p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(1, $p->forwards);
    }

    public function testForwardPlusPropertyReadIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('log($p->env); other($p);')['p']->fate);
    }

    public function testMethodCallOnParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$p->run();')['p']->fate);
    }

    public function testAssignmentFromParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$x = $p; other($p);')['p']->fate);
    }

    public function testReassignmentOfParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$p = null; other($p);')['p']->fate);
    }

    public function testReturnIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('return $p;')['p']->fate);
    }

    public function testStringInterpolationIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('echo "x{$p}";')['p']->fate);
    }

    public function testClosureCaptureIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$f = function () use ($p) { other($p); };')['p']->fate);
    }

    public function testArrowFnBodyReferenceIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$f = fn () => other($p);')['p']->fate);
    }

    public function testByRefParamIsByRefTerminated(): void
    {
        self::assertSame(ParamFate::ByRefTerminated, $this->classify('other($p);', 'Cfg &$p')['p']->fate);
    }

    public function testVariadicSpreadForwardIsPureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('other(...$p);', 'Cfg ...$p')['p']->fate);
    }

    public function testNamedArgForwardRecordsArgName(): void
    {
        $p = $this->classify('other(cfg: $p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertSame('cfg', $p->forwards[0]->argKey);
    }

    public function testPositionalForwardRecordsPosition(): void
    {
        $p = $this->classify('other(1, $p);')['p'];
        self::assertSame(1, $p->forwards[0]->argKey);
    }

    public function testTwoForwardsToTheSameCalleeRecordTwoSites(): void
    {
        $p = $this->classify('a($p); a($p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardIntoNestedCallIsForwardToInnerCallee(): void
    {
        // $p is an argument of wrap(), not of outer(): the edge goes to wrap().
        $p = $this->classify('outer(wrap($p));')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(1, $p->forwards);
    }

    public function testConstructorArgForwardIsPureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('new Cfg($p);')['p']->fate);
    }

    public function testStaticCallForwardRecordsStaticCallee(): void
    {
        $p = $this->classify('Cfg::other($p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertSame(CalleeKind::StaticCall, $p->forwards[0]->callee->kind);
        self::assertSame('other', $p->forwards[0]->callee->name);
        self::assertSame('Cfg', $p->forwards[0]->callee->receiverHint);
    }

    public function testNullsafeMethodCallForwardIsPureForward(): void
    {
        $p = $this->classify('$svc?->handle($p);', 'Cfg $p, C $svc')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertSame(CalleeKind::Method, $p->forwards[0]->callee->kind);
    }

    public function testMethodForwardRecordsCalleeAndReceiverHint(): void
    {
        $p = $this->classify('$svc->handle($p);', 'Cfg $p, C $svc')['p'];
        self::assertSame(CalleeKind::Method, $p->forwards[0]->callee->kind);
        self::assertSame('handle', $p->forwards[0]->callee->name);
        self::assertSame('svc', $p->forwards[0]->callee->receiverHint);
    }

    public function testFunctionForwardRecordsFuncCallee(): void
    {
        $p = $this->classify('other($p);')['p'];
        self::assertSame(CalleeKind::Func, $p->forwards[0]->callee->kind);
        self::assertSame('other', $p->forwards[0]->callee->name);
    }

    public function testDynamicFunctionCalleeIsUsed(): void
    {
        // $fn($p): the callee is not statically nameable, so we cannot call it a
        // mindless hop — the value leaves analyzable code.
        self::assertSame(ParamFate::Used, $this->classify('$fn = "x"; $fn($p);')['p']->fate);
    }

    public function testDynamicNewCalleeIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('new $cls($p);', 'Cfg $p, string $cls')['p']->fate);
    }

    public function testFirstPositionalForwardRecordsPositionZero(): void
    {
        self::assertSame(0, $this->classify('other($p, 2);')['p']->forwards[0]->argKey);
    }

    public function testNewInstantiationForwardRecordsResolvedClass(): void
    {
        $p = $this->classify('new Cfg($p);')['p'];
        self::assertSame(CalleeKind::Instantiation, $p->forwards[0]->callee->kind);
        self::assertSame('Cfg', $p->forwards[0]->callee->name);
    }

    public function testAbstractMethodWithoutBodyIsUnused(): void
    {
        $code = '<?php class Cfg {} abstract class A { abstract public function m(Cfg $p): void; }';
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('A::m');
            self::assertNotNull($method);
            self::assertSame(ParamFate::Unused, $method->params[0]->fate);
        } finally {
            unlink($file);
        }
    }

    public function testUnionTypedParamHasNullType(): void
    {
        $p = $this->classify('log(1);', 'Cfg|C $p')['p'];
        self::assertNull($p->type);
    }

    public function testClassifyConnectsParentsWithoutPriorParentTraversal(): void
    {
        // UsageClassifier is self-contained: given only name-resolved statements
        // (no ParentConnectingVisitor run beforehand), it still detects forwards.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse('<?php class Cfg {} class C { public function m(Cfg $p) { other($p); } }');
        self::assertNotNull($ast);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($ast);

        $class = $ast[1];
        self::assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $class);
        $method = $class->stmts[0];
        self::assertInstanceOf(\PhpParser\Node\Stmt\ClassMethod::class, $method);

        $params = (new UsageClassifier())->classify($method->params, $method->stmts);
        self::assertSame(ParamFate::PureForward, $params[0]->fate);
        self::assertCount(1, $params[0]->forwards);
    }

    public function testUntouchedParamIsUnused(): void
    {
        self::assertSame(ParamFate::Unused, $this->classify('log(1);')['p']->fate);
    }

    public function testPropertyStoreOnlyIsStoredOnly(): void
    {
        $p = $this->classify('$this->x = $p;')['p'];
        self::assertSame(ParamFate::Used, $p->fate);
        self::assertTrue($p->storedOnly);
    }

    public function testStorePlusReadIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('$this->x = $p; log($p->env);')['p']->storedOnly);
    }

    public function testPureForwardIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('other($p);')['p']->storedOnly);
    }

    public function testFanOutIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('a($p); b($p);')['p']->storedOnly);
    }

    public function testPlainForwardingUseIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('log($p);')['p']->storedOnly);
    }

    public function testUntouchedParamIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('log(1);')['p']->storedOnly);
    }

    public function testStoreThatAlsoForwardsIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('$this->x = $p; other($p);')['p']->storedOnly);
    }

    public function testClosureCaptureIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('$f = function () use ($p) { other($p); };')['p']->storedOnly);
    }

    public function testArrowFnReferenceIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('$f = fn () => other($p);')['p']->storedOnly);
    }

    public function testByRefParamIsNotStoredOnly(): void
    {
        self::assertFalse($this->classify('other($p);', 'Cfg &$p')['p']->storedOnly);
    }

    public function testPromotedConstructorPropertyIsStoredOnly(): void
    {
        $code = '<?php class Cfg {} class Mailer { '
            . 'public function __construct(private Cfg $p) {} }';
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('Mailer::__construct');
            self::assertNotNull($method);
            self::assertTrue($method->params[0]->storedOnly);
        } finally {
            unlink($file);
        }
    }

    public function testConstructorPropertyStorageIsUsed(): void
    {
        $code = '<?php class Cfg {} class Mailer { private Cfg $c; '
            . 'public function __construct(Cfg $p) { $this->c = $p; } }';
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('Mailer::__construct');
            self::assertNotNull($method);
            self::assertSame(ParamFate::Used, $method->params[0]->fate);
        } finally {
            unlink($file);
        }
    }

    public function testPromotedConstructorPropertyIsUsed(): void
    {
        $code = '<?php class Cfg {} class Mailer { '
            . 'public function __construct(private Cfg $p) {} }';
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('Mailer::__construct');
            self::assertNotNull($method);
            self::assertSame(ParamFate::Used, $method->params[0]->fate);
        } finally {
            unlink($file);
        }
    }

    public function testTwoDistinctFunctionCalleesAreFanOut(): void
    {
        $p = $this->classify('a($p); b($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testTwoDistinctMethodsOnThisAreFanOut(): void
    {
        $p = $this->classify('$this->a($p); $this->b($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testParentCallBesideACallOnThisIsFanOut(): void
    {
        // Conservative: rule 4's "same object" exemption spares `parent::` from
        // the hop count, it does not spare it from fan-out. The base-class chain
        // is reported from the base method as its own origin, so nothing is lost.
        $p = $this->classify('parent::__construct($p); $this->init($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testTwoDistinctParentCallsAreFanOut(): void
    {
        $p = $this->classify('parent::__construct($p); parent::init($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testSameMethodOnTwoReceiversIsFanOut(): void
    {
        $p = $this->classify('$x->save($p); $y->save($p);', 'Cfg $p, Repo $x, Repo $y')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testTwoDistinctInstantiationsAreFanOut(): void
    {
        $p = $this->classify('new A($p); new B($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testConditionalForwardBesideUnconditionalOneIsFanOut(): void
    {
        $p = $this->classify('if ($x) { a($p); } b($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardInBranchConditionIsFanOutWithForwardInItsBody(): void
    {
        // The condition is not an arm: it runs before whichever arm is taken.
        $p = $this->classify('if (a($p)) { b($p); }')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardInElseifConditionIsFanOutWithForwardInAnotherArm(): void
    {
        // An elseif condition is evaluated on the way into the else arm.
        $p = $this->classify('if ($a) { } elseif (check($p)) { } else { fallback($p); }')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardInMatchArmConditionIsFanOutWithForwardInAnotherArm(): void
    {
        // A match arm's condition is evaluated on the way into every later arm.
        $p = $this->classify('match ($x) { probe($p) => 1, default => handle($p) };')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardInSwitchCaseConditionIsFanOutWithForwardInAnotherCase(): void
    {
        // A case expression is evaluated on the way into every later case.
        $p = $this->classify('switch ($x) { case a($p): break; default: b($p); }')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testTwoDistinctCalleesInsideOneArmAreFanOut(): void
    {
        $p = $this->classify('if ($x) { a($p); b($p); }')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardAfterADispatcherIsFanOutWithEveryArm(): void
    {
        $p = $this->classify('match ($x) { 1 => a($p), default => b($p) }; c($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(3, $p->forwards);
    }

    public function testCoalesceOperandsAreFanOut(): void
    {
        // `??` short-circuits but does not branch: both operands are one path.
        $p = $this->classify('$r = a($p) ?? b($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardInsideLoopBesideForwardAfterItIsFanOut(): void
    {
        $p = $this->classify('foreach ($xs as $x) { a($p); } b($p);')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testVariadicSpreadToDistinctCalleesIsFanOut(): void
    {
        $p = $this->classify('a(...$p); b(...$p);', 'Cfg ...$p')['p'];
        self::assertSame(ParamFate::FanOut, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testSameCalleeWithOtherArgumentsDifferingIsPureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('a($p, 1); a($p, 2);')['p']->fate);
    }

    public function testForwardsInIfAndElseArmsArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('if ($x) { a($p); } else { b($p); }')['p']->fate,
        );
    }

    public function testForwardsInIfElseifAndElseArmsArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('if ($x) { a($p); } elseif ($y) { b($p); } else { c($p); }')['p']->fate,
        );
    }

    public function testForwardsInTwoDifferentElseifArmsArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('if ($x) { a($p); } elseif ($y) { b($p); } elseif ($z) { c($p); }')['p']->fate,
        );
    }

    public function testForwardsInMatchArmsArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('match ($x) { 1 => a($p), default => b($p) };')['p']->fate,
        );
    }

    public function testForwardsInSwitchCasesArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('switch ($x) { case 1: a($p); break; default: b($p); }')['p']->fate,
        );
    }

    public function testForwardsInTernaryArmsArePureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('$r = $x ? a($p) : b($p);')['p']->fate);
    }

    public function testForwardsInNestedExclusiveArmsArePureForward(): void
    {
        self::assertSame(
            ParamFate::PureForward,
            $this->classify('if ($x) { if ($y) { a($p); } else { b($p); } } else { c($p); }')['p']->fate,
        );
    }
}
