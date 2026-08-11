<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Console\InvalidArgsException;

/**
 * The two hop-count thresholds a report is judged against: fail at `limit`,
 * warn at `warnLimit`. Severity is computed here, at reporting time, so
 * `Finding` itself stays a pure fact about a chain.
 */
final class Thresholds
{
    /**
     * @throws InvalidArgsException if warnLimit is at or above limit (a warn
     *                               bar that never fires below the fail bar
     *                               is a config error)
     */
    public function __construct(
        public readonly int $limit,
        public readonly ?int $warnLimit,
    ) {
        if ($this->warnLimit !== null && $this->warnLimit >= $this->limit) {
            throw new InvalidArgsException(
                "warn-limit ({$this->warnLimit}) must be lower than limit ({$this->limit}).",
            );
        }
    }

    /** Null = below every threshold: not reported at all. */
    public function severityOf(Finding $finding): ?Severity
    {
        if ($finding->hops >= $this->limit) {
            return Severity::Error;
        }
        if ($this->warnLimit !== null && $finding->hops >= $this->warnLimit) {
            return Severity::Warning;
        }

        return null;
    }
}
