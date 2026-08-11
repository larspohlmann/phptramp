<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * The outcome of resolving one forwarding site: either a concrete target inside
 * analyzed code ({@see ResolvedTarget}), a target that leaves analyzed code
 * ({@see ExternalTarget}), or an honest non-answer ({@see TruncatedResolution}).
 * Each implementation carries its own data and describes its own target for the
 * `--explain` trace.
 */
interface Resolution
{
    /** How this resolution's target reads in an --explain trace line. */
    public function describe(): string;
}
