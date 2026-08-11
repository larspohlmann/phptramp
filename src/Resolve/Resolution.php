<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * The outcome of resolving one forwarding site: either a concrete target inside
 * analyzed code ({@see ResolvedTarget}), a target that leaves analyzed code
 * ({@see ExternalTarget}), or an honest non-answer ({@see TruncatedResolution}).
 * Marker interface — each implementation carries its own data.
 */
interface Resolution
{
}
