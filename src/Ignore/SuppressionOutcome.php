<?php

declare(strict_types=1);

namespace PhpTramp\Ignore;

use PhpTramp\Chain\Finding;

/**
 * The result of running {@see SuppressionFilter::filter()}: the findings that
 * survived suppression (`kept`), and the suppression keys that dropped at least
 * one finding (`firedKeys`) — in first-fired order, deduplicated.
 */
final class SuppressionOutcome
{
    /**
     * @param list<Finding> $kept findings that survived, in input order
     * @param list<string> $firedKeys keys (see SuppressionIndex builders) that dropped >= 1 chain
     */
    public function __construct(
        public readonly array $kept,
        public readonly array $firedKeys,
    ) {
    }
}
