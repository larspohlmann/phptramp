<?php

declare(strict_types=1);

namespace PhpTramp\Console;

use PhpTramp\Chain\ChainBuilder;
use PhpTramp\Chain\Finding;
use PhpTramp\Config\ConfigException;
use PhpTramp\Config\ConfigLoader;
use PhpTramp\Discovery\FileLocator;
use PhpTramp\Index\ForwardSite;
use PhpTramp\Index\Indexer;
use PhpTramp\Index\MethodIndex;
use PhpTramp\Index\ParamInfo;
use PhpTramp\Index\ParseException;
use PhpTramp\Report\ReporterFactory;
use PhpTramp\Report\Severity;
use PhpTramp\Report\Thresholds;
use PhpTramp\Resolve\CallResolver;
use PhpTramp\Resolve\ClassHierarchy;

final class Application
{
    public const NAME = 'phptramp';
    public const VERSION = '0.1.0-dev';

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? \STDOUT;
        $this->stderr = $stderr ?? \STDERR;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        try {
            $defaults = (new ConfigLoader())->load($this->workingDirectory());
            $options = (new ArgvParser())->parse($args, $defaults);
        } catch (ConfigException | InvalidArgsException $e) {
            fwrite($this->stderr, 'phptramp: ' . $e->getMessage() . "\n");

            return 2;
        }

        if ($args === [] || $options->help) {
            fwrite($this->stdout, self::helpText());

            return 0;
        }

        if ($options->version) {
            fwrite($this->stdout, self::NAME . ' ' . self::VERSION . "\n");

            return 0;
        }

        if ($options->dumpIndex) {
            return $this->dumpIndex($options);
        }

        return $this->analyze($options);
    }

    private function workingDirectory(): string
    {
        return getcwd() ?: '.';
    }

    private function analyze(Options $options): int
    {
        try {
            $index = $this->buildIndex($options);
            $reporter = (new ReporterFactory($this->workingDirectory()))->create($options);
        } catch (InvalidArgsException | ParseException $e) {
            fwrite($this->stderr, 'phptramp: ' . $e->getMessage() . "\n");

            return 2;
        }

        $findings = $this->findChains($index);
        fwrite($this->stdout, $reporter->render($findings));

        $thresholds = new Thresholds($options->limit, $options->warnLimit);

        return $this->hasError($findings, $thresholds) ? 1 : 0;
    }

    /**
     * @param list<Finding> $findings
     */
    private function hasError(array $findings, Thresholds $thresholds): bool
    {
        foreach ($findings as $finding) {
            if ($thresholds->severityOf($finding) === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    private function buildIndex(Options $options): MethodIndex
    {
        $files = (new FileLocator($this->workingDirectory()))->locate($options);

        return (new Indexer())->index($files);
    }

    /**
     * @return list<Finding>
     */
    private function findChains(MethodIndex $index): array
    {
        $resolver = new CallResolver($index, new ClassHierarchy($index));

        return (new ChainBuilder($resolver))->build($index);
    }

    private function dumpIndex(Options $options): int
    {
        try {
            $files = (new FileLocator($this->workingDirectory()))->locate($options);
            $index = (new Indexer())->index($files);
        } catch (InvalidArgsException | ParseException $e) {
            fwrite($this->stderr, 'phptramp: ' . $e->getMessage() . "\n");

            return 2;
        }

        fwrite($this->stdout, $this->renderIndex($index));

        return 0;
    }

    private function renderIndex(MethodIndex $index): string
    {
        $methods = [];
        foreach ($index->all() as $fqmn => $method) {
            if ($method->params === []) {
                continue;
            }

            $methods[$fqmn] = [
                'file' => $method->file,
                'params' => array_map(
                    fn (ParamInfo $param): array => [
                        'name' => $param->name,
                        'fate' => $param->fate->name,
                        'forwards' => array_map($this->renderForward(...), $param->forwards),
                    ],
                    $method->params,
                ),
            ];
        }

        $json = json_encode(['methods' => $methods], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ($json === false ? '{"methods":{}}' : $json) . "\n";
    }

    private function renderForward(ForwardSite $forward): string
    {
        return $forward->callee->kind->value . ':' . $forward->callee->name . '@' . $forward->argKey;
    }

    private static function helpText(): string
    {
        return <<<'TXT'
            phptramp - detect tramp data in PHP codebases

            Reports parameters that are passed through chains of methods that never
            use them ("this parameter was passed through X classes/methods before
            being used").

            Usage:
              phptramp [options]

            Path selection:
              --folder <dir>            Analyze all .php files under <dir> (repeatable)
              --file <path>             Analyze a single file (project index still built from configured paths)
              --files <a,b,c>           Comma-separated list of files

            Reporting:
              --limit <n>               Fail on chains with >= n pass-through hops (default: 3)
              --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops
              --format <fmt>            text|json|github|checkstyle|sarif|summary (default: text)
              --explain                 Show why chains ended (call resolution trace)

            Diff-aware mode:
              --changed-only            Only report chains touching changed lines
              --git-base <ref>          Diff base for --changed-only (default: origin/main)

            Baseline:
              --baseline <file>         Ignore findings recorded in the baseline file
              --generate-baseline <file>  Write current findings to a baseline file

            Debugging:
              --dump-index              Print the classified method index as JSON and exit

            Misc:
              -h, --help                Show this help
              -V, --version             Show version

            Exit codes:
              0  no findings at or over --limit
              1  at least one finding at or over --limit
              2  tool error (bad arguments, parse failure, ...)

            Status: Phase 2 - cross-file chain reporting works in the text format;
            other formats land in Phase 3. See docs/plan.md.

            TXT;
    }
}
