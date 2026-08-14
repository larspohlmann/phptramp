<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Chain\TerminalKindFilter;
use PhpTramp\Console\InvalidArgsException;
use PHPUnit\Framework\TestCase;

final class TerminalKindFilterTest extends TestCase
{
    public function testExcludedKindIsDroppedAndEveryOtherKindSurvives(): void
    {
        $findings = [$this->finding(TerminalKind::Stored), $this->finding(TerminalKind::Used)];

        $kept = TerminalKindFilter::fromNames(['stored'])->filter($findings);

        self::assertCount(1, $kept);
        self::assertSame(TerminalKind::Used, $kept[0]->terminalKind);
    }

    public function testEmptyExclusionListKeepsEverything(): void
    {
        $findings = [$this->finding(TerminalKind::Stored), $this->finding(TerminalKind::Used)];

        self::assertSame($findings, TerminalKindFilter::fromNames([])->filter($findings));
    }

    public function testUnknownTerminalKindIsRejected(): void
    {
        try {
            TerminalKindFilter::fromNames(['constructed']);
            self::fail('Expected InvalidArgsException was not thrown.');
        } catch (InvalidArgsException $exception) {
            self::assertSame(
                'unknown terminal kind: constructed '
                    . '(expected used|stored|&-terminated|unused-end|external|truncated)',
                $exception->getMessage(),
            );
        }
    }

    private function finding(TerminalKind $kind): Finding
    {
        $chain = [new Hop('Demo\A::go', 'Demo\A', '/repo/src/Demo.php', 1, 2)];

        return new Finding('config', 'Demo\A::go', null, $kind, 1, $chain, 1, [], []);
    }
}
