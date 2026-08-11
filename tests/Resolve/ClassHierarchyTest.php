<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Resolve;

use PhpTramp\Index\Indexer;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Resolve\ClassHierarchy;
use PHPUnit\Framework\TestCase;

final class ClassHierarchyTest extends TestCase
{
    private const METHODS = <<<'PHP'
        <?php

        namespace Demo;

        trait Helper
        {
            public function fromTrait(): void
            {
            }

            public function shared(): void
            {
            }
        }

        class Ancestor
        {
            public function fromAncestor(): void
            {
            }

            public function shared(): void
            {
            }
        }

        class Middle extends Ancestor
        {
            public function fromMiddle(): void
            {
            }
        }

        class Leaf extends Middle
        {
            use Helper;

            public function direct(): void
            {
            }
        }
        PHP;

    private const KINDS = <<<'PHP'
        <?php

        namespace Demo;

        interface Single {}
        class OnlySingle implements Single {}

        interface Pair {}
        class BravoPair implements Pair {}
        class AlphaPair implements Pair {}

        interface Base {}
        interface Derived extends Base {}
        class ViaDerived implements Derived {}

        abstract class AbstractRoot {}
        abstract class AbstractMid extends AbstractRoot {}
        class ConcreteLeaf extends AbstractMid {}

        trait SomeTrait {}
        enum Suit { case Hearts; }
        PHP;

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function hierarchy(string $code): ClassHierarchy
    {
        return new ClassHierarchy($this->index($code));
    }

    private function index(string $code): MethodIndex
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);

        return (new Indexer())->index([$this->file]);
    }

    public function testMethodOnFindsDirectDeclaration(): void
    {
        $method = $this->hierarchy(self::METHODS)->methodOn('Demo\Leaf', 'direct');
        self::assertSame('Demo\Leaf::direct', $method?->fqmn);
    }

    public function testMethodOnFindsViaTrait(): void
    {
        $method = $this->hierarchy(self::METHODS)->methodOn('Demo\Leaf', 'fromTrait');
        self::assertSame('Demo\Helper::fromTrait', $method?->fqmn);
    }

    public function testMethodOnFindsViaParent(): void
    {
        $method = $this->hierarchy(self::METHODS)->methodOn('Demo\Leaf', 'fromMiddle');
        self::assertSame('Demo\Middle::fromMiddle', $method?->fqmn);
    }

    public function testMethodOnFindsViaGrandparent(): void
    {
        $method = $this->hierarchy(self::METHODS)->methodOn('Demo\Leaf', 'fromAncestor');
        self::assertSame('Demo\Ancestor::fromAncestor', $method?->fqmn);
    }

    public function testMethodOnPrefersTraitOverInheritedParentMethod(): void
    {
        $method = $this->hierarchy(self::METHODS)->methodOn('Demo\Leaf', 'shared');
        self::assertSame('Demo\Helper::shared', $method?->fqmn);
    }

    public function testMethodOnUnknownClassIsNull(): void
    {
        self::assertNull($this->hierarchy(self::METHODS)->methodOn('Demo\Nonexistent', 'direct'));
    }

    public function testImplementationsOfInterfaceWithSingleImpl(): void
    {
        self::assertSame(['Demo\OnlySingle'], $this->hierarchy(self::KINDS)->implementationsOf('Demo\Single'));
    }

    public function testImplementationsOfInterfaceWithTwoImplsSorted(): void
    {
        $implementations = $this->hierarchy(self::KINDS)->implementationsOf('Demo\Pair');
        self::assertSame(['Demo\AlphaPair', 'Demo\BravoPair'], $implementations);
    }

    public function testImplementationsOfViaInterfaceExtendsChain(): void
    {
        self::assertSame(['Demo\ViaDerived'], $this->hierarchy(self::KINDS)->implementationsOf('Demo\Base'));
    }

    public function testImplementationsOfAbstractClassViaChainExcludesAbstractLinks(): void
    {
        $implementations = $this->hierarchy(self::KINDS)->implementationsOf('Demo\AbstractRoot');
        self::assertSame(['Demo\ConcreteLeaf'], $implementations);
    }

    public function testImplementationsOfUnknownTypeIsEmpty(): void
    {
        self::assertSame([], $this->hierarchy(self::KINDS)->implementationsOf('Demo\Nonexistent'));
    }

    public function testCyclicInheritanceTerminatesWithoutHanging(): void
    {
        // Malformed input: A extends B extends A. The visited set must stop both
        // the method walk and the supertype walk from looping forever.
        $hierarchy = $this->hierarchy('<?php namespace Demo; class Node extends Ring {} class Ring extends Node {}');

        self::assertNull($hierarchy->methodOn('Demo\Node', 'ghost'));
        self::assertContains('Demo\Node', $hierarchy->implementationsOf('Demo\Node'));
    }

    public function testIsAbstractTypeForEveryKind(): void
    {
        $hierarchy = $this->hierarchy(self::KINDS);

        self::assertTrue($hierarchy->isAbstractType('Demo\Single'), 'interface');
        self::assertTrue($hierarchy->isAbstractType('Demo\AbstractRoot'), 'abstract class');
        self::assertFalse($hierarchy->isAbstractType('Demo\OnlySingle'), 'concrete class');
        self::assertFalse($hierarchy->isAbstractType('Demo\SomeTrait'), 'trait');
        self::assertFalse($hierarchy->isAbstractType('Demo\Suit'), 'enum');
        self::assertFalse($hierarchy->isAbstractType('Demo\Nonexistent'), 'unknown');
    }
}
