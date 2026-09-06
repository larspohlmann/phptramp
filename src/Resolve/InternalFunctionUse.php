<?php

declare(strict_types=1);

namespace PhpTramp\Resolve;

/**
 * The forward hands the value to a PHP internal function (`trim`, `str_replace`,
 * `array_map`, …) that no user code in the index defines. The value cannot travel
 * any further inside analyzed code, so the forwarding method *is* the consumer:
 * unlike {@see ExternalTarget}, which leaves the callee as a mindless hop, this
 * terminates the chain at the caller as a use. Callback-taking built-ins are
 * internal too, so the conservative choice (their argument is consumed) falls out.
 */
final class InternalFunctionUse implements Resolution
{
    /** The internal function the value was handed to — for --explain. */
    public function __construct(public readonly string $function)
    {
    }

    public function describe(): string
    {
        return 'use: internal function ' . $this->function . '()';
    }
}
