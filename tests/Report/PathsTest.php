<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testRelativizesPathDirectlyUnderWorkingDirectory(): void
    {
        $paths = new Paths('/home/user/project');

        self::assertSame('src/Demo.php', $paths->relativize('/home/user/project/src/Demo.php'));
    }

    public function testRelativizesNestedSubdirectoryPath(): void
    {
        $paths = new Paths('/home/user/project');

        self::assertSame(
            'src/Chain/Deep/Nested.php',
            $paths->relativize('/home/user/project/src/Chain/Deep/Nested.php'),
        );
    }

    public function testLeavesPathOutsideWorkingDirectoryUnchanged(): void
    {
        $paths = new Paths('/home/user/project');

        self::assertSame('/var/other/Demo.php', $paths->relativize('/var/other/Demo.php'));
    }

    public function testPathEqualToWorkingDirectoryRelativizesToEmptyString(): void
    {
        $paths = new Paths('/home/user/project');

        self::assertSame('', $paths->relativize('/home/user/project'));
    }

    public function testNormalizesTrailingSlashOnWorkingDirectory(): void
    {
        $paths = new Paths('/home/user/project/');

        self::assertSame('src/Demo.php', $paths->relativize('/home/user/project/src/Demo.php'));
    }

    public function testDoesNotTreatDifferentDirectoryWithSamePrefixAsUnderIt(): void
    {
        $paths = new Paths('/home/user/project');

        self::assertSame('/home/user/project-other/Demo.php', $paths->relativize('/home/user/project-other/Demo.php'));
    }
}
