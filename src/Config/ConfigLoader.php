<?php

declare(strict_types=1);

namespace PhpTramp\Config;

use PhpTramp\Console\Options;

/**
 * Reads `phptramp.json` (falling back to `phptramp.dist.json`) from a
 * directory into an {@see Options} seed for {@see \PhpTramp\Console\ArgvParser}.
 *
 * The recognized-key list is strict on purpose: an unknown key or a
 * wrong-typed value throws rather than being silently ignored, because a
 * typo in this file must never silently disable gating. Never consults the
 * environment.
 */
final class ConfigLoader
{
    public function load(string $directory): Options
    {
        $file = $this->resolveFile($directory);
        if ($file === null) {
            return new Options();
        }

        return $this->toOptions($this->decode($file));
    }

    private function resolveFile(string $directory): ?string
    {
        $primary = $directory . '/phptramp.json';
        if (is_file($primary)) {
            return $primary;
        }

        $dist = $directory . '/phptramp.dist.json';

        return is_file($dist) ? $dist : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new ConfigException("unable to read config file: {$file}");
        }

        $decoded = json_decode($contents);
        if (json_last_error() !== JSON_ERROR_NONE || ! $decoded instanceof \stdClass) {
            throw new ConfigException("config file is not a JSON object: {$file}");
        }

        return $this->stringKeyed(get_object_vars($decoded));
    }

    /**
     * Narrows get_object_vars()'s array<mixed, mixed> to array<string, mixed>:
     * JSON object properties always decode to string keys, but PHPStan can't
     * see that through stdClass alone.
     *
     * @param array<mixed, mixed> $properties
     * @return array<string, mixed>
     */
    private function stringKeyed(array $properties): array
    {
        $result = [];
        foreach ($properties as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function toOptions(array $config): Options
    {
        $defaults = new Options();

        $folders = [];
        $files = [];
        $exclude = $defaults->exclude;
        $excludeTerminals = $defaults->excludeTerminals;
        $limit = $defaults->limit;
        $warnLimit = $defaults->warnLimit;
        $minClasses = $defaults->minClasses;
        $format = $defaults->format;
        $baseline = $defaults->baseline;
        $cacheDir = $defaults->cacheDir;
        $colorMode = $defaults->colorMode;

        foreach ($config as $key => $value) {
            match ($key) {
                'paths' => $this->assignPaths($value, $folders, $files),
                'exclude' => $exclude = $this->requireStringList('exclude', $value),
                'excludeTerminals' => $excludeTerminals = $this->requireStringList('excludeTerminals', $value),
                'limit' => $limit = $this->requireInt('limit', $value),
                'warnLimit' => $warnLimit = $this->requireInt('warnLimit', $value),
                'minClasses' => $minClasses = $this->requireInt('minClasses', $value),
                'format' => $format = $this->requireString('format', $value),
                'baseline' => $baseline = $this->requireString('baseline', $value),
                'cache' => $cacheDir = $this->requireString('cache', $value),
                'colorMode' => $colorMode = $this->requireColorMode('colorMode', $value),
                default => throw new ConfigException("unknown config key: {$key}"),
            };
        }

        return new Options(
            folders: $folders,
            files: $files,
            limit: $limit,
            warnLimit: $warnLimit,
            minClasses: $minClasses,
            format: $format,
            baseline: $baseline,
            exclude: $exclude,
            excludeTerminals: $excludeTerminals,
            cacheDir: $cacheDir,
            colorMode: $colorMode,
        );
    }

    /**
     * @param list<string> $folders
     * @param list<string> $files
     * @param-out list<string> $folders
     * @param-out list<string> $files
     */
    private function assignPaths(mixed $value, array &$folders, array &$files): void
    {
        foreach ($this->requireStringList('paths', $value) as $path) {
            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            } else {
                $folders[] = $path;
            }
        }
    }

    /**
     * @return list<string>
     */
    private function requireStringList(string $key, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ConfigException("config key \"{$key}\" must be a list of strings");
        }

        $strings = [];
        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new ConfigException("config key \"{$key}\" must be a list of strings");
            }
            $strings[] = $entry;
        }

        return $strings;
    }

    private function requireInt(string $key, mixed $value): int
    {
        if (! is_int($value)) {
            throw new ConfigException("config key \"{$key}\" must be an integer");
        }

        return $value;
    }

    private function requireString(string $key, mixed $value): string
    {
        if (! is_string($value)) {
            throw new ConfigException("config key \"{$key}\" must be a string");
        }

        return $value;
    }

    private function requireColorMode(string $key, mixed $value): string
    {
        if (! is_string($value)) {
            throw new ConfigException("config key \"{$key}\" must be a string");
        }

        $valid = ['always', 'auto', 'never'];
        if (! in_array($value, $valid, true)) {
            throw new ConfigException("config key \"{$key}\" must be one of: always, auto, never");
        }

        return $value;
    }
}
