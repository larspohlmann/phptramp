<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Resolve;

use PhpTramp\Index\ForwardSite;
use PhpTramp\Index\Indexer;
use PhpTramp\Index\MethodInfo;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ClassHierarchy;
use PhpTramp\Resolve\ExternalTarget;
use PhpTramp\Resolve\Resolution;
use PhpTramp\Resolve\ResolvedTarget;
use PhpTramp\Resolve\TruncatedResolution;
use PHPUnit\Framework\TestCase;

final class CallResolverTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function resolve(string $code, string $callerFqmn, string $param = 'p'): Resolution
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);
        $index = (new Indexer())->index([$this->file]);

        $caller = $index->get($callerFqmn);
        self::assertNotNull($caller);
        $forward = $this->firstForward($caller, $param);

        return (new CallResolver($index, new ClassHierarchy($index)))->resolve($forward, $caller);
    }

    private function firstForward(MethodInfo $caller, string $param): ForwardSite
    {
        foreach ($caller->params as $candidate) {
            if ($candidate->name === $param) {
                self::assertNotEmpty($candidate->forwards, "param {$param} has no forwards");

                return $candidate->forwards[0];
            }
        }

        self::fail("no param {$param} on {$caller->fqmn}");
    }

    public function testFunctionInIndexResolvesToTarget(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Caller { public function go(Cfg $p): void { helper($p); } } '
            . 'function helper(Cfg $x): void {}';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\helper', $resolution->fqmn);
        self::assertSame('x', $resolution->boundParam);
    }

    public function testUnknownFunctionIsExternal(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Caller { public function go(Cfg $p): void { sprintf("%s", $p); } }';

        self::assertInstanceOf(ExternalTarget::class, $this->resolve($code, 'Demo\Caller::go'));
    }

    public function testStaticCallViaClassName(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Other { public static function run(Cfg $x): void {} } '
            . 'class Caller { public function go(Cfg $p): void { Other::run($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Other::run', $resolution->fqmn);
    }

    public function testStaticCallInheritedFromParent(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Base { public static function make(Cfg $x): void {} } '
            . 'class Child extends Base { public function go(Cfg $p): void { self::make($p); } }';

        $resolution = $this->resolve($code, 'Demo\Child::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Base::make', $resolution->fqmn);
    }

    public function testStaticCallViaStaticKeyword(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Base { public static function make(Cfg $x): void {} } '
            . 'class Child extends Base { public function go(Cfg $p): void { static::make($p); } }';

        $resolution = $this->resolve($code, 'Demo\Child::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Base::make', $resolution->fqmn);
    }

    public function testStaticCallViaParentKeyword(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Base { public static function make(Cfg $x): void {} } '
            . 'class Child extends Base { public function go(Cfg $p): void { parent::make($p); } }';

        $resolution = $this->resolve($code, 'Demo\Child::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Base::make', $resolution->fqmn);
    }

    public function testFunctionCallerResolvesNamespacedFunction(): void
    {
        // The caller is itself a namespaced function; its namespace must come from
        // its own FQMN, not a declaring class (it has none).
        $code = '<?php namespace Demo; class Cfg {} '
            . 'function outer(Cfg $p): void { inner($p); } '
            . 'function inner(Cfg $x): void {}';

        $resolution = $this->resolve($code, 'Demo\outer');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\inner', $resolution->fqmn);
    }

    public function testMethodOnThis(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Caller { public function go(Cfg $p): void { $this->sink($p); } '
            . 'public function sink(Cfg $x): void {} }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Caller::sink', $resolution->fqmn);
    }

    public function testMethodViaParamDeclaredType(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(Cfg $x): void {} } '
            . 'class Caller { public function go(Service $svc, Cfg $p): void { $svc->handle($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Service::handle', $resolution->fqmn);
    }

    public function testMethodViaInlineNewReceiver(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(Cfg $x): void {} } '
            . 'class Caller { public function go(Cfg $p): void { (new Service())->handle($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Service::handle', $resolution->fqmn);
    }

    public function testInterfaceWithSingleImplementationResolvesIntoIt(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'interface Handler { public function handle(Cfg $x): void; } '
            . 'class RealHandler implements Handler { public function handle(Cfg $x): void {} } '
            . 'class Caller { public function go(Handler $h, Cfg $p): void { $h->handle($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\RealHandler::handle', $resolution->fqmn);
    }

    public function testInterfaceWithTwoImplementationsTruncatesWithCount(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'interface Handler { public function handle(Cfg $x): void; } '
            . 'class OneHandler implements Handler { public function handle(Cfg $x): void {} } '
            . 'class TwoHandler implements Handler { public function handle(Cfg $x): void {} } '
            . 'class Caller { public function go(Handler $h, Cfg $p): void { $h->handle($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(TruncatedResolution::class, $resolution);
        self::assertStringContainsString('2', $resolution->reason);
    }

    public function testInterfaceWithNoImplementationIsExternal(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'interface Handler { public function handle(Cfg $x): void; } '
            . 'class Caller { public function go(Handler $h, Cfg $p): void { $h->handle($p); } }';

        self::assertInstanceOf(ExternalTarget::class, $this->resolve($code, 'Demo\Caller::go'));
    }

    public function testUnionTypedReceiverTruncatesAsUnresolvableType(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class A { public function handle(Cfg $x): void {} } class B {} '
            . 'class Caller { public function go(A|B $u, Cfg $p): void { $u->handle($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(TruncatedResolution::class, $resolution);
        self::assertSame('unresolvable type', $resolution->reason);
    }

    public function testStaticCallOnClassNotInIndexIsExternal(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Caller { public function go(Cfg $p): void { \Vendor\Thing::run($p); } }';

        self::assertInstanceOf(ExternalTarget::class, $this->resolve($code, 'Demo\Caller::go'));
    }

    public function testRawReceiverTruncatesAsUntyped(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(Cfg $x): void {} } '
            . 'class Caller { public function go(Cfg $p): void { $this->make()->handle($p); } '
            . 'public function make(): Service { return new Service(); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(TruncatedResolution::class, $resolution);
        self::assertSame('untyped receiver', $resolution->reason);
    }

    public function testNewWithConstructorResolvesToConstructor(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Mailer { public function __construct(Cfg $x) {} } '
            . 'class Caller { public function go(Cfg $p): void { new Mailer($p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('Demo\Mailer::__construct', $resolution->fqmn);
        self::assertSame('x', $resolution->boundParam);
    }

    public function testNewWithoutConstructorIsExternal(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Plain {} '
            . 'class Caller { public function go(Cfg $p): void { new Plain($p); } }';

        self::assertInstanceOf(ExternalTarget::class, $this->resolve($code, 'Demo\Caller::go'));
    }

    public function testNamedArgumentBindsByName(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(Cfg $cfg): void {} } '
            . 'class Caller { public function go(Cfg $p): void { (new Service())->handle(cfg: $p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('cfg', $resolution->boundParam);
    }

    public function testPositionalArgumentPastVariadicBindsToVariadic(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(int $a, Cfg ...$rest): void {} } '
            . 'class Caller { public function go(Cfg $p): void { (new Service())->handle(1, 2, $p); } }';

        $resolution = $this->resolve($code, 'Demo\Caller::go');
        self::assertInstanceOf(ResolvedTarget::class, $resolution);
        self::assertSame('rest', $resolution->boundParam);
    }

    public function testPositionalArgumentBeyondLastParamTruncates(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { public function handle(int $a): void {} } '
            . 'class Caller { public function go(Cfg $p): void { (new Service())->handle(1, $p); } }';

        self::assertInstanceOf(TruncatedResolution::class, $this->resolve($code, 'Demo\Caller::go'));
    }
}
