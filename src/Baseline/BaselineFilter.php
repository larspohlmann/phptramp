<?php

declare(strict_types=1);

namespace PhpTramp\Baseline;

use PhpTramp\Chain\Finding;

/**
 * Stateless helper for the consume-baseline flow: loads a baseline document
 * from disk and drops findings whose fingerprint is recorded in it. A
 * baselined finding is invisible to every reporter and the exit code —
 * identical to not existing.
 */
final class BaselineFilter
{
    /**
     * @throws BaselineException if the file is unreadable or the document is corrupt
     */
    public function load(string $path): Baseline
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new BaselineException("unable to read baseline file: {$path}");
        }

        return Baseline::fromJson($contents);
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function exclude(array $findings, ?Baseline $baseline): array
    {
        if ($baseline === null) {
            return $findings;
        }

        $kept = [];
        foreach ($findings as $finding) {
            if (! $baseline->has($finding)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }
}
