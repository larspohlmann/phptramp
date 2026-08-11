<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * Encodes a report document as pretty-printed JSON with unescaped slashes,
 * shared by the JSON and SARIF reporters. Raises a typed exception on the
 * (unreachable) encode failure so callers never have to handle a `false`.
 */
final class JsonEncoder
{
    /**
     * @param array<string, mixed> $document
     * @throws JsonEncodingException
     */
    public function encode(array $document, string $subject): string
    {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            // Unreachable: $document is built entirely from scalars, strings, and
            // arrays thereof — nothing here can produce a resource or invalid
            // UTF-8 that would make json_encode fail.
            throw new JsonEncodingException("Failed to encode findings as {$subject}.");
        }

        return $json;
    }
}
