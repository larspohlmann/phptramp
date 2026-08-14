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

    public function create(Options $options, Styler $styler): Reporter
    {
        $thresholds = new Thresholds($options->limit, $options->warnLimit, $options->minClasses);
        $paths = new Paths($this->workingDirectory);

        return match ($options->format) {
            'text' => new TextReporter($thresholds, $paths, $options->explain),
            'pretty' => new PrettyReporter($thresholds, $paths, $options->explain, $styler),
            'json' => new JsonReporter($thresholds, $paths, $options->changedOnly),
            'github' => new GithubReporter($thresholds, $paths),
            'checkstyle' => new CheckstyleReporter($thresholds, $paths),
            'sarif' => new SarifReporter($thresholds, $paths),
            'summary' => new SummaryReporter($thresholds),
            // Unreachable: ArgvParser::validateFormat rejects any format outside
            // VALID_FORMATS at parse time, so every case above is exhaustive.
            default => throw new InvalidArgsException("format '{$options->format}' is not implemented yet."),
        };
    }
}
