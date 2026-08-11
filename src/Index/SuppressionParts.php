<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpTramp\Ignore\SuppressionIndex;

/**
 * The raw suppression facts one file contributes before they are aggregated
 * into a {@see SuppressionIndex}: methods (and class-expanded members) and
 * params carrying a `TrampIgnore` attribute, plus the source lines carrying a
 * `// phptramp-ignore` comment. A self-contained, serializable part of a
 * {@see FileIndex} so the per-file cache can persist and reload it.
 */
final class SuppressionParts
{
    /**
     * @param list<string> $methods FQMNs suppressed by a method- or class-level attribute
     * @param list<array{string, string}> $params [fqmn, paramName] pairs
     * @param array<string, list<int>> $lines file -> lines carrying an ignore comment
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $params,
        public readonly array $lines,
    ) {
    }

    /**
     * Folds per-file parts into one: methods and params are concatenated in
     * input order, ignore lines are unioned by file. Folding all parts in a
     * single pass keeps the merge linear in the total number of entries.
     */
    public static function merge(self ...$parts): self
    {
        $methods = [];
        $params = [];
        $lines = [];

        foreach ($parts as $part) {
            foreach ($part->methods as $method) {
                $methods[] = $method;
            }
            foreach ($part->params as $param) {
                $params[] = $param;
            }
            foreach ($part->lines as $file => $fileLines) {
                $lines[$file] = $fileLines;
            }
        }

        return new self($methods, $params, $lines);
    }

    public function toSuppressionIndex(): SuppressionIndex
    {
        return new SuppressionIndex($this->methods, $this->params, $this->lines);
    }
}
