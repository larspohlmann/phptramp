<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Ignore;

use PhpTramp\Chain\ChainBuilder;
use PhpTramp\Ignore\SuppressionFilter;
use PhpTramp\Ignore\SuppressionIndex;
use PhpTramp\Ignore\SuppressionOutcome;
use PhpTramp\Index\Indexer;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ClassHierarchy;
use PHPUnit\Framework\TestCase;

final class SuppressionFilterTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testMethodLevelAttributeOnMiddleHopKillsTheChain(): void
    {
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } } '
            . 'class ServiceA { #[TrampIgnore] public function process(Cfg $config): void { '
            . '(new ServiceB())->run($config); } } '
            . 'class ServiceB { public function run(Cfg $config): void { $config->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        self::assertContains(SuppressionIndex::methodKey('Demo\ServiceA::process'), $outcome->firedKeys);
    }

    public function testParamLevelAttributeOnlyKillsChainsOfThatParam(): void
    {
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class A { public function go(#[TrampIgnore] Cfg $ignored, Cfg $kept): void { '
            . '(new B())->take($ignored); (new B())->take($kept); } } '
            . 'class B { public function take(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        self::assertSame('kept', $outcome->kept[0]->param);
        self::assertContains(
            SuppressionIndex::paramKey('Demo\A::go', 'ignored'),
            $outcome->firedKeys,
        );
    }

    public function testAttributeOnAPlainFunctionMiddleHopKillsTheChain(): void
    {
        // Function-level (not method-level) attribute suppression: recordFunction
        // must collect it, exactly like recordMethod does for class methods.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { relay($p); } } '
            . '#[TrampIgnore] function relay(Cfg $p): void { (new B())->take($p); } '
            . 'class B { public function take(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        self::assertContains(SuppressionIndex::methodKey('Demo\relay'), $outcome->firedKeys);
    }

    public function testClassLevelAttributeKillsAllChainsThroughAnyMethodOfTheClass(): void
    {
        // Two independent origins, each forwarding through a different method of
        // the suppressed class ServiceA (process, alt) into Sink. Both chains
        // must be dropped: class-level suppression covers every method.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . '#[TrampIgnore] class ServiceA { '
            . 'public function process(Cfg $config): void { (new Sink())->take($config); } '
            . 'public function alt(Cfg $config): void { (new Sink())->take($config); } } '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } '
            . 'public function handleAlt(Cfg $config): void { (new ServiceA())->alt($config); } } '
            . 'class Sink { public function take(Cfg $config): void { $config->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        self::assertContains(SuppressionIndex::methodKey('Demo\ServiceA::process'), $outcome->firedKeys);
        self::assertContains(SuppressionIndex::methodKey('Demo\ServiceA::alt'), $outcome->firedKeys);
    }

    public function testIgnoreCommentOnForwardingCallSiteKillsTheChain(): void
    {
        $code = "<?php namespace Demo; class Cfg {}\n"
            . "class A { public function go(Cfg \$p): void { (new B())->step(\$p); // phptramp-ignore\n } }\n"
            . 'class B { public function step(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        // The comment sits on the forwarding call site line (line 2 of the file).
        self::assertContains(SuppressionIndex::lineKey($this->file, 2), $outcome->firedKeys);
    }

    public function testIgnoreCommentOnDeclarationLineKillsTheChain(): void
    {
        // Comment suppression targets a hop node's own declaration line. B::step
        // must be a mid-chain hop (forwarding on to C), not the chain's terminal,
        // since terminal nodes never suppress.
        $code = "<?php namespace Demo; class Cfg {}\n"
            . "class A { public function go(Cfg \$p): void { (new B())->step(\$p); } }\n"
            . "class B { public function step(Cfg \$p): void { (new C())->use(\$p); } } // phptramp-ignore\n"
            . 'class C { public function use(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        // B::step is declared on line 3 of the file, where the comment also sits.
        self::assertContains(SuppressionIndex::lineKey($this->file, 3), $outcome->firedKeys);
    }

    public function testIgnoreCommentOnLineAboveDeclarationKillsTheChain(): void
    {
        $code = "<?php namespace Demo; class Cfg {}\n"
            . "class A { public function go(Cfg \$p): void { (new B())->step(\$p); } }\n"
            . "class B {\n"
            . "// phptramp-ignore\n"
            . "public function step(Cfg \$p): void { (new C())->use(\$p); }\n"
            . "}\n"
            . 'class C { public function use(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        // The comment is on line 4; B::step is declared on line 5, which matches
        // via the line-above-declaration rule.
        self::assertContains(SuppressionIndex::lineKey($this->file, 4), $outcome->firedKeys);
    }

    public function testIgnoreCommentOnUnrelatedLineDoesNotDropTheChain(): void
    {
        $code = "<?php namespace Demo; class Cfg {}\n"
            . "// phptramp-ignore\n"
            . "\n"
            . "class A { public function go(Cfg \$p): void { (new B())->step(\$p); } }\n"
            . "class B { public function step(Cfg \$p): void { \$p->x(); } }";

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        // No suppression entry fired - firedKeys is empty.
        self::assertSame([], $outcome->firedKeys);
    }

    public function testIgnoreMarkerInsideAStringLiteralDoesNotSuppress(): void
    {
        // The marker text appears inside a string literal on the forwarding
        // line, not in an actual PHP comment. It must not be mistaken for a
        // suppression - the finding must still be reported.
        $code = "<?php namespace Demo; class Cfg {}\n"
            . 'class A { public function go(Cfg $p): void { '
            . '$note = "see // phptramp-ignore for context"; (new B())->step($p); } } '
            . 'class B { public function step(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        self::assertSame([], $outcome->firedKeys);
    }

    public function testRenamedParamAtNonOriginHopSuppressesOnlyThatParamsChains(): void
    {
        // The attribute sits on a mid-chain hop's own (differently-named)
        // parameter, not the origin's. This proves the per-hop param name used
        // for suppression lookup comes from the hop's own param name (carried on
        // Hop::$param) rather than the origin's param name. The sibling param
        // "other", bound positionally to B::mid's un-suppressed second param,
        // must still be found.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class A { public function go(Cfg $ignored, Cfg $kept): void { '
            . '(new B())->mid($ignored, $kept); } } '
            . 'class B { public function mid(#[TrampIgnore] Cfg $renamed, Cfg $other): void { '
            . '(new C())->takeRenamed($renamed); (new C())->takeOther($other); } } '
            . 'class C { public function takeRenamed(Cfg $p): void { $p->x(); } '
            . 'public function takeOther(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        self::assertSame('kept', $outcome->kept[0]->param);
        self::assertSame('Demo\C::takeOther', $outcome->kept[0]->terminal);
        self::assertContains(
            SuppressionIndex::paramKey('Demo\B::mid', 'renamed'),
            $outcome->firedKeys,
        );
    }

    public function testShortNameAttributeMatchesWithoutImportingOurTrampIgnoreClass(): void
    {
        // No `use PhpTramp\Ignore\TrampIgnore;` here: matching is purely by the
        // attribute name's short name, so analyzed codebases are not required
        // to autoload our class.
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { #[TrampIgnore] public function step(Cfg $p): void { (new C())->use($p); } } '
            . 'class C { public function use(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        self::assertContains(SuppressionIndex::methodKey('Demo\B::step'), $outcome->firedKeys);
    }

    public function testAttributeOnTheTerminalMethodDoesNotSuppressTheChain(): void
    {
        // The terminal node is not a hop and never participates in suppression
        // (frozen rule 11; matches ChangedChainFilter's `forwardLine === null`
        // skip). A `#[TrampIgnore]` on the terminal method must leave the
        // finding reported, and its method key must not appear in firedKeys.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { public function step(Cfg $p): void { (new C())->sink($p); } } '
            . 'class C { #[TrampIgnore] public function sink(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        self::assertSame('Demo\C::sink', $outcome->kept[0]->terminal);
        self::assertNotContains(SuppressionIndex::methodKey('Demo\C::sink'), $outcome->firedKeys);
        self::assertSame([], $outcome->firedKeys);
    }

    public function testIgnoreCommentOnTheTerminalDeclarationLineDoesNotSuppressTheChain(): void
    {
        // Same rule, line-based: a `// phptramp-ignore` on the terminal's own
        // declaration line must not drop the chain. The comment is on C::sink's
        // declaration line; the finding stays.
        $code = "<?php namespace Demo; class Cfg {}\n"
            . "class A { public function go(Cfg \$p): void { (new B())->step(\$p); } }\n"
            . "class B { public function step(Cfg \$p): void { (new C())->sink(\$p); } }\n"
            . "class C { public function sink(Cfg \$p): void { \$p->x(); } } // phptramp-ignore\n";

        $outcome = $this->buildOutcome($code);
        self::assertCount(1, $outcome->kept);
        self::assertSame('Demo\C::sink', $outcome->kept[0]->terminal);
        self::assertSame([], $outcome->firedKeys);
    }

    public function testHopMatchingBothMethodAndParamSuppressionsRecordsBothKeys(): void
    {
        // A single hop carrying both a method-level and a param-level
        // #[TrampIgnore] must record both keys in firedKeys, proving
        // matchedKeysFor / matchedKeysForHop return every matched key rather
        // than only the first.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . 'class A { public function go(Cfg $p): void { (new B())->step($p); } } '
            . 'class B { #[TrampIgnore] public function step(#[TrampIgnore] Cfg $p): void { '
            . '(new C())->use($p); } } '
            . 'class C { public function use(Cfg $p): void { $p->x(); } }';

        $outcome = $this->buildOutcome($code);
        self::assertCount(0, $outcome->kept);
        self::assertContains(SuppressionIndex::methodKey('Demo\B::step'), $outcome->firedKeys);
        self::assertContains(SuppressionIndex::paramKey('Demo\B::step', 'p'), $outcome->firedKeys);
    }

    public function testFiredKeysAreDeduplicatedInFirstFiredOrder(): void
    {
        // Two findings both pass through the same suppressed method. The method
        // key must appear once in firedKeys, at the position of the first drop.
        $code = '<?php namespace Demo; use PhpTramp\Ignore\TrampIgnore; class Cfg {} '
            . '#[TrampIgnore] class ServiceA { '
            . 'public function process(Cfg $config): void { (new Sink())->take($config); } '
            . 'public function alt(Cfg $config): void { (new Sink())->take($config); } } '
            . 'class Controller { public function handle(Cfg $config): void { (new ServiceA())->process($config); } '
            . 'public function handleAlt(Cfg $config): void { (new ServiceA())->alt($config); } } '
            . 'class Sink { public function take(Cfg $config): void { $config->x(); } }';

        $outcome = $this->buildOutcome($code);
        $methodKey = SuppressionIndex::methodKey('Demo\ServiceA::process');
        $altKey = SuppressionIndex::methodKey('Demo\ServiceA::alt');
        $methodPositions = array_keys($outcome->firedKeys, $methodKey, true);
        $altPositions = array_keys($outcome->firedKeys, $altKey, true);
        self::assertCount(1, $methodPositions, 'method key deduplicated');
        self::assertCount(1, $altPositions, 'alt key deduplicated');
        self::assertSame([$methodKey, $altKey], $outcome->firedKeys);
    }

    private function buildOutcome(string $code): SuppressionOutcome
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);
        $index = (new Indexer())->index([$this->file]);
        $resolver = new CallResolver($index, new ClassHierarchy($index));
        $findings = (new ChainBuilder($resolver))->build($index);

        return (new SuppressionFilter($index->suppressions()))->filter($findings);
    }
}
