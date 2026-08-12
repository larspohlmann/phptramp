<?php

declare(strict_types=1);

namespace PhpTramp\Console;

/**
 * Hand-rolled argv parser: turns a list of arguments (program name already
 * sliced off) into an immutable {@see Options}. No external dependency so the
 * tool stays conflict-free as a require-dev install.
 */
final class ArgvParser
{
    /**
     * Skips config loading. Public because {@see Application} must detect it in
     * the raw args before parsing (config seeds the parser's defaults), so both
     * sides share this one spelling.
     */
    public const NO_CONFIG_FLAG = '--no-config';

    private const VALUE_FLAGS = [
        '--folder',
        '--file',
        '--files',
        '--limit',
        '--warn-limit',
        '--min-classes',
        '--format',
        '--color',
        '--git-base',
        '--diff',
        '--baseline',
        '--generate-baseline',
    ];

    private const BOOL_FLAGS = [
        '--explain',
        '--changed-only',
        '--dump-index',
        self::NO_CONFIG_FLAG,
        '--help',
        '-h',
        '--version',
        '-V',
        '--fail-on-stale',
        '--no-cache',
    ];

    private const VALID_FORMATS = ['text', 'json', 'github', 'checkstyle', 'sarif', 'summary'];
    private const VALID_COLOR_MODES = ['always', 'auto', 'never'];

    /** @var list<string> */
    private array $folders = [];
    /** @var list<string> */
    private array $files = [];
    private int $limit = 6;
    private ?int $warnLimit = 4;
    private int $minClasses = 0;
    private string $format = 'text';
    private string $colorMode = 'auto';
    private DiffAwareFlags $diffAware;
    private BoolFlags $bools;
    private ?string $baseline = null;
    private ?string $generateBaseline = null;
    private bool $clearedSeededPaths = false;
    /** @var list<string> */
    private array $exclude = [];
    private string $cacheDir = '.phptramp.cache';

    /**
     * @param list<string> $args
     */
    public function parse(array $args, Options $defaults = new Options()): Options
    {
        $this->reset($defaults);

        $index = 0;
        $count = count($args);
        while ($index < $count) {
            $index = $this->consume($args, $index, $count);
        }

        return new Options(
            folders: $this->folders,
            files: $this->files,
            limit: $this->limit,
            warnLimit: $this->warnLimit,
            minClasses: $this->minClasses,
            format: $this->format,
            colorMode: $this->colorMode,
            explain: $this->bools->explain,
            changedOnly: $this->diffAware->changedOnly,
            gitBase: $this->diffAware->gitBase,
            diff: $this->diffAware->diff,
            baseline: $this->baseline,
            generateBaseline: $this->generateBaseline,
            dumpIndex: $this->bools->dumpIndex,
            noConfig: $this->bools->noConfig,
            help: $this->bools->help,
            version: $this->bools->version,
            failOnStale: $this->bools->failOnStale,
            exclude: $this->exclude,
            noCache: $this->bools->noCache,
            cacheDir: $this->cacheDir,
        );
    }

    private function reset(Options $defaults): void
    {
        $this->folders = $defaults->folders;
        $this->files = $defaults->files;
        $this->limit = $defaults->limit;
        $this->warnLimit = $defaults->warnLimit;
        $this->minClasses = $defaults->minClasses;
        $this->format = $defaults->format;
        $this->colorMode = $defaults->colorMode;
        $this->diffAware = new DiffAwareFlags($defaults->changedOnly, $defaults->gitBase, $defaults->diff);
        $this->bools = new BoolFlags(
            explain: $defaults->explain,
            dumpIndex: $defaults->dumpIndex,
            noConfig: $defaults->noConfig,
            help: $defaults->help,
            version: $defaults->version,
            failOnStale: $defaults->failOnStale,
            noCache: $defaults->noCache,
        );
        $this->baseline = $defaults->baseline;
        $this->generateBaseline = $defaults->generateBaseline;
        $this->exclude = $defaults->exclude;
        $this->cacheDir = $defaults->cacheDir;
        $this->clearedSeededPaths = false;
    }

