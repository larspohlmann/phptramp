<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpTramp\Index\BranchArmPath;
use PHPUnit\Framework\TestCase;

final class BranchArmPathTest extends TestCase
{
    /**
     * Pins the symmetry of `isExclusiveWith` directly: two nodes in different
     * arms exclude each other whichever of them is asked. The classifier can
     * only observe the relation through however FanOutDetector happens to
     * iterate, so this contract needs a unit test of its own.
     */
    public function testExclusivityIsSymmetric(): void
    {
        [$thenArm, $elseArm] = $this->armPathsOfIfElse();

        self::assertTrue($thenArm->isExclusiveWith($elseArm));
        self::assertTrue($elseArm->isExclusiveWith($thenArm));
    }

    /** @return array{BranchArmPath, BranchArmPath} paths of `$p` in the then and else arm */
    private function armPathsOfIfElse(): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $statements = $parser->parse('<?php if ($c) { a($p); } else { b($p); }');
        self::assertNotNull($statements);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $statements = $traverser->traverse($statements);

        $occurrences = (new NodeFinder())->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Variable && $node->name === 'p',
        );
        self::assertCount(2, $occurrences);

        return [BranchArmPath::of($occurrences[0]), BranchArmPath::of($occurrences[1])];
    }
}
