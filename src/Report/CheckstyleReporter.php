<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;

/**
 * Renders findings as a Checkstyle XML document: one `<error>` per finding at
 * its origin line, grouped into `<file>` elements by origin file. Consumed by
 * CI tooling that already understands the Checkstyle format (GitLab Code
 * Quality, Jenkins Warnings NG, ...).
 */
final class CheckstyleReporter implements Reporter
{
    private readonly FindingMessage $message;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
    ) {
        $this->message = new FindingMessage();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<checkstyle version="3.0">'];
        foreach ($this->groupByOriginFile($findings) as $file => $fileFindings) {
            foreach ($this->fileBlock($file, $fileFindings) as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '</checkstyle>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{0: Finding, 1: Severity}> $fileFindings
     * @return list<string>
     */
    private function fileBlock(string $file, array $fileFindings): array
    {
        $lines = ['  <file name="' . $this->escape($file) . '">'];
        foreach ($fileFindings as [$finding, $severity]) {
            $lines[] = $this->errorLine($finding, $severity);
        }
        $lines[] = '  </file>';

        return $lines;
    }

    private function errorLine(Finding $finding, Severity $severity): string
    {
        $origin = $finding->chain[0];

        return '    <error line="' . $origin->line . '" severity="' . $this->severityLabel($severity)
            . '" message="' . $this->escape($this->message->describe($finding))
            . '" source="phptramp.trampData"/>';
    }

    /**
     * Groups reportable findings by relativized origin file, in first-seen
     * file order; findings within a file keep their input order.
     *
     * @param list<Finding> $findings
     * @return array<string, list<array{0: Finding, 1: Severity}>>
     */
    private function groupByOriginFile(array $findings): array
    {
        $groups = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity === null) {
                continue;
            }
            $file = $this->paths->relativize($finding->chain[0]->file);
            $groups[$file][] = [$finding, $severity];
        }

        return $groups;
    }

    private function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'error',
            Severity::Warning => 'warning',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