    /**
     * @param list<string> $args
     * @return int the next index to read
     */
    private function consume(array $args, int $index, int $count): int
    {
        $raw = $args[$index];

        $eq = strpos($raw, '=');
        if ($eq !== false) {
            $this->applyValueFlag(substr($raw, 0, $eq), substr($raw, $eq + 1));

            return $index + 1;
        }

        if (in_array($raw, self::BOOL_FLAGS, true)) {
            $this->applyBoolFlag($raw);

            return $index + 1;
        }

        if (in_array($raw, self::VALUE_FLAGS, true)) {
            if ($index + 1 >= $count) {
                throw new InvalidArgsException("missing value for option: {$raw}");
            }
            $this->applyValueFlag($raw, $args[$index + 1]);

            return $index + 2;
        }

        throw new InvalidArgsException("unknown option: {$raw}");
    }

    private function applyValueFlag(string $name, string $value): void
    {
        match ($name) {
            '--folder' => $this->appendFolder($value),
            '--file' => $this->appendFile($value),
            '--files' => $this->appendFiles($value),
            '--limit' => $this->limit = $this->toInt($name, $value),
            '--warn-limit' => $this->warnLimit = $this->toInt($name, $value),
            '--min-classes' => $this->minClasses = $this->toInt($name, $value),
            '--format' => $this->format = $this->validateFormat($value),
            '--color' => $this->colorMode = $this->validateColorMode($value),
            '--git-base' => $this->diffAware->gitBase = $value,
            '--diff' => $this->applyDiffFlag($value),
            '--baseline' => $this->baseline = $value,
            '--generate-baseline' => $this->generateBaseline = $value,
            default => throw new InvalidArgsException("unknown option: {$name}"),
        };
    }

    private function applyBoolFlag(string $name): void
    {
        match ($name) {
            '--explain' => $this->bools->explain = true,
            '--changed-only' => $this->diffAware->changedOnly = true,
            '--dump-index' => $this->bools->dumpIndex = true,
            self::NO_CONFIG_FLAG => $this->bools->noConfig = true,
            '--help', '-h' => $this->bools->help = true,
            '--version', '-V' => $this->bools->version = true,
            '--fail-on-stale' => $this->bools->failOnStale = true,
            '--no-cache' => $this->bools->noCache = true,
            default => throw new InvalidArgsException("unknown option: {$name}"),
        };
    }

    /**
     * A diff source with no filter to apply it to is contradictory, so
     * --diff always turns --changed-only on.
     */
    private function applyDiffFlag(string $value): void
    {
        $this->diffAware->diff = $value;
        $this->diffAware->changedOnly = true;
    }

    private function appendFolder(string $value): void
    {
        $this->clearSeededPathsOnce();
        $this->folders[] = $value;
    }

    private function appendFile(string $value): void
    {
        $this->clearSeededPathsOnce();
        $this->files[] = $value;
    }

    private function appendFiles(string $value): void
    {
        $this->clearSeededPathsOnce();
        foreach (explode(',', $value) as $file) {
            $this->files[] = $file;
        }
    }

    /**
     * The first path flag replaces config-seeded paths entirely (never mixed
     * with CLI paths); later path flags append as usual.
     */
    private function clearSeededPathsOnce(): void
    {
        if ($this->clearedSeededPaths) {
            return;
        }

        $this->folders = [];
        $this->files = [];
        $this->clearedSeededPaths = true;
    }

    private function toInt(string $flag, string $value): int
    {
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgsException("option {$flag} expects an integer, got: {$value}");
        }

        return (int) $value;
    }

    private function validateFormat(string $value): string
    {
        if (! in_array($value, self::VALID_FORMATS, true)) {
            throw new InvalidArgsException(
                "unknown format: {$value} (expected " . implode('|', self::VALID_FORMATS) . ')'
            );
        }

        return $value;
    }

    private function validateColorMode(string $value): string
    {
        if (! in_array($value, self::VALID_COLOR_MODES, true)) {
            throw new InvalidArgsException(
                "unknown color mode: {$value} (expected " . implode('|', self::VALID_COLOR_MODES) . ')'
            );
        }

        return $value;
    }
}
