<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Discovery;

use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;
use PhpTramp\Discovery\FileLocator;
use PHPUnit\Framework\TestCase;

final class FileLocatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/phptramp-loc-' . uniqid('', true);
        mkdir($base . '/sub/deep', 0777, true);
        file_put_contents($base . '/a.php', "<?php\n");
        file_put_contents($base . '/sub/b.php', "<?php\n");
        file_put_contents($base . '/sub/deep/c.php', "<?php\n");
        file_put_contents($base . '/notes.txt', "text\n");
        $this->root = $base;
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = (string) $item;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->root);
    }

    /**
     * @return list<string>
     */
    private function locate(Options $options): array
    {
        return (new FileLocator($this->root))->locate($options);
    }

    private function real(string $relative): string
    {
        $path = realpath($this->root . '/' . $relative);
        self::assertIsString($path);

        return $path;
    }

    public function testRecursesFoldersAndKeepsOnlyPhpFiles(): void
    {
        $found = $this->locate(new Options(folders: [$this->root]));

        self::assertSame(
            [$this->real('a.php'), $this->real('sub/b.php'), $this->real('sub/deep/c.php')],
            $found
        );
    }

    public function testResultIsSortedAndRealpathNormalized(): void
    {
        $found = $this->locate(new Options(folders: [$this->root . '/sub/..']));

        $sorted = $found;
        sort($sorted);
        self::assertSame($sorted, $found);
        foreach ($found as $path) {
            self::assertSame(realpath($path), $path);
        }
    }

    public function testExplicitFilesAreIncludedAndDeduplicated(): void
    {
        $found = $this->locate(new Options(
            folders: [$this->root],
            files: [$this->root . '/a.php'],
        ));

        self::assertSame(
            [$this->real('a.php'), $this->real('sub/b.php'), $this->real('sub/deep/c.php')],
            $found
        );
    }

    public function testMissingFolderThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->locate(new Options(folders: [$this->root . '/does-not-exist']));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->locate(new Options(files: [$this->root . '/ghost.php']));
    }

    public function testExistingFileGivenAsFolderThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->locate(new Options(folders: [$this->root . '/a.php']));
    }

    public function testUppercasePhpExtensionIsMatchedCaseInsensitively(): void
    {
        file_put_contents($this->root . '/Upper.PHP', "<?php\n");

        self::assertContains($this->real('Upper.PHP'), $this->locate(new Options(folders: [$this->root])));
    }

    public function testExcludeGlobDropsNestedDiscoveredFile(): void
    {
        mkdir($this->root . '/vendor/pkg/src', 0777, true);
        file_put_contents($this->root . '/vendor/pkg/src/X.php', "<?php\n");

        $found = $this->locate(new Options(folders: [$this->root], exclude: ['vendor/*']));

        self::assertNotContains($this->real('vendor/pkg/src/X.php'), $found);
        self::assertContains($this->real('a.php'), $found);
    }

    public function testExcludeGlobDropsDiscoveredFileByBasenamePattern(): void
    {
        file_put_contents($this->root . '/sub/bThingTest.php', "<?php\n");

        $found = $this->locate(new Options(folders: [$this->root], exclude: ['*Test.php']));

        self::assertNotContains($this->real('sub/bThingTest.php'), $found);
        self::assertContains($this->real('a.php'), $found);
    }

    public function testExcludeMatchesRawPathWhenFileIsOutsideWorkingDirectory(): void
    {
        $otherDirectory = sys_get_temp_dir() . '/phptramp-loc-other-' . uniqid('', true);
        mkdir($otherDirectory);

        try {
            $found = (new FileLocator($otherDirectory))->locate(
                new Options(folders: [$this->root], exclude: [$this->real('a.php')])
            );

            self::assertNotContains($this->real('a.php'), $found);
            self::assertContains($this->real('sub/b.php'), $found);
        } finally {
            rmdir($otherDirectory);
        }
    }

    public function testExplicitFileBeatsExclude(): void
    {
        $found = $this->locate(new Options(
            folders: [$this->root],
            files: [$this->root . '/a.php'],
            exclude: ['a.php', '*.php'],
        ));

        self::assertContains($this->real('a.php'), $found);
        self::assertNotContains($this->real('sub/b.php'), $found);
    }
}
