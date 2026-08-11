<?php

declare(strict_types=1);

namespace PhpTramp\Ignore;

/**
 * O(1) membership lookups for every suppression collected during indexing:
 * methods (and classes, already expanded to their member FQMNs) suppressed by
 * a `TrampIgnore` attribute, individual params suppressed by the same, and
 * source lines carrying a `// phptramp-ignore` comment. Immutable; produced
 * by {@see \PhpTramp\Index\IndexingVisitor::suppressions()}.
 *
 * The static `*Key()` builders and {@see keys()} expose every configured entry
 * as a stable string key, so the suppression filter can record which entries
 * fired and Task 6 can diff `keys() minus firedKeys` for stale-ignore reporting.
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

    public static function methodKey(string $fqmn): string
    {
        return 'method:' . $fqmn;
    }

    public static function paramKey(string $fqmn, string $param): string
    {
        return 'param:' . $fqmn . '::$' . $param;
    }

    public static function lineKey(string $file, int $line): string
    {
        return 'line:' . $file . ':' . $line;
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
     * Every configured suppression entry as a key. The returned list is in
     * configuration order: methods first, then params, then lines.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach (array_keys($this->methods) as $fqmn) {
            $keys[] = self::methodKey($fqmn);
        }

        foreach ($this->params as $combined => $_) {
            [$fqmn, $param] = explode(self::KEY_SEPARATOR, $combined, 2);
            $keys[] = self::paramKey($fqmn, $param);
        }

        foreach ($this->lines as $file => $lineNumbers) {
            foreach (array_keys($lineNumbers) as $line) {
                $keys[] = self::lineKey($file, (int) $line);
            }
        }

        return $keys;
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
