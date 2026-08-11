<?php

declare(strict_types=1);

namespace PhpTramp\Ignore;

/**
 * O(1) membership lookups for every suppression collected during indexing:
 * methods (and classes, already expanded to their member FQMNs) suppressed by
 * a `TrampIgnore` attribute, individual params suppressed by the same, and
 * source lines carrying a `// phptramp-ignore` comment. Immutable; produced
 * by {@see \PhpTramp\Index\IndexingVisitor::suppressions()}.
 */
final class SuppressionIndex
{
    private const KEY_SEPARATOR = "\0";

    /** @var array<string, true> */
    private readonly array $methods;

    /** @var array<string, true> */
    private readonly array $params;

    /** @var array<string, array<int, true>> */
    private readonly array $lines;

    /**
     * @param list<string> $methods suppressed method FQMNs (method- or class-level attribute)
     * @param list<array{string, string}> $params [fqmn, paramName] pairs
     * @param array<string, list<int>> $lines file -> lines carrying an ignore comment
     */
    public function __construct(array $methods, array $params, array $lines)
    {
        $this->methods = array_fill_keys($methods, true);
        $this->params = $this->indexParams($params);
        $this->lines = $this->indexLines($lines);
    }

    public function suppressesMethod(string $fqmn): bool
    {
        return isset($this->methods[$fqmn]);
    }

    public function suppressesParam(string $fqmn, string $param): bool
    {
        return isset($this->params[$fqmn . self::KEY_SEPARATOR . $param]);
    }

    public function suppressesLine(string $file, int $line): bool
    {
        return isset($this->lines[$file][$line]);
    }

    /**
     * @param list<array{string, string}> $params
     * @return array<string, true>
     */
    private function indexParams(array $params): array
    {
        $index = [];
        foreach ($params as [$fqmn, $param]) {
            $index[$fqmn . self::KEY_SEPARATOR . $param] = true;
        }

        return $index;
    }

    /**
     * @param array<string, list<int>> $lines
     * @return array<string, array<int, true>>
     */
    private function indexLines(array $lines): array
    {
        $index = [];
        foreach ($lines as $file => $lineNumbers) {
            $index[$file] = array_fill_keys($lineNumbers, true);
        }

        return $index;
    }
}
