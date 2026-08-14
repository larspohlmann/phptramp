<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Diff;

use PhpTramp\Diff\ChangedLines;
use PHPUnit\Framework\TestCase;

final class ChangedLinesTest extends TestCase
{
    private string $baseDirectory;

    protected function setUp(): void
    {
        $baseDirectory = sys_get_temp_dir() . '/phptramp-changed-lines-' . uniqid();
        mkdir($baseDirectory . '/src', recursive: true);
        file_put_contents($baseDirectory . '/src/Foo.php', '<?php');
        $this->baseDirectory = $baseDirectory;
    }

    protected function tearDown(): void
    {
        unlink($this->baseDirectory . '/src/Foo.php');
        rmdir($this->baseDirectory . '/src');
        rmdir($this->baseDirectory);
    }

    public function testIsEmptyIsTrueForNoFiles(): void
    {
        self::assertTrue((new ChangedLines([]))->isEmpty());
    }

    public function testIsEmptyIsFalseWhenAFileHasLines(): void
    {
        self::assertFalse((new ChangedLines(['src/Foo.php' => [1]]))->isEmpty());
    }

    public function testContainsLineIsTrueForAKnownLine(): void
    {
        $changedLines = new ChangedLines(['src/Foo.php' => [1, 2, 3]]);

        self::assertTrue($changedLines->containsLine('src/Foo.php', 2));
    }

    public function testContainsLineIsFalseForAnUnlistedLine(): void
    {
        $changedLines = new ChangedLines(['src/Foo.php' => [1, 2, 3]]);

        self::assertFalse($changedLines->containsLine('src/Foo.php', 5));
    }

    public function testContainsLineIsFalseForAnUnknownFile(): void
    {
        $changedLines = new ChangedLines(['src/Foo.php' => [1, 2, 3]]);

        self::assertFalse($changedLines->containsLine('src/Bar.php', 1));
    }

    public function testResolveAgainstRekeysPathsToRealpath(): void
    {
        $changedLines = new ChangedLines(['src/Foo.php' => [1, 2]]);

        $resolved = $changedLines->resolveAgainst($this->baseDirectory);

        $expectedRealPath = realpath($this->baseDirectory . '/src/Foo.php');
        self::assertIsString($expectedRealPath);
        self::assertTrue($resolved->containsLine($expectedRealPath, 1));
        self::assertFalse($resolved->containsLine('src/Foo.php', 1));
    }

    public function testResolveAgainstDropsFilesThatDoNotResolve(): void
    {
        $changedLines = new ChangedLines(['src/Deleted.php' => [1]]);

        $resolved = $changedLines->resolveAgainst($this->baseDirectory);

        self::assertTrue($resolved->isEmpty());
    }

    public function testResolveAgainstKeepsResolvableFilesAndDropsDeletedOnes(): void
    {
        $changedLines = new ChangedLines([
            'src/Foo.php' => [1],
            'src/Deleted.php' => [7],
        ]);

        $resolved = $changedLines->resolveAgainst($this->baseDirectory);

        $expectedRealPath = realpath($this->baseDirectory . '/src/Foo.php');
        self::assertIsString($expectedRealPath);
        self::assertTrue($resolved->containsLine($expectedRealPath, 1));
        self::assertFalse($resolved->isEmpty());
    }
}
