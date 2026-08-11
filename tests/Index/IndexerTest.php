<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

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

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function index(string $code): MethodIndex
    {
        $this->file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($this->file, $code);

        return (new Indexer())->index([$this->file]);
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
}
