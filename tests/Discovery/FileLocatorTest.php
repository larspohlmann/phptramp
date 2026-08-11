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
        return (new FileLocator())->locate($options);
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
}
