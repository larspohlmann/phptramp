<?php

declare(strict_types=1);

namespace PhpTramp\Index;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses and classifies a single source file into a {@see FileIndex}. Each
 * call uses a fresh {@see IndexingVisitor} (with fresh {@see
 * SuppressionCollector}) and a fresh traverser — that is what makes a file
 * independently cacheable in Task 2.
 *
 * @phpstan-import-type PendingMethod from IndexingVisitor
 */
final class FileIndexer
{
    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @throws ParseException if the file cannot be read or parsed (single file's message)
     */
    public function index(string $file): FileIndex
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            throw new ParseException("{$file}: could not read file");
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (Error $e) {
            throw new ParseException("{$file}: {$e->getMessage()}");
        }

        if ($ast === null) {
            throw new ParseException("{$file}: could not parse file");
        }

        $visitor = new IndexingVisitor();
        $visitor->setFile($file);
        $visitor->recordIgnoreComments($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return new FileIndex(
            $this->classifyPending($visitor->pending()),
            $visitor->classes(),
            $visitor->suppressionParts(),
        );
    }

    /**
     * @param list<PendingMethod> $pending
     *
     * @return array<string, MethodInfo>
     */
    private function classifyPending(array $pending): array
    {
        $classifier = new UsageClassifier();
        $methods = [];
        foreach ($pending as $entry) {
            $node = $entry['node'];
            $params = $classifier->classify($node->getParams(), $node->getStmts());
            $methods[$entry['fqmn']] = new MethodInfo(
                $entry['fqmn'],
                $entry['file'],
                $entry['line'],
                $params,
                $entry['class'],
            );
        }

        return $methods;
    }
}
