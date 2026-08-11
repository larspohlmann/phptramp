<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpTramp\Index\Indexer;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParamInfo;
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

    public function testTwoForwardsRecordTwoSites(): void
    {
        $p = $this->classify('a($p); b($p);')['p'];
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

    public function testUntouchedParamIsUnused(): void
    {
        self::assertSame(ParamFate::Unused, $this->classify('log(1);')['p']->fate);
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
}
