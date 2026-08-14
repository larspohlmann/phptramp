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
     * @param list<string> $excludeTerminals TerminalKind backing values
     */
    public function __construct(
        public readonly array $folders = [],
        public readonly array $files = [],
        public readonly int $limit = 6,
        public readonly ?int $warnLimit = 4,
        public readonly int $minClasses = 0,
        public readonly string $format = 'pretty',
        public readonly string $colorMode = 'auto',
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
        public readonly array $excludeTerminals = [],
        public readonly bool $noCache = false,
        public readonly string $cacheDir = '.phptramp.cache',
    ) {
    }

    /**
     * An immutable copy that swaps only the format — so {@see Application}
     * can apply its post-parse non-TTY downgrade without re-stating the full
     * 22-parameter constructor. Mirrors {@see \PhpTramp\Chain\Finding::withChain()}.
     */
    public function withFormat(string $format): self
    {
        return new self(
            folders: $this->folders,
            files: $this->files,
            limit: $this->limit,
            warnLimit: $this->warnLimit,
            minClasses: $this->minClasses,
            format: $format,
            colorMode: $this->colorMode,
            explain: $this->explain,
            changedOnly: $this->changedOnly,
            gitBase: $this->gitBase,
            diff: $this->diff,
            baseline: $this->baseline,
            generateBaseline: $this->generateBaseline,
            dumpIndex: $this->dumpIndex,
            noConfig: $this->noConfig,
            help: $this->help,
            version: $this->version,
            failOnStale: $this->failOnStale,
            exclude: $this->exclude,
            excludeTerminals: $this->excludeTerminals,
            noCache: $this->noCache,
            cacheDir: $this->cacheDir,
        );
    }
}
