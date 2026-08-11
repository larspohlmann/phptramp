<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;

final class ReporterFactory
{
    public function create(Options $options): Reporter
    {
        $thresholds = new Thresholds($options->limit, $options->warnLimit);

        return match ($options->format) {
            'text' => new TextReporter($thresholds, $options->explain),
            default => throw new InvalidArgsException("format '{$options->format}' is not implemented yet."),
        };
    }
}
