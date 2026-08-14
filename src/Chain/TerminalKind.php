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

    /**
     * Whether a chain ending this way keeps its terminal node in `Finding::$chain`.
     * `external`/`truncated` left analyzed code, so there is no node to show.
     * This is the executable form of the rule the class docblock states in prose;
     * reporters ask here instead of comparing chain length against the hop score,
     * which counts and no longer indexes.
     */
    public function keepsTerminalNode(): bool
    {
        return match ($this) {
            self::Used, self::Stored, self::ByRef, self::Unused => true,
            self::External, self::Truncated => false,
        };
    }
}
