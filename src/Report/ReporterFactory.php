<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;

final class ReporterFactory
{
    public function __construct(private readonly string $workingDirectory)
    {
    }

    public function create(Options $options): Reporter
    {
        $thresholds = new Thresholds($options->limit, $options->warnLimit);

        return match ($options->format) {
            'text' => new TextReporter($thresholds, $options->explain),
            'json' => new JsonReporter($thresholds, new Paths($this->workingDirectory)),
            'github' => new GithubReporter($thresholds, new Paths($this->workingDirectory)),
            'checkstyle' => new CheckstyleReporter($thresholds, new Paths($this->workingDirectory)),
            'sarif' => new SarifReporter($thresholds, new Paths($this->workingDirectory)),
            'summary' => new SummaryReporter($thresholds),
            // Unreachable: ArgvParser::validateFormat rejects any format outside
            // VALID_FORMATS at parse time, so every case above is exhaustive.
            default => throw new InvalidArgsException("format '{$options->format}' is not implemented yet."),
        };
    }
}
