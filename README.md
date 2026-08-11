# phptramp

> Detect **tramp data** in PHP codebases: parameters that get passed through chains of
> methods that never use them — "this parameter was passed through 4 classes / 5 methods
> before being used."

**Status: Phase 0 (scaffold).** The analysis engine is not implemented yet.
See [docs/plan.md](docs/plan.md) for the full implementation plan and current phase.

## What it will do

```text
$ vendor/bin/phptramp --folder src --limit 3

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)        src/Http/Controller.php:21
  hop 1     App\Service\ServiceA::process($config)      src/Service/ServiceA.php:14
  hop 2     App\Service\ServiceB::run($config)          src/Service/ServiceB.php:9
  terminal  App\Mail\Mailer::__construct($config)       src/Mail/Mailer.php:12   (stored)
```

A **hop** is a method that receives a parameter and *purely forwards* it — never reads a
property, never calls a method on it, never uses it in an expression. Long hop chains are
the "tramp data" smell: every method in the middle is coupled to a value it has no
business knowing about. The fix is usually a parameter object, a context object, or
dependency injection at the terminal — this tool tells you *where*.

The flagship feature (Phase 4) is diff-aware CI mode: run it on a pull request and it
reports **"your edit made this chain longer"**, marking exactly which hops are yours.

## Installation

```bash
composer require --dev larspohlmann/phptramp
```

### `composer tramp`

Add a script to your project's `composer.json` to invoke it the short way:

```json
{
    "scripts": {
        "tramp": "phptramp --folder src"
    }
}
```

```bash
composer tramp
composer tramp -- --changed-only --git-base origin/main
```

## Planned CLI

```text
phptramp [options]

  --folder <dir>            Analyze all .php files under <dir> (repeatable)
  --file <path>             Analyze a single file
  --files <a,b,c>           Comma-separated list of files
  --limit <n>               Fail on chains with >= n pass-through hops (default: 3)
  --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops
  --format <fmt>            text|json|github|checkstyle|sarif (default: text)
  --explain                 Show why chains ended (call resolution trace)
  --changed-only            Only report chains touching changed lines
  --git-base <ref>          Diff base for --changed-only (default: origin/main)
  --baseline <file>         Ignore findings recorded in the baseline
  --generate-baseline <f>   Write current findings to a baseline file
```

Exit codes: `0` no findings, `1` findings at/over `--limit`, `2` tool error —
so it drops straight into any CI pipeline. Configuration lives in `phptramp.json`
(or `phptramp.dist.json`); CLI flags override config.

## IDE integration

PhpStorm (and any IDE with external-tool support) can run phptramp per file via
`--file`; a documented External Tool / File Watcher recipe ships in Phase 6.
`--format=checkstyle` and `--format=sarif` cover CI annotation ecosystems,
including GitHub Code Scanning.

## Non-goals

- **No auto-fix.** Fixing tramp data means refactoring (parameter objects, DI rewiring),
  which is not mechanically safe. phptramp reports; you decide.

## License

MIT
