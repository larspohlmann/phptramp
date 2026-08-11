<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Diff;

use PhpTramp\Diff\ChangedLines;
use PhpTramp\Diff\DiffException;
use PhpTramp\Diff\DiffParser;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    private function parse(string $unifiedDiff): ChangedLines
    {
        return (new DiffParser())->parse($unifiedDiff);
    }

    public function testEmptyStringParsesToEmptyChangedLines(): void
    {
        self::assertTrue($this->parse('')->isEmpty());
    }

    public function testTextWithoutDiffStructureThrows(): void
    {
        $this->expectException(DiffException::class);

        $this->parse("hello world\nnot a diff at all\n");
    }

    public function testSingleHunkWithFullNewRangeStripsBPrefix(): void
    {
        $diff = <<<DIFF
        diff --git a/src/Foo.php b/src/Foo.php
        index abc123..def456 100644
        --- a/src/Foo.php
        +++ b/src/Foo.php
        @@ -10,3 +10,4 @@ class Foo
         line1
        +line2
         line3
         line4
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Foo.php', 10));
        self::assertTrue($changedLines->containsLine('src/Foo.php', 13));
        self::assertFalse($changedLines->containsLine('src/Foo.php', 14));
        self::assertFalse($changedLines->containsLine('src/Foo.php', 9));
    }

    public function testAPrefixIsAlsoStripped(): void
    {
        $diff = <<<DIFF
        --- a/src/Bar.php
        +++ a/src/Bar.php
        @@ -1,2 +1,2 @@
        -old
        +new
        DIFF;

        self::assertTrue($this->parse($diff)->containsLine('src/Bar.php', 1));
    }

    public function testNoPrefixIsTolerated(): void
    {
        $diff = <<<DIFF
        --- path/no/prefix.php
        +++ path/no/prefix.php
        @@ -1,1 +2,1 @@
        +x
        DIFF;

        self::assertTrue($this->parse($diff)->containsLine('path/no/prefix.php', 2));
        self::assertFalse($this->parse($diff)->containsLine('path/no/prefix.php', 1));
    }

    public function testOmittedNewCountMeansSingleLine(): void
    {
        $diff = <<<DIFF
        --- a/src/Baz.php
        +++ b/src/Baz.php
        @@ -1,3 +5 @@
        +x
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Baz.php', 5));
        self::assertFalse($changedLines->containsLine('src/Baz.php', 6));
    }

    public function testPureDeletionHunkContributesNothing(): void
    {
        $diff = <<<DIFF
        --- a/src/Deleted.php
        +++ b/src/Deleted.php
        @@ -5,3 +5,0 @@
        -a
        -b
        -c
        DIFF;

        self::assertTrue($this->parse($diff)->isEmpty());
    }

    public function testDevNullNewFileSkipsFileEntirely(): void
    {
        $diff = <<<DIFF
        --- a/src/Removed.php
        +++ /dev/null
        @@ -1,2 +1,2 @@
        +still counted against a phantom range
        +another line
        DIFF;

        self::assertTrue($this->parse($diff)->isEmpty());
        self::assertFalse($this->parse($diff)->containsLine('src/Removed.php', 1));
    }

    public function testRenameCopyModeIndexAndNoNewlineNoiseAreTolerated(): void
    {
        $diff = <<<DIFF
        diff --git a/old.php b/new.php
        similarity index 100%
        rename from old.php
        rename to new.php
        copy from old.php
        copy to new.php
        old mode 100644
        new mode 100755
        index abc123..def456 100644
        --- a/new.php
        +++ b/new.php
        @@ -1,2 +1,2 @@
        -old line
        +new line
        \ No newline at end of file
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('new.php', 1));
        self::assertTrue($changedLines->containsLine('new.php', 2));
    }

    public function testCrlfLineEndingsAreAccepted(): void
    {
        $diff = implode("\r\n", [
            '--- a/src/Foo.php',
            '+++ b/src/Foo.php',
            '@@ -10,3 +10,4 @@ class Foo',
            ' line1',
            '+line2',
            ' line3',
            ' line4',
            '',
        ]);

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Foo.php', 10));
        self::assertTrue($changedLines->containsLine('src/Foo.php', 13));
    }

    public function testMultipleHunksInSameFileAccumulate(): void
    {
        $diff = <<<DIFF
        --- a/src/Multi.php
        +++ b/src/Multi.php
        @@ -1,1 +1,1 @@
        +x
        @@ -20,1 +20,2 @@
        +y
        +z
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Multi.php', 1));
        self::assertTrue($changedLines->containsLine('src/Multi.php', 20));
        self::assertTrue($changedLines->containsLine('src/Multi.php', 21));
        self::assertFalse($changedLines->containsLine('src/Multi.php', 15));
    }

    public function testAddedLineLookingLikeAFileHeaderIsNotMistakenForOne(): void
    {
        // The hunk body's added line has source content "++ i", which the diff
        // renders as "+++ i" once its own "+" marker is prepended — indistinguishable
        // from a real "+++ <path>" header unless the parser tracks hunk-body extent.
        $diff = "--- a/src/Foo.php\n"
            . "+++ b/src/Foo.php\n"
            . "@@ -1,1 +1,2 @@\n"
            . " context\n"
            . "+++ i\n"
            . "@@ -50,1 +50,1 @@\n"
            . "+second hunk line\n";

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Foo.php', 50));
        self::assertFalse($changedLines->containsLine('i', 50));
    }

    public function testDeletionOnlyHunkBodyIsSkippedBeforeANonDeletionHunkInTheSameFile(): void
    {
        $diff = <<<DIFF
        --- a/src/Mixed.php
        +++ b/src/Mixed.php
        @@ -1,3 +1,0 @@
        -a
        -b
        -c
        @@ -10,1 +10,2 @@
         context
        +added
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/Mixed.php', 10));
        self::assertTrue($changedLines->containsLine('src/Mixed.php', 11));
        self::assertFalse($changedLines->isEmpty());
    }

    public function testMultipleFilesAreKeptSeparate(): void
    {
        $diff = <<<DIFF
        --- a/src/One.php
        +++ b/src/One.php
        @@ -1,1 +1,1 @@
        +x
        --- a/src/Two.php
        +++ b/src/Two.php
        @@ -5 +9 @@
        +y
        DIFF;

        $changedLines = $this->parse($diff);

        self::assertTrue($changedLines->containsLine('src/One.php', 1));
        self::assertFalse($changedLines->containsLine('src/One.php', 9));
        self::assertTrue($changedLines->containsLine('src/Two.php', 9));
        self::assertFalse($changedLines->containsLine('src/Two.php', 1));
    }
}
