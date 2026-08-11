<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\Paths;
use PhpTramp\Report\SarifReporter;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

final class SarifReporterTest extends TestCase
{
    private const SCHEMA_URL = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/'
        . 'sarif-schema-2.1.0.json';

    /**
     * Working directory that is not a prefix of any fixture file path below, so
     * Paths::relativize leaves the already-relative "src/..." files unchanged.
     */
    private function paths(): Paths
    {
        return new Paths('/not/matching');
    }

    public function testRendersErrorFindingWithLocationAndRelatedLocationPerSubsequentHop(): void
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

        $expected = <<<JSON
            {
                "version": "2.1.0",
                "\$schema": "{$this->schemaUrl()}",
                "runs": [
                    {
                        "tool": {
                            "driver": {
                                "name": "phptramp",
                                "informationUri": "https://github.com/larspohlmann/phptramp",
                                "rules": [
                                    {
                                        "id": "phptramp.trampData"
                                    }
                                ]
                            }
                        },
                        "results": [
                            {
                                "ruleId": "phptramp.trampData",
                                "level": "error",
                                "message": {
            JSON;
        $expected .= "\n" . '                        "text": "$config: 3 pass-through hops across 4 classes '
            . "(terminal: Demo\\\\Mailer::__construct [stored])\"\n";
        $expected .= <<<JSON
                                },
                                "locations": [
                                    {
                                        "physicalLocation": {
                                            "artifactLocation": {
                                                "uri": "src/Demo.php"
                                            },
                                            "region": {
                                                "startLine": 12
                                            }
                                        }
                                    }
                                ],
                                "relatedLocations": [
                                    {
                                        "physicalLocation": {
                                            "artifactLocation": {
                                                "uri": "src/Demo.php"
                                            },
                                            "region": {
                                                "startLine": 18
                                            }
                                        },
                                        "message": {
                                            "text": "hop 2 of \$config chain from Demo\\\\Controller::handle"
                                        }
                                    },
                                    {
                                        "physicalLocation": {
                                            "artifactLocation": {
                                                "uri": "src/Demo.php"
                                            },
                                            "region": {
                                                "startLine": 24
                                            }
                                        },
                                        "message": {
                                            "text": "hop 3 of \$config chain from Demo\\\\Controller::handle"
                                        }
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }

            JSON;

        $reporter = new SarifReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testRendersWarningFindingAndOmitsRelatedLocationsWhenNoSubsequentHops(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
        ];
        $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

        $expected = <<<JSON
            {
                "version": "2.1.0",
                "\$schema": "{$this->schemaUrl()}",
                "runs": [
                    {
                        "tool": {
                            "driver": {
                                "name": "phptramp",
                                "informationUri": "https://github.com/larspohlmann/phptramp",
                                "rules": [
                                    {
                                        "id": "phptramp.trampData"
                                    }
                                ]
                            }
                        },
                        "results": [
                            {
                                "ruleId": "phptramp.trampData",
                                "level": "warning",
                                "message": {
                                    "text": "\$p: 1 pass-through hop across 1 class (terminal: Demo\\\\A::sink [used])"
                                },
                                "locations": [
                                    {
                                        "physicalLocation": {
                                            "artifactLocation": {
                                                "uri": "src/A.php"
                                            },
                                            "region": {
                                                "startLine": 5
                                            }
                                        }
                                    }
                                ]
                            }
                        ]
                    }
                ]
            }

            JSON;

        $reporter = new SarifReporter(new Thresholds(3, 1), $this->paths());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testOmitsBelowThresholdFindingEntirely(): void
    {
        $chain = [
            new Hop('Demo\C::go', 'Demo\C', 'src/C.php', 5, null),
        ];
        $finding = new Finding('q', 'Demo\C::go', 'Demo\C::go', TerminalKind::Used, 1, $chain, 1, [], []);

        $reporter = new SarifReporter(new Thresholds(3, 2), $this->paths());

        self::assertStringNotContainsString('"ruleId"', $reporter->render([$finding]));
    }

    public function testEmptyRunRendersValidDocumentWithEmptyResults(): void
    {
        $expected = <<<JSON
            {
                "version": "2.1.0",
                "\$schema": "{$this->schemaUrl()}",
                "runs": [
                    {
                        "tool": {
                            "driver": {
                                "name": "phptramp",
                                "informationUri": "https://github.com/larspohlmann/phptramp",
                                "rules": [
                                    {
                                        "id": "phptramp.trampData"
                                    }
                                ]
                            }
                        },
                        "results": []
                    }
                ]
            }

            JSON;

        $reporter = new SarifReporter(new Thresholds(3, null), $this->paths());

        self::assertSame($expected, $reporter->render([]));
    }

    private function schemaUrl(): string
    {
        return self::SCHEMA_URL;
    }
}
