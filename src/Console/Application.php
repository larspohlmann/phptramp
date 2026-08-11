<?php

declare(strict_types=1);

namespace PhpTramp\Console;

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

        if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
            fwrite($this->stdout, self::helpText());

            return 0;
        }

        if (in_array('--version', $args, true) || in_array('-V', $args, true)) {
            fwrite($this->stdout, self::NAME . ' ' . self::VERSION . "\n");

            return 0;
        }

        fwrite($this->stderr, "phptramp: analysis is not implemented yet (Phase 0 scaffold). See docs/plan.md.\n");

        return 2;
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
              --format <fmt>            text|json|github|checkstyle|sarif (default: text)
              --explain                 Show why chains ended (call resolution trace)

            Diff-aware mode:
              --changed-only            Only report chains touching changed lines
              --git-base <ref>          Diff base for --changed-only (default: origin/main)

            Baseline:
              --baseline <file>         Ignore findings recorded in the baseline file
              --generate-baseline <file>  Write current findings to a baseline file

            Misc:
              -h, --help                Show this help
              -V, --version             Show version

            Exit codes:
              0  no findings at or over --limit
              1  at least one finding at or over --limit
              2  tool error (bad arguments, parse failure, ...)

            Status: Phase 0 scaffold - analysis is not implemented yet. See docs/plan.md.

            TXT;
    }
}
