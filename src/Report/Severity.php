<?php

declare(strict_types=1);

namespace PhpTramp\Report;

enum Severity
{
    case Error;
    case Warning;

    /** The lowercase token every machine reporter emits for this severity. */
    public function label(): string
    {
        return match ($this) {
            self::Error => 'error',
            self::Warning => 'warning',
        };
    }
}
