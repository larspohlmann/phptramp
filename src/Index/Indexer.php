<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpTramp\Ignore\SuppressionIndex;

/**
 * Orchestrates per-file indexing: each located file is parsed and classified
 * by {@see FileIndexer} in isolation, then the per-file results are merged in
 * input order into one whole-project {@see MethodIndex}. The merge preserves
 * the prior single-visitor accumulation semantics — later files win on
 * duplicate FQMN/FQCN keys, suppression parts are concatenated — so the
 * resulting index is byte-identical to the pre-refactor output.
 *
 * @phpstan-import-type PendingMethod from IndexingVisitor
 */
final class Indexer
{
    public function __construct(
        private readonly FileIndexer $fileIndexer = new FileIndexer(),
    ) {
    }

    /**
     * @param list<string> $files
     *
     * @throws ParseException if any file cannot be read or parsed (per-file messages joined)
     */
    public function index(array $files): MethodIndex
    {
        /** @var array<string, MethodInfo> $mergedMethods */
        $mergedMethods = [];
        /** @var array<string, ClassInfo> $mergedClasses */
        $mergedClasses = [];
        /** @var list<string> $suppressedMethods */
        $suppressedMethods = [];
        /** @var list<array{string, string}> $suppressedParams */
        $suppressedParams = [];
        /** @var array<string, list<int>> $suppressedLines */
        $suppressedLines = [];
        $errors = [];

        foreach ($files as $file) {
            try {
                $fileIndex = $this->fileIndexer->index($file);
            } catch (ParseException $e) {
                $errors[] = $e->getMessage();
                continue;
            }

            $mergedMethods = array_merge($mergedMethods, $fileIndex->methods);
            $mergedClasses = array_merge($mergedClasses, $fileIndex->classes);
            array_push($suppressedMethods, ...$fileIndex->suppressedMethods);
            array_push($suppressedParams, ...$fileIndex->suppressedParams);
            $suppressedLines = array_merge($suppressedLines, $fileIndex->suppressedLines);
        }

        if ($errors !== []) {
            throw new ParseException("parse errors:\n" . implode("\n", $errors));
        }

        return new MethodIndex(
            $mergedMethods,
            $mergedClasses,
            new SuppressionIndex($suppressedMethods, $suppressedParams, $suppressedLines),
        );
    }
}
