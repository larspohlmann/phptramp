<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Console\InvalidArgsException;

/**
 * The three thresholds a report is judged against: fail at `limit`, warn at
 * `warnLimit`, suppress below `minClasses` distinct classes. Severity is
 * computed here, at reporting time, so `Finding` itself stays a pure fact
 * about a chain.
 *
 * `0` disables a tier: `limit: 0` turns off the error tier, `warnLimit: 0`
 * is normalized to `null` (off). The warn-limit-below-limit guard fires only
 * when both are positive.
 */
final class Thresholds
{
    public readonly ?int $warnLimit;

    /**
     * @throws InvalidArgsException when both limits are positive and
     *                               warnLimit >= limit (a warn bar that can
     *                               never fire below the fail bar is a config
     *                               error)
     */
    public function __construct(
        public readonly int $limit,
        ?int $warnLimit,
        public readonly int $minClasses = 0,
    ) {
        $this->warnLimit = $warnLimit !== null && $warnLimit > 0 ? $warnLimit : null;

        if ($this->warnLimit !== null && $this->limit > 0 && $this->warnLimit >= $this->limit) {
            throw new InvalidArgsException(
                "warn-limit ({$warnLimit}) must be lower than limit ({$this->limit}).",
            );
        }
    }

    /** Null = below every threshold: not reported at all. */
    public function severityOf(Finding $finding): ?Severity
    {
        if ($this->minClasses > 0 && $finding->classes < $this->minClasses) {
            return null;
        }

        if ($this->limit > 0 && $finding->hops >= $this->limit) {
            return Severity::Error;
        }

        if ($this->warnLimit !== null && $finding->hops >= $this->warnLimit) {
            return Severity::Warning;
        }

        return null;
    }
}
