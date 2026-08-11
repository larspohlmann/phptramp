<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * A syntactic call target captured at a forwarding site: enough information for
 * Phase 2's CallResolver to resolve it to a concrete FQMN. No resolution here.
 */
final class CalleeRef
{
    public function __construct(
        public readonly CalleeKind $kind,
        /** Function name, method name, or (for `new`/static) the class name. */
        public readonly string $name,
        /**
         * Receiver description for method calls: `this`, a parameter name, an
         * inferred class name, or `raw` when untypeable. Null for functions and
         * `new`.
         */
        public readonly ?string $receiverHint = null,
    ) {
    }
}
