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
    private const VALUE_FLAGS = [
        '--folder',
        '--file',
        '--files',
        '--limit',
        '--warn-limit',
        '--format',
        '--git-base',
        '--baseline',
        '--generate-baseline',
    ];

    private const BOOL_FLAGS = [
        '--explain',
        '--changed-only',
        '--dump-index',
        '--help',
        '-h',
        '--version',
        '-V',
    ];

    private const VALID_FORMATS = ['text', 'json', 'github', 'checkstyle', 'sarif', 'summary'];

    /** @var list<string> */
    private array $folders = [];
    /** @var list<string> */
    private array $files = [];
    private int $limit = 3;
    private ?int $warnLimit = null;
    private string $format = 'text';
    private bool $explain = false;
    private bool $changedOnly = false;
    private string $gitBase = 'origin/main';
    private ?string $baseline = null;
    private ?string $generateBaseline = null;
    private bool $dumpIndex = false;
    private bool $help = false;
    private bool $version = false;

    /**
     * @param list<string> $args
     */
    public function parse(array $args): Options
    {
        $this->reset();

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
            format: $this->format,
            explain: $this->explain,
            changedOnly: $this->changedOnly,
            gitBase: $this->gitBase,
            baseline: $this->baseline,
            generateBaseline: $this->generateBaseline,
            dumpIndex: $this->dumpIndex,
            help: $this->help,
            version: $this->version,
        );
    }

    private function reset(): void
    {
        $this->folders = [];
        $this->files = [];
        $this->limit = 3;
        $this->warnLimit = null;
        $this->format = 'text';
        $this->explain = false;
        $this->changedOnly = false;
        $this->gitBase = 'origin/main';
        $this->baseline = null;
        $this->generateBaseline = null;
        $this->dumpIndex = false;
        $this->help = false;
        $this->version = false;
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
            '--folder' => $this->folders[] = $value,
            '--file' => $this->files[] = $value,
            '--files' => $this->appendFiles($value),
            '--limit' => $this->limit = $this->toInt($name, $value),
            '--warn-limit' => $this->warnLimit = $this->toInt($name, $value),
            '--format' => $this->format = $this->validateFormat($value),
            '--git-base' => $this->gitBase = $value,
            '--baseline' => $this->baseline = $value,
            '--generate-baseline' => $this->generateBaseline = $value,
            default => throw new InvalidArgsException("unknown option: {$name}"),
        };
    }

    private function applyBoolFlag(string $name): void
    {
        match ($name) {
            '--explain' => $this->explain = true,
            '--changed-only' => $this->changedOnly = true,
            '--dump-index' => $this->dumpIndex = true,
            '--help', '-h' => $this->help = true,
            '--version', '-V' => $this->version = true,
            default => throw new InvalidArgsException("unknown option: {$name}"),
        };
    }

    private function appendFiles(string $value): void
    {
        foreach (explode(',', $value) as $file) {
            $this->files[] = $file;
        }
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
}
