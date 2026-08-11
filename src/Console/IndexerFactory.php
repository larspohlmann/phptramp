<?php

declare(strict_types=1);

namespace PhpTramp\Console;

use PhpTramp\Cache\FileIndexCache;
use PhpTramp\Index\Indexer;

/**
 * Builds the {@see Indexer} the CLI runs with, deciding whether the per-file
 * cache is wired in. Lives outside {@see Application} so that one ternary does
 * not tip Application's class-complexity over PHPMD's ceiling, and so the
 * cache-on/off decision has a single, testable home.
 */
final class IndexerFactory
{
    public function fromOptions(Options $options): Indexer
    {
        return new Indexer(
            cache: $options->noCache ? null : new FileIndexCache($options->cacheDir),
        );
    }
}
