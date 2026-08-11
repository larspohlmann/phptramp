<?php

declare(strict_types=1);

namespace PhpTramp\Baseline;

use PhpTramp\Chain\Finding;
use PhpTramp\Report\JsonEncoder;

/**
 * A parsed baseline document: refactor-stable finding fingerprints mapped to
 * the raw entry lines they came from. Parse is strict — a typo'd key or
 * wrong-typed value throws, never silently un-gating CI. {@see generate()}
 * round-trips byte-stably on an unchanged tree.
 */
final class Baseline
{
    /**
     * @param array<string, string> $entriesByHash fingerprint hash => the raw
     *        entry line it came from (with human context); the line is kept so
     *        staleEntries() can report the original text
     */
    private function __construct(
        private readonly array $entriesByHash,
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

        $entriesByHash = [];
        foreach ($lines as $line) {
            $entriesByHash[self::hashOf($line)] = $line;
        }

        return new self($entriesByHash);
    }

    public function has(Finding $finding): bool
    {
        return isset($this->entriesByHash[Fingerprint::of($finding)]);
    }

    /**
     * The raw entry lines whose fingerprint matched no finding passed in, in
     * document order.
     *
     * @param list<Finding> $findings
     * @return list<string> raw entry lines
     */
    public function staleEntries(array $findings): array
    {
        $liveHashes = [];
        foreach ($findings as $finding) {
            $liveHashes[Fingerprint::of($finding)] = true;
        }

        $stale = [];
        foreach ($this->entriesByHash as $hash => $line) {
            if (! isset($liveHashes[$hash])) {
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

        return (new JsonEncoder())->encode(['fingerprints' => $lines], 'baseline') . "\n";
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
            throw new BaselineException(
                '"fingerprints" must be a list of strings, got ' . get_debug_type($fingerprints),
            );
        }

        $lines = [];
        foreach ($fingerprints as $index => $entry) {
            if (! is_string($entry)) {
                throw new BaselineException(
                    '"fingerprints" must be a list of strings, non-string entry at index '
                    . $index . ': ' . get_debug_type($entry),
                );
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
