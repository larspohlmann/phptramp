<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\ChainBuilder;
use PhpTramp\Chain\Finding;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Index\Indexer;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ClassHierarchy;
use PHPUnit\Framework\TestCase;

final class ChainBuilderTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @return list<Finding>
     */
    private function build(string $code): array
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);
        $index = (new Indexer())->index([$this->file]);
        $resolver = new CallResolver($index, new ClassHierarchy($index));

        return (new ChainBuilder($resolver))->build($index);
    }

    public function testReadmeThreeHopChain(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { public function process(Cfg $config): void { (new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { new Mailer($config); } } '
            . 'class Mailer { private Cfg $c; '
            . 'public function __construct(Cfg $config) { $this->c = $config; } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);

        $finding = $findings[0];
        self::assertSame('config', $finding->param);
        self::assertSame('Demo\Controller::handle', $finding->origin);
        self::assertSame('Demo\Mailer::__construct', $finding->terminal);
        self::assertSame(TerminalKind::Stored, $finding->terminalKind);
        self::assertSame(3, $finding->hops);
        self::assertSame(4, $finding->classes);
        self::assertCount(4, $finding->chain);
        self::assertSame([
            'Demo\Controller::handle: method:process -> Demo\ServiceA::process',
            'Demo\ServiceA::process: method:run -> Demo\ServiceB::run',
            'Demo\ServiceB::run: new:Demo\Mailer -> Demo\Mailer::__construct',
        ], $finding->trace);
    }

    public function testUseAtSecondHopEndsChain(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { public function step(Cfg $p): void { $p->run(); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->hops);
        self::assertSame('Demo\B::step', $findings[0]->terminal);
        self::assertSame(TerminalKind::Used, $findings[0]->terminalKind);
    }

    public function testBranchToTwoCalleesYieldsTwoFindings(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->left($p); (new C())->right($p); } } '
            . 'class B { public function left(Cfg $p): void { $p->x(); } } '
            . 'class C { public function right(Cfg $p): void { $p->y(); } }';

        $findings = $this->build($code);
        self::assertCount(2, $findings);
        self::assertSame('Demo\A::go', $findings[0]->origin);
        self::assertSame('Demo\A::go', $findings[1]->origin);
        $terminals = [$findings[0]->terminal, $findings[1]->terminal];
        sort($terminals);
        self::assertSame(['Demo\B::left', 'Demo\C::right'], $terminals);
    }

    public function testChainStartsAtTheTrueOriginRegardlessOfDeclarationOrder(): void
    {
        // The origin method is declared last, after a mid-chain node. Proper
        // origin detection (no incoming edge) must still start the chain at it —
        // not at whichever pure-forward node is indexed first.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Mid { public function relay(Cfg $config): void { (new Sink())->store($config); } } '
            . 'class Sink { public function store(Cfg $config): void { $config->x(); } } '
            . 'class Origin { public function start(Cfg $config): void { (new Mid())->relay($config); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame('Demo\Origin::start', $findings[0]->origin);
        self::assertSame(2, $findings[0]->hops);
    }

    public function testUsedTerminalThatAlsoForwardsDoesNotContinuePastIt(): void
    {
        // B::step reads $p (so it is a Used terminal) but also forwards it. The
        // chain ends at the use; it must not walk on into C.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { public function step(Cfg $p): void { $p->x(); (new C())->y($p); } } '
            . 'class C { public function y(Cfg $p): void { $p->z(); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame('Demo\B::step', $findings[0]->terminal);
        self::assertSame(TerminalKind::Used, $findings[0]->terminalKind);
    }

    public function testByRefCalleeTerminatesAsByRef(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->mut($p); } } '
            . 'class B { public function mut(Cfg &$p): void {} }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(TerminalKind::ByRef, $findings[0]->terminalKind);
        self::assertSame('Demo\B::mut', $findings[0]->terminal);
    }

    public function testUnusedCalleeTerminatesAsUnused(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->ignore($p); } } '
            . 'class B { public function ignore(Cfg $p): void {} }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(TerminalKind::Unused, $findings[0]->terminalKind);
    }

    public function testForwardIntoExternalFunctionTerminatesAsExternal(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { sprintf("%s", $p); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(TerminalKind::External, $findings[0]->terminalKind);
        self::assertNull($findings[0]->terminal);
        self::assertCount(1, $findings[0]->chain);
        self::assertSame(
            ['Demo\A::go: function:sprintf -> external: function not in index'],
            $findings[0]->trace,
        );
    }

    public function testMultipleImplementationsTruncateWithNote(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'interface Handler { public function handle(Cfg $p): void; } '
            . 'class H1 implements Handler { public function handle(Cfg $p): void { $p->x(); } } '
            . 'class H2 implements Handler { public function handle(Cfg $p): void { $p->y(); } } '
            . 'class A { public function go(Handler $h, Cfg $p): void { $h->handle($p); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(TerminalKind::Truncated, $findings[0]->terminalKind);
        self::assertNull($findings[0]->terminal);
        self::assertContains('truncated: 2 implementations', $findings[0]->notes);
        self::assertSame(
            ['Demo\A::go: method:handle -> truncated: 2 implementations'],
            $findings[0]->trace,
        );
    }

    public function testDirectRecursionIsReportedOnceAndTerminates(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { $this->go($p); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame('Demo\A::go', $findings[0]->origin);
        self::assertContains('(recursive)', $findings[0]->notes);
    }

    public function testEntrylessCycleIsReportedOnce(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function a(Cfg $p): void { $this->b($p); } '
            . 'public function b(Cfg $p): void { $this->a($p); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame('Demo\A::a', $findings[0]->origin);
        self::assertContains('(recursive)', $findings[0]->notes);
    }

    public function testChainBuilderDoesNotApplyLimit(): void
    {
        // A single-hop chain must still be returned; thresholding is the caller's job.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { public function step(Cfg $p): void { $p->run(); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(1, $findings[0]->hops);
    }

    public function testFindingsAreSortedByOriginLocationThenParam(): void
    {
        // Two independent origins in the same file; ZZ declared first but on a
        // later line, so line-order (not declaration text) drives the sort.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Early { public function go(Cfg $p): void { (new Sink())->take($p); } } '
            . 'class Late { public function go(Cfg $p): void { (new Sink())->take($p); } } '
            . 'class Sink { public function take(Cfg $p): void { $p->x(); } }';

        $findings = $this->build($code);
        self::assertCount(2, $findings);
        self::assertSame('Demo\Early::go', $findings[0]->origin);
        self::assertSame('Demo\Late::go', $findings[1]->origin);
    }

    public function testFindingsFromOneOriginAreSortedByParamName(): void
    {
        // Both params originate on the same method (same file and line), so only
        // the parameter name breaks the tie; 'aaa' must sort before 'bbb' even
        // though 'bbb' is declared first.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $bbb, Cfg $aaa): void { (new S())->x($bbb); (new S())->y($aaa); } } '
            . 'class S { public function x(Cfg $p): void { $p->u(); } public function y(Cfg $p): void { $p->u(); } }';

        $findings = $this->build($code);
        self::assertCount(2, $findings);
        self::assertSame('aaa', $findings[0]->param);
        self::assertSame('bbb', $findings[1]->param);
    }
}
