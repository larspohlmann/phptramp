<?php

declare(strict_types=1);

namespace PhpTramp\Console;

use PhpTramp\Baseline\Baseline;
use PhpTramp\Baseline\BaselineException;
use PhpTramp\Baseline\BaselineFilter;
use PhpTramp\Chain\ChainBuilder;
use PhpTramp\Chain\Finding;
use PhpTramp\Config\ConfigException;
use PhpTramp\Config\ConfigLoader;
use PhpTramp\Diff\ChangedChainFilter;
use PhpTramp\Diff\DiffException;
use PhpTramp\Diff\DiffParser;
use PhpTramp\Diff\GitDiffRunner;
use PhpTramp\Discovery\FileLocator;
use PhpTramp\Ignore\SuppressionFilter;
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

    /** @var resource */
    private $stdin;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        $this->stdout = $stdout ?? \STDOUT;
        $this->stderr = $stderr ?? \STDERR;
        $this->stdin = $stdin ?? \STDIN;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);

        try {
            $options = (new ArgvParser())->parse($args, $this->loadDefaults($args));
        } catch (ConfigException | InvalidArgsException $e) {
            fwrite($this->stderr, 'phptramp: ' . $e->getMessage() . "\n");

            return 2;
        }

        if ($options->help) {
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

        if ($this->nothingToAnalyze($options)) {
            fwrite($this->stdout, self::helpText());

            return 0;
        }

        return $this->analyze($options);
    }

    /**
     * A run with no folders and no files has nothing to work on. That happens
     * for a bare `phptramp` with no config; when config supplies `paths` the
     * folders are populated, so the same argument-less invocation analyzes.
     */
    private function nothingToAnalyze(Options $options): bool
    {
        return $options->folders === [] && $options->files === [];
    }

    /**
     * Config seeds the parser's defaults, so it must be resolved before parsing
     * — which means --no-config is detected from the raw args here, not from the
     * parsed Options that do not exist yet.
     *
     * @param list<string> $args
     */
    private function loadDefaults(array $args): Options
    {
        if (in_array(ArgvParser::NO_CONFIG_FLAG, $args, true)) {
            return new Options();
        }

        return (new ConfigLoader())->load($this->workingDirectory());
    }

    private function workingDirectory(): string
    {
        return getcwd() ?: '.';
    }

    private function analyze(Options $options): int
    {
        try {
            $thresholds = new Thresholds($options->limit, $options->warnLimit);
            $baselineFilter = new BaselineFilter();
            $baseline = $this->consumeBaseline($options, $baselineFilter);
            $index = $this->buildIndex($options);
            $reporter = (new ReporterFactory($this->workingDirectory()))->create($options);
            $suppression = (new SuppressionFilter($index->suppressions()))->filter(
                $this->changedOnlyFindings($this->findChains($index), $options),
            );
            $findings = $suppression->kept;
        } catch (InvalidArgsException | ParseException | DiffException | BaselineException $e) {
            fwrite($this->stderr, 'phptramp: ' . $e->getMessage() . "\n");

            return 2;
        }

        if ($options->generateBaseline !== null) {
            return $this->generateBaseline($findings, $thresholds, $options->generateBaseline, $options->baseline);
        }

        $staleReporter = new StaleReporter($baseline, $index->suppressions());
        $staleLines = $staleReporter->lines($options->changedOnly, $findings, $suppression->firedKeys);
        foreach ($staleLines as $line) {
            fwrite($this->stderr, $line . "\n");
        }

        $findings = $baselineFilter->exclude($findings, $baseline);

        fwrite($this->stdout, $reporter->render($findings));

        return $staleReporter->exitCode(
            $this->hasError($findings, $thresholds),
            $options->failOnStale,
            $staleLines,
        );
    }

    /**
     * Consume mode loads only when --baseline is set without --generate-baseline
     * (generation wins when both are set and already notes the ignored flag).
     * Fail fast before the expensive index build.
     */
    private function consumeBaseline(Options $options, BaselineFilter $baselineFilter): ?Baseline
    {
        if ($options->baseline === null || $options->generateBaseline !== null) {
            return null;
        }

        return $baselineFilter->load($options->baseline);
    }

    /**
     * Generation is maintenance, not a gate: it reuses the filter chain up to
     * the suppression step, collects the findings a reporter would show
     * (errors and warnings — warnings excluded would resurface on every later
     * run), and writes a baseline document. Exits 0 regardless of findings.
     *
     * @param list<Finding> $findings
     */
    private function generateBaseline(
        array $findings,
        Thresholds $thresholds,
        string $baselinePath,
        ?string $consumeBaseline,
    ): int {
        if ($consumeBaseline !== null) {
            fwrite(
                $this->stderr,
                'phptramp: --baseline ignored while generating a new baseline' . "\n",
            );
        }

        $reportedFindings = $this->reportedFindings($findings, $thresholds);
        $written = @file_put_contents($baselinePath, Baseline::generate($reportedFindings));
        if ($written === false) {
            fwrite($this->stderr, 'phptramp: unable to write baseline to ' . $baselinePath . "\n");

            return 2;
        }

        fwrite(
            $this->stderr,
            'baseline written: ' . $baselinePath
            . ' (' . count($reportedFindings) . ' findings)' . "\n",
        );

        return 0;
    }

    /**
     * The findings a reporter would show: severity is non-null (error or warning).
     *
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function reportedFindings(array $findings, Thresholds $thresholds): array
    {
        $reported = [];
        foreach ($findings as $finding) {
            if ($thresholds->severityOf($finding) !== null) {
                $reported[] = $finding;
            }
        }

        return $reported;
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    private function changedOnlyFindings(array $findings, Options $options): array
    {
        if (! $options->changedOnly) {
            return $findings;
        }

        $changedLines = (new DiffParser())->parse($this->acquireDiffText($options))
            ->resolveAgainst($this->workingDirectory());

        return (new ChangedChainFilter($changedLines))->filter($findings);
    }

    private function acquireDiffText(Options $options): string
    {
        if ($options->diff === null) {
            return (new GitDiffRunner())->run($options->gitBase, $this->workingDirectory());
        }

        if ($options->diff === '-') {
            return $this->readStdin();
        }

        return $this->readDiffFile($options->diff);
    }

    private function readDiffFile(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new DiffException("unable to read diff file: {$path}");
        }

        return $contents;
    }

    private function readStdin(): string
    {
        $contents = stream_get_contents($this->stdin);
        if ($contents === false) {
            throw new DiffException('unable to read diff from stdin');
        }

        return $contents;
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
              --diff <path|->           Read the diff from a file, or stdin with '-' (implies --changed-only)

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

            Status: Phase 4 - diff-aware mode is shipped: --changed-only /
            --git-base / --diff report only chains touching the diff and mark
            which hops are yours, across all six formats
            (text/json/github/checkstyle/sarif/summary). phptramp.json config,
            #[TrampIgnore]/phptramp-ignore suppression, and --warn-limit are
            wired up. See docs/plan.md.

            TXT;
    }
}
