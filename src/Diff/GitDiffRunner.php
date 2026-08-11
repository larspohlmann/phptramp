<?php

declare(strict_types=1);

namespace PhpTramp\Diff;

/**
 * Produces the unified diff text {@see DiffParser} consumes, by shelling out
 * to `git diff --unified=0 <base>...HEAD` (three-dot: merge-base semantics,
 * matching what CI reviews) in a given working directory.
 */
final class GitDiffRunner
{
    /**
     * @throws DiffException with git's stderr when git fails (no repo, unknown
     *     ref), or when $base looks like an option instead of a ref
     */
    public function run(string $base, string $workingDirectory): string
    {
        if (str_starts_with($base, '-')) {
            throw new DiffException("base ref must not start with '-': {$base}");
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['git', 'diff', '--unified=0', $base . '...HEAD'],
            $descriptorSpec,
            $pipes,
            $workingDirectory
        );

        if ($process === false) {
            throw new DiffException("failed to start git in {$workingDirectory}");
        }

        // stdin is never written to; closing it immediately avoids git ever
        // blocking on it and lets us read the output pipes without deadlock.
        fclose($pipes[0]);
        $standardOutput = $this->readAndClosePipe($pipes[1]);
        $standardError = $this->readAndClosePipe($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new DiffException($this->failureMessage($standardError, $exitCode));
        }

        return $standardOutput;
    }

    /** @param resource $pipe */
    private function readAndClosePipe($pipe): string
    {
        $contents = stream_get_contents($pipe);
        fclose($pipe);

        return $contents !== false ? $contents : '';
    }

    /** Falls back to the exit code when git wrote nothing to stderr (e.g. killed by a signal). */
    private function failureMessage(string $standardError, int $exitCode): string
    {
        $trimmedError = trim($standardError);

        return $trimmedError !== '' ? $trimmedError : "git exited with status {$exitCode} and no stderr output";
    }
}
