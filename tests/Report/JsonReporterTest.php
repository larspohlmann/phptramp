<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\JsonReporter;
use PhpTramp\Report\Paths;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class JsonReporterTest extends TestCase
{
    /**
     * Working directory that is not a prefix of any fixture file path below, so
     * Paths::relativize leaves the already-relative "src/..." files unchanged.
     * Paths itself is covered by PathsTest.
     */
    private function paths(): Paths
    {
        return new Paths('/not/matching');
    }

    public function testRendersExactDocumentForThreeHopStoredErrorCase(): void
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

        $expected = <<<'JSON'
            {
                "limit": 3,
                "warnLimit": 2,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "config",
                        "severity": "error",
                        "origin": "Demo\\Controller::handle",
                        "terminal": "Demo\\Mailer::__construct",
                        "terminalKind": "stored",
                        "hops": 3,
                        "classes": 4,
                        "chain": [
                            {
                                "method": "Demo\\Controller::handle",
                                "role": "origin",
                                "file": "src/Demo.php",
                                "line": 12,
                                "forwardLine": 14
                            },
                            {
                                "method": "Demo\\ServiceA::process",
                                "role": "hop",
                                "file": "src/Demo.php",
                                "line": 18,
                                "forwardLine": 20
                            },
                            {
                                "method": "Demo\\ServiceB::run",
                                "role": "hop",
                                "file": "src/Demo.php",
                                "line": 24,
                                "forwardLine": 26
                            },
                            {
                                "method": "Demo\\Mailer::__construct",
                                "role": "terminal",
                                "file": "src/Demo.php",
                                "line": 32,
                                "forwardLine": null
                            }
                        ],
                        "notes": []
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(3, 2), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testViaParentHopEmitsViaParentKeyOnlyOnThatChainEntry(): void
    {
        $chain = [
            new Hop('Demo\Sub::__construct', 'Demo\Sub', 'src/Demo.php', 19, 21, false, 'config', true),
            new Hop('Demo\Base::__construct', 'Demo\Base', 'src/Demo.php', 11, 13, false, 'config'),
            new Hop('Demo\Mailer::send', 'Demo\Mailer', 'src/Demo.php', 29, null, false, 'config'),
        ];
        $finding = new Finding(
            'config',
            'Demo\Sub::__construct',
            'Demo\Mailer::send',
            TerminalKind::Stored,
            1,
            $chain,
            2,
            [],
            [],
        );

        $expected = <<<'JSON'
            {
                "limit": 1,
                "warnLimit": null,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "config",
                        "severity": "error",
                        "origin": "Demo\\Sub::__construct",
                        "terminal": "Demo\\Mailer::send",
                        "terminalKind": "stored",
                        "hops": 1,
                        "classes": 2,
                        "chain": [
                            {
                                "method": "Demo\\Sub::__construct",
                                "role": "origin",
                                "file": "src/Demo.php",
                                "line": 19,
                                "forwardLine": 21,
                                "viaParent": true
                            },
                            {
                                "method": "Demo\\Base::__construct",
                                "role": "hop",
                                "file": "src/Demo.php",
                                "line": 11,
                                "forwardLine": 13
                            },
                            {
                                "method": "Demo\\Mailer::send",
                                "role": "terminal",
                                "file": "src/Demo.php",
                                "line": 29,
                                "forwardLine": null
                            }
                        ],
                        "notes": []
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(1, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testChangedOnlyRunAppendsChangedKeyAsLastEntryInEachChainEntry(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20, true),
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

        $expected = <<<'JSON'
            {
                "limit": 3,
                "warnLimit": 2,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "config",
                        "severity": "error",
                        "origin": "Demo\\Controller::handle",
                        "terminal": "Demo\\Mailer::__construct",
                        "terminalKind": "stored",
                        "hops": 3,
                        "classes": 4,
                        "chain": [
                            {
                                "method": "Demo\\Controller::handle",
                                "role": "origin",
                                "file": "src/Demo.php",
                                "line": 12,
                                "forwardLine": 14,
                                "changed": false
                            },
                            {
                                "method": "Demo\\ServiceA::process",
                                "role": "hop",
                                "file": "src/Demo.php",
                                "line": 18,
                                "forwardLine": 20,
                                "changed": true
                            },
                            {
                                "method": "Demo\\ServiceB::run",
                                "role": "hop",
                                "file": "src/Demo.php",
                                "line": 24,
                                "forwardLine": 26,
                                "changed": false
                            },
                            {
                                "method": "Demo\\Mailer::__construct",
                                "role": "terminal",
                                "file": "src/Demo.php",
                                "line": 32,
                                "forwardLine": null,
                                "changed": false
                            }
                        ],
                        "notes": []
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(3, 2), $this->paths(), true);

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testChangedOnlyFlagDefaultsToFalseAndOmitsChangedKey(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7, true),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::go', TerminalKind::Used, 1, $chain, 1, [], []);

        $reporter = new JsonReporter(new Thresholds(1, null), $this->paths());

        self::assertStringNotContainsString('"changed"', $reporter->render([$finding]));
    }

    public function testRendersWarningSeverityAndOmitsBelowThresholdFinding(): void
    {
        $warningChain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/C.php', 13, null),
        ];
        $warningFinding = new Finding(
            'p',
            'Demo\A::go',
            'Demo\C::sink',
            TerminalKind::Used,
            2,
            $warningChain,
            2,
            [],
            [],
        );

        $belowWarnLimitChain = [
            new Hop('Demo\C::go', 'Demo\C', 'src/C.php', 5, null),
        ];
        $belowWarnLimitFinding = new Finding(
            'q',
            'Demo\C::go',
            'Demo\C::go',
            TerminalKind::Used,
            1,
            $belowWarnLimitChain,
            1,
            [],
            [],
        );

        $expected = <<<'JSON'
            {
                "limit": 3,
                "warnLimit": 2,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "p",
                        "severity": "warning",
                        "origin": "Demo\\A::go",
                        "terminal": "Demo\\C::sink",
                        "terminalKind": "used",
                        "hops": 2,
                        "classes": 2,
                        "chain": [
                            {
                                "method": "Demo\\A::go",
                                "role": "origin",
                                "file": "src/A.php",
                                "line": 5,
                                "forwardLine": 7
                            },
                            {
                                "method": "Demo\\B::step",
                                "role": "hop",
                                "file": "src/B.php",
                                "line": 9,
                                "forwardLine": 11
                            },
                            {
                                "method": "Demo\\C::sink",
                                "role": "terminal",
                                "file": "src/C.php",
                                "line": 13,
                                "forwardLine": null
                            }
                        ],
                        "notes": []
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(3, 2), $this->paths());

        // Below-threshold finding comes first so an off-by-one on the filter
        // (e.g. stopping instead of skipping) would also fail this assertion.
        self::assertSame($expected, $reporter->render([$belowWarnLimitFinding, $warningFinding]));
    }

    public function testIncludesAllQualifyingFindingsWhenMultiplePresent(): void
    {
        $firstChain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $firstFinding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $firstChain, 1, [], []);

        $secondChain = [
            new Hop('Demo\B::go', 'Demo\B', 'src/B.php', 5, 7),
            new Hop('Demo\B::sink', 'Demo\B', 'src/B.php', 9, null),
        ];
        $secondFinding = new Finding('q', 'Demo\B::go', 'Demo\B::sink', TerminalKind::Used, 1, $secondChain, 1, [], []);

        $expected = <<<'JSON'
            {
                "limit": 1,
                "warnLimit": null,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "p",
                        "severity": "error",
                        "origin": "Demo\\A::go",
                        "terminal": "Demo\\A::sink",
                        "terminalKind": "used",
                        "hops": 1,
                        "classes": 1,
                        "chain": [
                            {
                                "method": "Demo\\A::go",
                                "role": "origin",
                                "file": "src/A.php",
                                "line": 5,
                                "forwardLine": 7
                            },
                            {
                                "method": "Demo\\A::sink",
                                "role": "terminal",
                                "file": "src/A.php",
                                "line": 9,
                                "forwardLine": null
                            }
                        ],
                        "notes": []
                    },
                    {
                        "param": "q",
                        "severity": "error",
                        "origin": "Demo\\B::go",
                        "terminal": "Demo\\B::sink",
                        "terminalKind": "used",
                        "hops": 1,
                        "classes": 1,
                        "chain": [
                            {
                                "method": "Demo\\B::go",
                                "role": "origin",
                                "file": "src/B.php",
                                "line": 5,
                                "forwardLine": 7
                            },
                            {
                                "method": "Demo\\B::sink",
                                "role": "terminal",
                                "file": "src/B.php",
                                "line": 9,
                                "forwardLine": null
                            }
                        ],
                        "notes": []
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(1, null), $this->paths());

        self::assertSame($expected, $reporter->render([$firstFinding, $secondFinding]));
    }

    public function testChainWithNoTerminalNodeGivesLastEntryHopRoleAndNullTerminal(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
        ];
        $finding = new Finding(
            'cfg',
            'Demo\A::go',
            null,
            TerminalKind::Truncated,
            2,
            $chain,
            2,
            ['truncated: 2 implementations'],
            [],
        );

        $expected = <<<'JSON'
            {
                "limit": 2,
                "warnLimit": null,
                "minClasses": 0,
                "findings": [
                    {
                        "param": "cfg",
                        "severity": "error",
                        "origin": "Demo\\A::go",
                        "terminal": null,
                        "terminalKind": "truncated",
                        "hops": 2,
                        "classes": 2,
                        "chain": [
                            {
                                "method": "Demo\\A::go",
                                "role": "origin",
                                "file": "src/A.php",
                                "line": 5,
                                "forwardLine": 7
                            },
                            {
                                "method": "Demo\\B::step",
                                "role": "hop",
                                "file": "src/B.php",
                                "line": 9,
                                "forwardLine": 11
                            }
                        ],
                        "notes": [
                            "truncated: 2 implementations"
                        ]
                    }
                ]
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(2, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testRendersEmptyFindingsArrayForEmptyRun(): void
    {
        $expected = <<<'JSON'
            {
                "limit": 3,
                "warnLimit": null,
                "minClasses": 0,
                "findings": []
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([]));
    }

    public function testMinClassesIsEmittedWhenSet(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        $expected = <<<'JSON'
            {
                "limit": 1,
                "warnLimit": null,
                "minClasses": 2,
                "findings": []
            }

            JSON;

        $reporter = new JsonReporter(new Thresholds(1, null, 2), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }
}
