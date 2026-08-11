<?php

declare(strict_types=1);

namespace PhpTramp\Baseline;

use PhpTramp\Chain\Finding;

/**
 * A parsed baseline document: a set of refactor-stable finding fingerprints
 * plus the raw entry lines they came from. Parse is strict — a typo'd key or
 * wrong-typed value throws, never silently un-gating CI. {@see generate()}
 * round-trips byte-stably on an unchanged tree.
 */
final class Baseline
{
    /**
     * @param list<string> $fingerprints sha1 hashes only, context already stripped
     * @param list<string> $lines raw entry lines (with human context); kept so
     *                            staleEntries() can report the original text
     */
    private function __construct(
        private readonly array $fingerprints,
        private readonly array $lines,
    ) {
    }

    /**
     * Strict parse of a baseline document. Shape:
     * {"fingerprints": ["<sha1> optional human context", ...]}.
     * Unknown top-level key, non-list, or non-string entry -> BaselineException
     * (same philosophy as ConfigLoader: a typo must not silently un-gate CI).
     * The first whitespace-delimited token of each entry is the hash.
     */
    public static function fromJson(string $json): self
    {
        $document = self::decode($json);
        $entries = self::fingerprintsValue($document);
        $lines = self::stringEntries($entries);
        $hashes = array_map(self::hashOf(...), $lines);

        return new self($hashes, $lines);
    }

    public function has(Finding $finding): bool
    {
        return in_array(Fingerprint::of($finding), $this->fingerprints, true);
    }

    /**
     * True for entries whose hash matched no finding passed in.
     *
     * @param list<Finding> $findings
     * @return list<string> raw entry lines
     */
    public function staleEntries(array $findings): array
    {
        $liveHashes = array_map(Fingerprint::of(...), $findings);

        $stale = [];
        foreach ($this->lines as $index => $line) {
            if (! in_array($this->fingerprints[$index], $liveHashes, true)) {
                $stale[] = $line;
            }
        }

        return $stale;
    }

    /**
     * The full generated document for findings: sorted lines, trailing newline.
     * Sort is by the whole rendered line (origin-alphabetical therefore diff-stable).
     *
     * @param list<Finding> $findings
     */
    public static function generate(array $findings): string
    {
        $lines = array_map(Fingerprint::line(...), $findings);
        sort($lines);

        return json_encode(
            ['fingerprints' => $lines],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . "\n";
    }

    private static function decode(string $json): \stdClass
    {
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new BaselineException('baseline document is not valid JSON: ' . $exception->getMessage());
        }

        if (! $decoded instanceof \stdClass) {
            throw new BaselineException('baseline document must be a JSON object');
        }

        return $decoded;
    }

    /** @return mixed */
    private static function fingerprintsValue(\stdClass $document): mixed
    {
        $vars = get_object_vars($document);
        foreach (array_keys($vars) as $key) {
            if ($key !== 'fingerprints') {
                throw new BaselineException("unknown baseline key: {$key}");
            }
        }

        if (! array_key_exists('fingerprints', $vars)) {
            throw new BaselineException('baseline document is missing "fingerprints"');
        }

        return $vars['fingerprints'];
    }

    /**
     * @return list<string>
     */
    private static function stringEntries(mixed $fingerprints): array
    {
        if (! is_array($fingerprints) || ! array_is_list($fingerprints)) {
            throw new BaselineException('"fingerprints" must be a list of strings');
        }

        $lines = [];
        foreach ($fingerprints as $entry) {
            if (! is_string($entry)) {
                throw new BaselineException('"fingerprints" must be a list of strings');
            }
            $lines[] = $entry;
        }

        return $lines;
    }

    private static function hashOf(string $entry): string
    {
        preg_match('/\S+/', $entry, $matches);

        return $matches[0] ?? '';
    }
}
