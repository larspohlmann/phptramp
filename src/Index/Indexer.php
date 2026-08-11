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
 * Parses the located files into a whole-project {@see MethodIndex}. Parameter
 * usage classification is delegated to {@see UsageClassifier}.
 */
final class Indexer
{
    /**
     * @param list<string> $files
     *
     * @throws ParseException if any file cannot be read or parsed
     */
    public function index(array $files): MethodIndex
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $visitor = new IndexingVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor($visitor);

        $errors = [];
        foreach ($files as $file) {
            $error = $this->indexFile($parser, $traverser, $visitor, $file);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw new ParseException("parse errors:\n" . implode("\n", $errors));
        }

        return new MethodIndex($visitor->methods(), $visitor->classes());
    }

    private function indexFile(
        Parser $parser,
        NodeTraverser $traverser,
        IndexingVisitor $visitor,
        string $file
    ): ?string {
        $code = @file_get_contents($file);
        if ($code === false) {
            return "{$file}: could not read file";
        }

        try {
            $ast = $parser->parse($code);
        } catch (Error $e) {
            return "{$file}: {$e->getMessage()}";
        }

        if ($ast === null) {
            return "{$file}: could not parse file";
        }

        $visitor->setFile($file);
        $traverser->traverse($ast);

        return null;
    }
}
