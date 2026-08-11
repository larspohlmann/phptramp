<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpTramp\Index\ClassKind;
use PhpTramp\Index\FileIndex;
use PhpTramp\Index\FileIndexer;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParseException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see FileIndexer} parses and classifies a single source file
 * in isolation, producing a {@see FileIndex} that carries the file's methods,
 * classes, and all three suppression part kinds. Per-file isolation is the
 * foundation for Task 2's per-file cache.
 */
final class FileIndexerTest extends TestCase
{
    private const SAMPLE = <<<'PHP'
        <?php

        namespace Demo;

        class Service
        {
            #[TrampIgnore]
            public function handle(#[TrampIgnore] string $unused): void
            {
                // phptramp-ignore
            }
        }
        PHP;

    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function index(string $code): FileIndex
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);

        return (new FileIndexer())->index($this->file);
    }

    public function testCarriesTheClassifiedMethod(): void
    {
        $index = $this->index(self::SAMPLE);

        $method = $index->methods['Demo\Service::handle'] ?? null;
        self::assertNotNull($method);
        self::assertSame('Demo\Service::handle', $method->fqmn);
        self::assertSame($this->file, $method->file);
        self::assertSame('Demo\Service', $method->class);

        $param = $method->paramNamed('unused');
        self::assertNotNull($param);
        self::assertSame(ParamFate::Unused, $param->fate);
    }

    public function testCarriesTheClass(): void
    {
        $index = $this->index(self::SAMPLE);

        $class = $index->classes['Demo\Service'] ?? null;
        self::assertNotNull($class);
        self::assertSame('Demo\Service', $class->name);
        self::assertSame(ClassKind::ConcreteClass, $class->kind);
    }

    public function testCarriesAllThreeSuppressionPartKinds(): void
    {
        $index = $this->index(self::SAMPLE);

        self::assertContains('Demo\Service::handle', $index->suppressedMethods);
        self::assertSame([['Demo\Service::handle', 'unused']], $index->suppressedParams);
        self::assertArrayHasKey($this->file, $index->suppressedLines);
        self::assertCount(1, $index->suppressedLines[$this->file]);
    }

    public function testUnreadablePathThrowsParseExceptionNamingTheFile(): void
    {
        $missing = sys_get_temp_dir() . '/phptramp_does_not_exist_' . bin2hex(random_bytes(4)) . '.php';

        try {
            (new FileIndexer())->index($missing);
            self::fail('expected ParseException');
        } catch (ParseException $e) {
            self::assertStringContainsString($missing, $e->getMessage());
        }
    }

    public function testSyntaxErrorThrowsParseExceptionNamingTheFile(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, "<?php class Broken {");

        try {
            (new FileIndexer())->index($this->file);
            self::fail('expected ParseException');
        } catch (ParseException $e) {
            self::assertStringContainsString($this->file, $e->getMessage());
        }
    }
}
