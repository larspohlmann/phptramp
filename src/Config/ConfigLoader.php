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
        $limit = $defaults->limit;
        $warnLimit = $defaults->warnLimit;
        $format = $defaults->format;
        $baseline = $defaults->baseline;

        foreach ($config as $key => $value) {
            match ($key) {
                'paths' => $this->assignPaths($value, $folders, $files),
                'exclude' => null, // storage arrives in Task 5; allow-listed so it doesn't trip the unknown-key guard.
                'limit' => $limit = $this->requireInt('limit', $value),
                'warnLimit' => $warnLimit = $this->requireInt('warnLimit', $value),
                'format' => $format = $this->requireString('format', $value),
                'baseline' => $baseline = $this->requireString('baseline', $value),
                default => throw new ConfigException("unknown config key: {$key}"),
            };
        }

        return new Options(
            folders: $folders,
            files: $files,
            limit: $limit,
            warnLimit: $warnLimit,
            format: $format,
            baseline: $baseline,
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
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ConfigException('config key "paths" must be a list of strings');
        }

        foreach ($value as $path) {
            if (! is_string($path)) {
                throw new ConfigException('config key "paths" must be a list of strings');
            }

            if (str_ends_with($path, '.php')) {
                $files[] = $path;
            } else {
                $folders[] = $path;
            }
        }
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
}
