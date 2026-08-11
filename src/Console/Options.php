<?php

declare(strict_types=1);

namespace PhpTramp\Console;

/**
 * Immutable value object holding every resolved CLI setting.
 *
 * This is an aggregate settings DTO: the wide constructor is the point, so the
 * codesize parameter-count rule is deliberately suppressed here.
 *
 * @SuppressWarnings(PHPMD)
 */
final class Options
{
    /**
     * @param list<string> $folders
     * @param list<string> $files
     * @param list<string> $exclude
     */
    public function __construct(
        public readonly array $folders = [],
        public readonly array $files = [],
        public readonly int $limit = 3,
        public readonly ?int $warnLimit = null,
        public readonly string $format = 'text',
        public readonly bool $explain = false,
        public readonly bool $changedOnly = false,
        public readonly string $gitBase = 'origin/main',
        public readonly ?string $diff = null,
        public readonly ?string $baseline = null,
        public readonly ?string $generateBaseline = null,
        public readonly bool $dumpIndex = false,
        public readonly bool $noConfig = false,
        public readonly bool $help = false,
        public readonly bool $version = false,
        public readonly bool $failOnStale = false,
        public readonly array $exclude = [],
    ) {
    }
}
