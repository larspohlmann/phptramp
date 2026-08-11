<?php

declare(strict_types=1);

namespace PhpTramp\Console;

/**
 * The standalone boolean flags travel together as a unit throughout parsing,
 * so ArgvParser holds them behind one field instead of six — keeping its own
 * field count under PHPMD's ceiling (the same reason {@see DiffAwareFlags}
 * exists) without losing the 1:1 mapping onto {@see Options}.
 */
final class BoolFlags
{
    public function __construct(
        public bool $explain = false,
        public bool $dumpIndex = false,
        public bool $noConfig = false,
        public bool $help = false,
        public bool $version = false,
        public bool $failOnStale = false,
        public bool $noCache = false,
    ) {
    }
}
