<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;

/**
 * Accumulates every `TrampIgnore` attribute and `// phptramp-ignore` comment
 * seen while {@see IndexingVisitor} traverses a file, and exposes them as the
 * raw parts the Indexer merges across files before building a
 * {@see \PhpTramp\Ignore\SuppressionIndex}. A separate collaborator so that
 * suppression bookkeeping - a distinct responsibility from recording the class
 * hierarchy and pending methods - does not inflate IndexingVisitor's own
 * complexity.
 *
 * Attribute matching is by short name only: an attribute counts iff its
 * name's last segment is `TrampIgnore`, so analyzed codebases are never
 * required to autoload our attribute class.
 *
 * @phpstan-import-type PendingMethod from IndexingVisitor
 */
final class SuppressionCollector
{
    private const IGNORE_COMMENT_MARKER = '// phptramp-ignore';
    private const IGNORE_ATTRIBUTE_NAME = 'TrampIgnore';

    /** @var list<string> */
    private array $suppressedMethods = [];

    /** @var list<string> */
    private array $suppressedClasses = [];

    /** @var list<array{string, string}> */
    private array $suppressedParams = [];

    /** @var array<string, list<int>> */
    private array $ignoreLines = [];

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    public function recordClassAttributes(string $fqcn, array $attrGroups): void
    {
        if ($this->hasIgnoreAttribute($attrGroups)) {
            $this->suppressedClasses[] = $fqcn;
        }
    }

    public function recordFunctionLikeAttributes(string $fqmn, FunctionLike $node): void
    {
        if ($this->hasIgnoreAttribute($node->getAttrGroups())) {
            $this->suppressedMethods[] = $fqmn;
        }

        foreach ($node->getParams() as $param) {
            if ($this->hasIgnoreAttribute($param->attrGroups)) {
                $this->recordSuppressedParam($fqmn, $param);
            }
        }
    }

    public function recordIgnoreComments(string $file, string $code): void
    {
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && $this->isIgnoreCommentToken($token)) {
                $this->ignoreLines[$file][] = $token[2];
            }
        }
    }

    /**
     * The raw suppression parts accumulated so far, before they are aggregated
     * into a {@see \PhpTramp\Ignore\SuppressionIndex}. The Indexer merges these
     * across per-file visitors and builds one index from the concatenation.
     *
     * @param list<PendingMethod> $pending
     *
     * @return array{methods: list<string>, params: list<array{string, string}>, lines: array<string, list<int>>}
     */
    public function parts(array $pending): array
    {
        return [
            'methods' => $this->expandClassSuppressions($pending),
            'params' => $this->suppressedParams,
            'lines' => $this->ignoreLines,
        ];
    }

    /**
     * @param list<PendingMethod> $pending
     * @return list<string>
     */
    private function expandClassSuppressions(array $pending): array
    {
        $methods = $this->suppressedMethods;
        foreach ($pending as $entry) {
            if ($entry['class'] !== null && in_array($entry['class'], $this->suppressedClasses, true)) {
                $methods[] = $entry['fqmn'];
            }
        }

        return $methods;
    }

    private function recordSuppressedParam(string $fqmn, Param $param): void
    {
        if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
            return;
        }

        $this->suppressedParams[] = [$fqmn, $param->var->name];
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    private function hasIgnoreAttribute(array $attrGroups): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->getLast() === self::IGNORE_ATTRIBUTE_NAME) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array{0: int, 1: string, 2: int} $token
     */
    private function isIgnoreCommentToken(array $token): bool
    {
        [$id, $text] = $token;

        return ($id === T_COMMENT || $id === T_DOC_COMMENT) && str_contains($text, self::IGNORE_COMMENT_MARKER);
    }
}
