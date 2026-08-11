<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

/**
 * How a tramp-data chain ends. The backing values are the tokens the reporters
 * print. `used`/`stored`/`&-terminated`/`unused-end` keep the terminal node in
 * the chain; `external`/`truncated` do not (the chain left analyzed code or was
 * conservatively cut short).
 */
enum TerminalKind: string
{
    case Used = 'used';
    case Stored = 'stored';
    case ByRef = '&-terminated';
    case Unused = 'unused-end';
    case External = 'external';
    case Truncated = 'truncated';
}
