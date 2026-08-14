<?php

declare(strict_types=1);

namespace PhpTramp\Diff;

/**
 * Parses unified diff text into a {@see ChangedLines} set.
 *
 * Only the new-file side matters: the target path comes from `+++ b/<path>`
 * lines (the `a/`/`b/` prefix is stripped, and its absence is tolerated), and
 * the changed line numbers come from hunk headers (`@@ -o,n +s,c @@`). Every
 * other diff line — `diff --git`, `index`, rename/copy/mode headers, hunk
 * body lines, `\ No newline at end of file` — is noise this parser ignores.
 *
 * Structural lines (`+++ `, `@@ `) are only recognized outside a hunk body: a
 * hunk body can itself contain a line whose *content* starts with `++ ` (an
 * added source line "++ i" becomes the diff line "+++ i" once the leading `+`
 * marker is prepended), which would otherwise be misread as a new file
 * header. The parser instead tracks how many old-side and new-side body
 * lines the current hunk header declared, and only resumes looking for
 * structure once that many have been consumed.
 */
final class DiffParser
{
    private const HUNK_HEADER_PATTERN = '/^@@ -\d+(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/';
    private const NO_NEWLINE_MARKER = '\\';
    private const CONTEXT_MARKER = ' ';
    private const REMOVED_MARKER = '-';
    private const ADDED_MARKER = '+';

    /** @throws DiffException when the text is not a unified diff */
    public function parse(string $unifiedDiff): ChangedLines
    {
        if ($unifiedDiff === '') {
            return new ChangedLines([]);
        }

        $lines = $this->splitIntoLines($unifiedDiff);
        $this->assertHasDiffStructure($lines);

        return new ChangedLines($this->collectChangedLinesByFile($lines));
    }

    /** @return list<string> */
    private function splitIntoLines(string $unifiedDiff): array
    {
        return explode("\n", str_replace("\r", '', $unifiedDiff));
    }

    /** @param list<string> $lines */
    private function assertHasDiffStructure(array $lines): void
    {
        foreach ($lines as $line) {
            $isDiffTargetLine = str_starts_with($line, '+++ ');
            $isHunkHeaderLine = $this->matchHunkHeader($line) !== null;

            if ($isDiffTargetLine || $isHunkHeaderLine) {
                return;
            }
        }

        throw new DiffException('text is not a unified diff: no "+++" or "@@" hunk header found');
    }

    /**
     * @param list<string> $lines
     * @return array<string, list<int>>
     */
    private function collectChangedLinesByFile(array $lines): array
    {
        $linesByFile = [];
        $currentFile = null;
        $totalLines = count($lines);
        $index = 0;

        while ($index < $totalLines) {
            $line = $lines[$index];

            if (str_starts_with($line, '+++ ')) {
                $currentFile = $this->targetFile($line);
                $index++;
                continue;
            }

            $hunk = $this->matchHunkHeader($line);
            if ($hunk === null) {
                $index++;
                continue;
            }

            [$oldLineCount, $newStartLine, $newLineCount] = $hunk;
            $hunkLines = $this->linesInRange($newStartLine, $newLineCount);
            if ($hunkLines !== [] && $currentFile !== null) {
                $linesByFile[$currentFile] = [...($linesByFile[$currentFile] ?? []), ...$hunkLines];
            }

            $index = $this->skipHunkBody($lines, $index + 1, $oldLineCount, $newLineCount);
        }

        return $linesByFile;
    }

    /** @return list<int> */
    private function linesInRange(int $startLine, int $lineCount): array
    {
        if ($lineCount === 0) {
            return [];
        }

        return range($startLine, $startLine + $lineCount - 1);
    }

    /**
     * Advances past the body lines a hunk header declared: exactly
     * $remainingOldLines old-side lines (markers ' '/'-') and $remainingNewLines
     * new-side lines (markers ' '/'+'), in whatever order/interleaving they
     * appear. Returns the index of the first line after the body.
     *
     * @param list<string> $lines
     */
    private function skipHunkBody(array $lines, int $index, int $remainingOldLines, int $remainingNewLines): int
    {
        $totalLines = count($lines);

        while ($index < $totalLines && ($remainingOldLines > 0 || $remainingNewLines > 0)) {
            $remaining = $this->consumeHunkBodyLine($lines[$index], $remainingOldLines, $remainingNewLines);
            if ($remaining === null) {
                break;
            }

            [$remainingOldLines, $remainingNewLines] = $remaining;
            $index++;
        }

        return $index;
    }

    /**
     * Accounts one hunk-body line against the still-owed old-side/new-side
     * counts, based on its leading marker. Null means the line does not belong
     * to the hunk body (an unexpected marker, or a count already exhausted) —
     * the caller stops consuming and treats it as ordinary diff text again.
     *
     * @return array{0: int, 1: int}|null
     */
    private function consumeHunkBodyLine(string $line, int $remainingOldLines, int $remainingNewLines): ?array
    {
        $marker = $line[0] ?? '';

        if ($marker === self::NO_NEWLINE_MARKER) {
            return [$remainingOldLines, $remainingNewLines];
        }

        if ($marker === self::CONTEXT_MARKER && $remainingOldLines > 0 && $remainingNewLines > 0) {
            return [$remainingOldLines - 1, $remainingNewLines - 1];
        }

        if ($marker === self::REMOVED_MARKER && $remainingOldLines > 0) {
            return [$remainingOldLines - 1, $remainingNewLines];
        }

        if ($marker === self::ADDED_MARKER && $remainingNewLines > 0) {
            return [$remainingOldLines, $remainingNewLines - 1];
        }

        return null;
    }

    /** Null for a deleted file (`+++ /dev/null`); the `a/`/`b/` prefix, if present, is stripped. */
    private function targetFile(string $plusPlusPlusLine): ?string
    {
        $path = substr($plusPlusPlusLine, strlen('+++ '));

        if ($path === '/dev/null') {
            return null;
        }

        if (str_starts_with($path, 'a/') || str_starts_with($path, 'b/')) {
            return substr($path, 2);
        }

        return $path;
    }

    /** @return array{0: int, 1: int, 2: int}|null old-side line count, new-side start line, new-side line count */
    private function matchHunkHeader(string $line): ?array
    {
        if (preg_match(self::HUNK_HEADER_PATTERN, $line, $matches) !== 1) {
            return null;
        }

        // Group 1 is always present in $matches (a required group follows it, so
        // PCRE fills it with '' when unmatched); group 3 is trailing, so PCRE
        // omits it entirely when unmatched instead of filling it with ''.
        $oldLineCount = $matches[1] !== '' ? (int) $matches[1] : 1;
        $newStartLine = (int) $matches[2];
        $newLineCount = isset($matches[3]) ? (int) $matches[3] : 1;

        return [$oldLineCount, $newStartLine, $newLineCount];
    }
}
