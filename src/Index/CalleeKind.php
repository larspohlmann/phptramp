<?php

declare(strict_types=1);

namespace PhpTramp\Index;

/**
 * The syntactic shape of a call target, before any resolution to an FQMN.
 * The backing values are the tokens used in `--dump-index` output.
 */
enum CalleeKind: string
{
    case Func = 'function';
    case Method = 'method';
    case StaticCall = 'static';
    case Instantiation = 'new';
}
