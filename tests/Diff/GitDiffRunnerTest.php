<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Diff;

use PhpTramp\Diff\DiffException;
use PhpTramp\Diff\GitDiffRunner;
use PHPUnit\Framework\TestCase;

final class GitDiffRunnerTest extends TestCase
{
    private string $repository;

    protected function setUp(): void
    {
        exec('git --version', $output, $exitCode);
        if ($exitCode !== 0) {
            self::markTestSkipped('git is not available on this machine');
        }

        $this->repository = sys_get_temp_dir() . '/phptramp-gitdiff-' . uniqid('', true);
        mkdir($this->repository, 0777, true);
        $this->buildRepositoryWithChangeOnFeatureBranch();
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->repository, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $path = (string) $item;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->repository);
    }

    private function buildRepositoryWithChangeOnFeatureBranch(): void
    {
        $this->runGit(['init', '-q', '-b', 'main']);
        $this->runGit(['config', 'user.email', 'phptramp-test@example.com']);
        $this->runGit(['config', 'user.name', 'phptramp test']);

        file_put_contents($this->repository . '/tracked.php', "<?php\n\$one = 1;\n");
        $this->runGit(['add', 'tracked.php']);
        $this->runGit(['commit', '-q', '-m', 'initial commit']);

        $this->runGit(['checkout', '-q', '-b', 'feature']);
        file_put_contents($this->repository . '/tracked.php', "<?php\n\$one = 1;\n\$two = 2;\n");
        $this->runGit(['add', 'tracked.php']);
        $this->runGit(['commit', '-q', '-m', 'second commit']);
    }

    /** @param list<string> $arguments */
    private function runGit(array $arguments): void
    {
        $command = array_merge(['git'], $arguments);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->repository);
        self::assertNotFalse($process, 'failed to start git while setting up the test repository');

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, 'git command failed while setting up the test repository');
    }

    public function testReturnsUnifiedDiffContainingTheChangedFilesHunk(): void
    {
        $diff = (new GitDiffRunner())->run('main', $this->repository);

        self::assertStringContainsString('+++ b/tracked.php', $diff);
        self::assertStringContainsString('@@ -2,0 +3 @@', $diff);
    }

    public function testUnknownBaseRefThrowsWithGitsStderr(): void
    {
        $this->expectException(DiffException::class);
        $this->expectExceptionMessageMatches('/this-branch-does-not-exist/');

        (new GitDiffRunner())->run('this-branch-does-not-exist', $this->repository);
    }

    public function testNonRepositoryDirectoryThrows(): void
    {
        $nonRepository = sys_get_temp_dir() . '/phptramp-gitdiff-non-repo-' . uniqid('', true);
        mkdir($nonRepository);

        try {
            $this->expectException(DiffException::class);
            (new GitDiffRunner())->run('main', $nonRepository);
        } finally {
            rmdir($nonRepository);
        }
    }

    public function testLeadingDashBaseIsRejectedBeforeReachingGit(): void
    {
        $injectedOutputPath = $this->repository . '-injected-output.txt';

        try {
            $this->expectException(DiffException::class);
            (new GitDiffRunner())->run('--output=' . $injectedOutputPath, $this->repository);
        } finally {
            self::assertFileDoesNotExist(
                $injectedOutputPath,
                'a leading-dash base must never reach git as an option'
            );
        }
    }
}
