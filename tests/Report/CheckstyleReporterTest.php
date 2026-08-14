<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\CheckstyleReporter;
use PhpTramp\Report\Paths;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class CheckstyleReporterTest extends TestCase
{
    /**
     * Working directory that is not a prefix of any fixture file path below, so
     * Paths::relativize leaves the already-relative "src/..." files unchanged.
     */
    private function paths(): Paths
    {
        return new Paths('/not/matching');
    }

    public function testRendersSingleErrorFindingInPinnedSkeleton(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20),
            new Hop('Demo\ServiceB::run', 'Demo\ServiceB', 'src/Demo.php', 24, 26),
            new Hop('Demo\Mailer::__construct', 'Demo\Mailer', 'src/Demo.php', 32, null),
        ];
        $finding = new Finding(
            'config',
            'Demo\Controller::handle',
            'Demo\Mailer::__construct',
            TerminalKind::Stored,
            3,
            $chain,
            4,
            [],
            [],
        );

        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<checkstyle version="3.0">' . "\n"
            . '  <file name="src/Demo.php">' . "\n"
            . '    <error line="12" severity="error" message="$config: 3 pass-through hops across 4 classes '
            . '(terminal: Demo\Mailer::__construct [stored])" source="phptramp.trampData"/>' . "\n"
            . '  </file>' . "\n"
            . '</checkstyle>' . "\n";

        $reporter = new CheckstyleReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testRendersWarningSeverityLabel(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 2, $chain, 2, [], []);

        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<checkstyle version="3.0">' . "\n"
            . '  <file name="src/A.php">' . "\n"
            . '    <error line="5" severity="warning" message="$p: 2 pass-through hops across 2 classes '
            . '(terminal: Demo\C::sink [used])" source="phptramp.trampData"/>' . "\n"
            . '  </file>' . "\n"
            . '</checkstyle>' . "\n";

        $reporter = new CheckstyleReporter(new Thresholds(3, 2), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testGroupsFindingsByOriginFilePreservingFirstSeenAndInputOrder(): void
    {
        $aFinding = new Finding(
            'p',
            'Demo\A::go',
            'Demo\A::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
                new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
            ],
            1,
            [],
            [],
        );
        $bFinding = new Finding(
            'q',
            'Demo\B::go',
            'Demo\B::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\B::go', 'Demo\B', 'src/B.php', 15, 17),
                new Hop('Demo\B::sink', 'Demo\B', 'src/B.php', 19, null),
            ],
            1,
            [],
            [],
        );
        $secondAFinding = new Finding(
            'r',
            'Demo\A::again',
            'Demo\A::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\A::again', 'Demo\A', 'src/A.php', 25, 27),
                new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 29, null),
            ],
            1,
            [],
            [],
        );

        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<checkstyle version="3.0">' . "\n"
            . '  <file name="src/A.php">' . "\n"
            . '    <error line="5" severity="error" message="$p: 1 pass-through hop across 1 class '
            . '(terminal: Demo\A::sink [used])" source="phptramp.trampData"/>' . "\n"
            . '    <error line="25" severity="error" message="$r: 1 pass-through hop across 1 class '
            . '(terminal: Demo\A::sink [used])" source="phptramp.trampData"/>' . "\n"
            . '  </file>' . "\n"
            . '  <file name="src/B.php">' . "\n"
            . '    <error line="15" severity="error" message="$q: 1 pass-through hop across 1 class '
            . '(terminal: Demo\B::sink [used])" source="phptramp.trampData"/>' . "\n"
            . '  </file>' . "\n"
            . '</checkstyle>' . "\n";

        $reporter = new CheckstyleReporter(new Thresholds(1, null), $this->paths());

        self::assertSame($expected, $reporter->render([$aFinding, $bFinding, $secondAFinding]));
    }

    public function testEscapesMessageXmlEntities(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B<T>::sink', 'Demo\B', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\B<T>::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<checkstyle version="3.0">' . "\n"
            . '  <file name="src/A.php">' . "\n"
            . '    <error line="5" severity="error" message="$p: 1 pass-through hop across 1 class '
            . '(terminal: Demo\B&lt;T&gt;::sink [used])" source="phptramp.trampData"/>' . "\n"
            . '  </file>' . "\n"
            . '</checkstyle>' . "\n";

        $reporter = new CheckstyleReporter(new Thresholds(1, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testEmptyRunRendersSkeletonWithNoFileElements(): void
    {
        $expected = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<checkstyle version="3.0">' . "\n"
            . '</checkstyle>' . "\n";

        $reporter = new CheckstyleReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([]));
    }
}
