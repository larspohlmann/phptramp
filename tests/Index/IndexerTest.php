<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpTramp\Index\ClassKind;
use PhpTramp\Index\Indexer;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Index\ParseException;
use PHPUnit\Framework\TestCase;

final class IndexerTest extends TestCase
{
    private const SAMPLE = <<<'PHP'
        <?php

        namespace Demo;

        interface Handler
        {
        }

        trait Logs
        {
            public function log(string $message): void
            {
            }
        }

        class Service extends Base implements Handler
        {
            use Logs;

            public function handle(Cfg $config, int $count): void
            {
            }

            public function collect(string ...$parts): void
            {
            }

            public function mutate(array &$ref): void
            {
            }
        }

        function helper(Cfg $config): void
        {
        }
        PHP;

    private string $file = '';

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }

        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function index(string $code): MethodIndex
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);

        return (new Indexer())->index([$this->file]);
    }

    private function writeFile(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        $this->files[] = $file;

        return $file;
    }

    /**
     * @return array{MethodIndex, string, string}
     */
    private function indexFiles(string $codeA, string $codeB): array
    {
        $fileA = $this->writeFile($codeA);
        $fileB = $this->writeFile($codeB);

        return [(new Indexer())->index([$fileA, $fileB]), $fileA, $fileB];
    }

    private function lineOf(string $code, string $marker): int
    {
        return substr_count(substr($code, 0, strpos($code, $marker)), "\n") + 1;
    }

    public function testIndexesMethodsFunctionsAndTraitMethods(): void
    {
        $index = $this->index(self::SAMPLE);

        self::assertNotNull($index->get('Demo\Service::handle'));
        self::assertNotNull($index->get('Demo\helper'));
        self::assertNotNull($index->get('Demo\Logs::log'));
        self::assertNull($index->get('Demo\Service::missing'));
    }

    public function testFqmnUsesDoubleColonForMethodsAndBareNameForFunctions(): void
    {
        $index = $this->index(self::SAMPLE);

        $handle = $index->get('Demo\Service::handle');
        $helper = $index->get('Demo\helper');
        self::assertNotNull($handle);
        self::assertNotNull($helper);
        self::assertSame('Demo\Service::handle', $handle->fqmn);
        self::assertSame('Demo\helper', $helper->fqmn);
        self::assertSame('Demo\Service', $handle->class);
        self::assertNull($helper->class);
    }

    public function testParamNamesPositionsAndTypes(): void
    {
        $handle = $this->index(self::SAMPLE)->get('Demo\Service::handle');
        self::assertNotNull($handle);

        self::assertSame('config', $handle->params[0]->name);
        self::assertSame(0, $handle->params[0]->position);
        self::assertSame('Demo\Cfg', $handle->params[0]->type);

        self::assertSame('count', $handle->params[1]->name);
        self::assertSame(1, $handle->params[1]->position);
        self::assertSame('int', $handle->params[1]->type);
    }

    public function testByRefFlag(): void
    {
        $mutate = $this->index(self::SAMPLE)->get('Demo\Service::mutate');
        self::assertNotNull($mutate);
        self::assertTrue($mutate->params[0]->byRef);
        self::assertFalse($mutate->params[0]->variadic);
    }

    public function testVariadicFlag(): void
    {
        $collect = $this->index(self::SAMPLE)->get('Demo\Service::collect');
        self::assertNotNull($collect);
        self::assertTrue($collect->params[0]->variadic);
        self::assertFalse($collect->params[0]->byRef);
    }

    public function testRecordsClassHierarchyFacts(): void
    {
        $service = $this->index(self::SAMPLE)->classInfo('Demo\Service');
        self::assertNotNull($service);
        self::assertSame('Demo\Base', $service->parent);
        self::assertSame(['Demo\Handler'], $service->interfaces);
        self::assertSame(['Demo\Logs'], $service->traits);
    }

    public function testRecordsClassKindForEveryDeclarationShape(): void
    {
        $code = <<<'PHP'
            <?php

            namespace Demo;

            class Concrete
            {
            }

            abstract class Abstracted
            {
            }

            interface Contract
            {
            }

            trait Helper
            {
            }

            enum Suit
            {
                case Hearts;
            }
            PHP;

        $index = $this->index($code);

        self::assertSame(ClassKind::ConcreteClass, $index->classInfo('Demo\Concrete')?->kind);
        self::assertSame(ClassKind::AbstractClass, $index->classInfo('Demo\Abstracted')?->kind);
        self::assertSame(ClassKind::Interface_, $index->classInfo('Demo\Contract')?->kind);
        self::assertSame(ClassKind::Trait_, $index->classInfo('Demo\Helper')?->kind);
        self::assertSame(ClassKind::Enum_, $index->classInfo('Demo\Suit')?->kind);
    }

    public function testParseErrorThrowsParseException(): void
    {
        $this->expectException(ParseException::class);
        $this->index("<?php class Broken {");
    }

    public function testParseErrorMessageNamesTheFileAndReason(): void
    {
        try {
            $this->index("<?php class Broken {");
            self::fail('expected ParseException');
        } catch (ParseException $e) {
            self::assertStringContainsString('parse errors', $e->getMessage());
            self::assertStringContainsString($this->file, $e->getMessage());
        }
    }

    public function testMergesMethodsAndClassesFromMultipleFiles(): void
    {
        $codeA = <<<'PHP'
            <?php

            namespace DemoA;

            class ServiceA
            {
                public function handle(): void
                {
                }
            }
            PHP;
        $codeB = <<<'PHP'
            <?php

            namespace DemoB;

            class ServiceB
            {
                public function run(): void
                {
                }
            }
            PHP;

        [$index] = $this->indexFiles($codeA, $codeB);

        self::assertNotNull($index->get('DemoA\ServiceA::handle'));
        self::assertNotNull($index->get('DemoB\ServiceB::run'));
        self::assertNotNull($index->classInfo('DemoA\ServiceA'));
        self::assertNotNull($index->classInfo('DemoB\ServiceB'));
    }

    public function testMergesSuppressedLinesFromMultipleFiles(): void
    {
        $codeA = <<<'PHP'
            <?php

            namespace DemoA;

            class ServiceA
            {
                public function run(): void
                {
                    // phptramp-ignore
                }
            }
            PHP;
        $codeB = <<<'PHP'
            <?php

            namespace DemoB;

            class ServiceB
            {
                public function run(): void
                {
                    // phptramp-ignore
                }
            }
            PHP;

        [$index, $fileA, $fileB] = $this->indexFiles($codeA, $codeB);
        $suppressions = $index->suppressions();

        self::assertTrue($suppressions->suppressesLine($fileA, $this->lineOf($codeA, '// phptramp-ignore')));
        self::assertTrue($suppressions->suppressesLine($fileB, $this->lineOf($codeB, '// phptramp-ignore')));
    }

    public function testParseErrorAggregatesMessagesFromAllBadFiles(): void
    {
        $fileA = $this->writeFile("<?php class BrokenA {");
        $fileB = $this->writeFile("<?php class BrokenB {");

        try {
            (new Indexer())->index([$fileA, $fileB]);
            self::fail('expected ParseException');
        } catch (ParseException $e) {
            self::assertStringContainsString($fileA, $e->getMessage());
            self::assertStringContainsString($fileB, $e->getMessage());
        }
    }
}
