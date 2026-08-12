<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;

/**
 * Renders findings as a SARIF 2.1.0 log: one run, one rule
 * (`phptramp.trampData`), one result per finding with a location at the
 * origin and a relatedLocations entry per subsequent hop. The driver
 * intentionally omits `version` so fixtures stay stable across releases.
 * See https://docs.oasis-open.org/sarif/sarif/v2.1.0/sarif-v2.1.0.html.
 */
final class SarifReporter implements Reporter
{
    private const SCHEMA_URL = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/'
        . 'sarif-schema-2.1.0.json';

    private readonly FindingMessage $message;
    private readonly JsonEncoder $encoder;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
    ) {
        $this->message = new FindingMessage();
        $this->encoder = new JsonEncoder();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $document = [
            'version' => '2.1.0',
            '$schema' => self::SCHEMA_URL,
            'runs' => [$this->run($findings)],
        ];

        return $this->encoder->encode($document, 'SARIF') . "\n";
    }

    /**
     * @param list<Finding> $findings
     * @return array<string, mixed>
     */
    private function run(array $findings): array
    {
        return [
            'tool' => [
                'driver' => [
                    'name' => 'phptramp',
                    'informationUri' => 'https://github.com/larspohlmann/phptramp',
                    'rules' => [
                        ['id' => 'phptramp.trampData'],
                    ],
                ],
            ],
            'results' => $this->results($findings),
        ];
    }

    /**
     * @param list<Finding> $findings
     * @return list<array<string, mixed>>
     */
    private function results(array $findings): array
    {
        $results = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity === null) {
                continue;
            }
            $results[] = $this->result($finding, $severity);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(Finding $finding, Severity $severity): array
    {
        $result = [
            'ruleId' => 'phptramp.trampData',
            'level' => $severity->label(),
            'message' => ['text' => $this->message->describe($finding)],
            'locations' => [$this->location($finding->chain[0])],
        ];

        $relatedLocations = $this->relatedLocations($finding);
        if ($relatedLocations !== []) {
            $result['relatedLocations'] = $relatedLocations;
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>> one entry per subsequent hop (chain
     *                                     indices 1 .. hops-1); the terminal
     *                                     node is never included
     */
    private function relatedLocations(Finding $finding): array
    {
        $locations = [];
        for ($index = 1; $index < $finding->hops; $index++) {
            $hop = $finding->chain[$index];
            $locations[] = [
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $this->paths->relativize($hop->file)],
                    'region' => ['startLine' => $hop->forwardLine ?? $hop->line],
                ],
                'message' => [
                    'text' => 'hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin,
                ],
            ];
        }

        return $locations;
    }

    /**
     * @return array<string, mixed>
     */
    private function location(Hop $hop): array
    {
        return [
            'physicalLocation' => [
                'artifactLocation' => ['uri' => $this->paths->relativize($hop->file)],
                'region' => ['startLine' => $hop->line],
            ],
        ];
    }
}
