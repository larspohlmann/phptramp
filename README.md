# phptramp

> Detect **tramp data** in PHP codebases: parameters that get passed through chains of
> methods that never use them — "this parameter was passed through 4 classes / 5 methods
> before being used."

[![CI](https://github.com/larspohlmann/phptramp/actions/workflows/ci.yml/badge.svg)](https://github.com/larspohlmann/phptramp/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/larspohlmann/phptramp)](https://packagist.org/packages/larspohlmann/phptramp)
[![PHP ≥ 8.2](https://img.shields.io/badge/php-%E2%89%A5%208.2-777bb3)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

## What it does

```text
$ vendor/bin/phptramp --folder src --limit 3

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)     src/Http/Controller.php:21→23
  hop 2     App\Service\ServiceA::process($config)   src/Service/ServiceA.php:14→16
  hop 3     App\Service\ServiceB::run($config)       src/Service/ServiceB.php:9→11
  terminal  App\Mail\Mailer::__construct($config)    src/Mail/Mailer.php:12  (stored)
```

The origin is hop 1, so a 3-hop chain runs origin → hop 2 → hop 3 → terminal; the
summary counts the origin. Add `--explain` to see, per edge, how each call was
resolved.

A **hop** is a method that receives a parameter and *purely forwards* it — never reads a
property, never calls a method on it, never uses it in an expression. Long hop chains are
the "tramp data" smell: every method in the middle is coupled to a value it has no
business knowing about. The fix is usually a parameter object, a context object, or
dependency injection at the terminal — this tool tells you *where*.

Construction and delegation don't count: a constructor forwarding to
`parent::__construct()` (or any `parent::` call) is the same object
handling its own value, so that node scores neither a hop nor a class and
is marked `(parent)` in the chain.

## Diff-aware CI mode

The flagship feature is diff-aware CI mode: run it on a pull request and it reports
**"your edit made this chain longer"**, marking exactly which hops are yours.
`--changed-only` restricts findings to chains that intersect a diff — a hop matches iff
its declaration line or its forwarding call-site line was touched — and each matching
hop's location line grows a `*YOURS*` annotation:

```text
$ vendor/bin/phptramp --folder src --changed-only --git-base origin/main

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)    src/Http/Controller.php:21→23
  hop 2     App\Service\ServiceA::process($config)  src/Service/ServiceA.php:14→16  *YOURS*
  hop 3     App\Service\ServiceB::run($config)      src/Service/ServiceB.php:9→11
  terminal  App\Mail\Mailer::__construct($config)   src/Mail/Mailer.php:12  (stored)

1 finding (limit: 3 hops).
```

The `→N` after a hop's `file:line` is the **forwarding call-site line** — the line
inside that method where the parameter is forwarded on. Terminal hops have no
forwarding call, so they show only `file:line`. When a method forwards the same
parameter to the same callee via multiple call sites, each produces its own
finding distinguished by this `→N` value.

`--git-base <ref>` (default `origin/main`) supplies the diff via
`git diff --unified=0 <ref>...HEAD` (three-dot, merge-base semantics); `--diff <path|->`
reads a unified diff from a file, or from stdin with `-`, instead — either always implies
`--changed-only`. See [docs/ci.md](docs/ci.md) for GitHub Actions and GitLab recipes.

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
composer tramp -- --format json --warn-limit 2
```

This repository gates itself with exactly this script — see its
own [`phptramp.dist.json`](phptramp.dist.json).

## CLI

```text
phptramp [options]

  --folder <dir>            Analyze all .php files under <dir> (repeatable)
  --file <path>             Analyze a single file
  --files <a,b,c>           Comma-separated list of files
  --limit <n>               Fail on chains with >= n pass-through hops (default: 6)
  --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops (default: 4; 0 = off)
  --min-classes <n>         Only report chains traversing >= n distinct classes (default: 0 = off)
  --format <fmt>            text|pretty|json|github|checkstyle|sarif|summary (default: pretty on TTY, text otherwise)
  --color <mode>            always|auto|never (default: auto; honors NO_COLOR in auto mode)
  --explain                 Show why chains ended (call resolution trace)
  --exclude-terminal <kind> Do not report chains ending in <kind> (repeatable; used|stored|&-terminated|unused-end|external|truncated -- quote '&-terminated', or the shell backgrounds the command)
  --changed-only            Only report chains touching changed lines
  --git-base <ref>          Diff base for --changed-only (default: origin/main)
  --diff <path|->           Read the diff from a file, or stdin with '-' (implies --changed-only)
  --baseline <file>         Ignore findings recorded in the baseline file
  --generate-baseline <f>   Write current findings to a baseline file
  --fail-on-stale           Exit 1 when stale baseline entries or stale suppressions are found
  --no-cache                Disable the per-file index cache (re-parse every file)
```

Exit codes: `0` no finding at error severity, `1` at least one finding at/over
`--limit`, `2` tool error — so it drops straight into any CI pipeline.

## Configuration

phptramp reads `phptramp.json` (falling back to `phptramp.dist.json`) from
the current working directory; CLI flags always win. All keys, the index
cache, and performance numbers are documented in
[docs/configuration.md](docs/configuration.md).

```json
{
    "paths": ["src"],
    "limit": 3,
    "warnLimit": 2
}
```

## Output formats

`--format` selects the renderer; all seven are implemented and each has an exact-string
unit test.

`pretty` is the default on a TTY; pipes and CI fall back to `text` automatically.
`--color=always|auto|never` overrides (`NO_COLOR` is honored in `auto` mode).

| Format | One-line example |
|---|---|
| `pretty` | `src/Demo.php` (bold-blue file header) / `FINDING  $config: 3 pass-through hops across 4 classes` (colored, grouped by file) |
| `text` | `FINDING  $config: 3 pass-through hops across 4 classes` |
| `json` | `{"limit":3,"warnLimit":null,"findings":[{"param":"config","severity":"error","hops":3,...}]}` |
| `github` | `::error file=src/Demo.php,line=12,title=phptramp%3A%3A$config%3A 3 pass-through hops across 4 classes (terminal%3A Demo\Mailer%3A%3A__construct [stored])` |
| `checkstyle` | `<error line="12" severity="error" message="$config: 3 pass-through hops across 4 classes (terminal: Demo\Mailer::__construct [stored])" source="phptramp.trampData"/>` |
| `sarif` | `{"ruleId":"phptramp.trampData","level":"error","message":{"text":"$config: 3 pass-through hops across 4 classes (terminal: Demo\\Mailer::__construct [stored])"}}` |
| `summary` | `12 chains total; 5 at or over the limit (limit: 3 hops).` |

In a `--changed-only` run, each `json` chain entry additionally carries a `"changed":
true|false` field marking whether that hop intersects the diff; a normal full run omits
the field entirely rather than sending it as always-`false` noise.

## Suppression

Mark a specific false positive as intentional instead of raising `--limit` project-wide:

- **`#[TrampIgnore]`** on a class, method, function, or parameter. Matching is by the
  attribute name's *short name*, so an analyzed codebase never has to `require` or
  autoload phptramp's own `PhpTramp\Ignore\TrampIgnore` class to use it.
- **`// phptramp-ignore`** as a real PHP comment (a marker inside a string literal does
  not count) on a hop's declaration line, the line directly above it, or a forwarding
  call-site line.

Either drops the *entire chain* passing through that hop, not just the one parameter
reaching the flagged declaration.

## `--warn-limit`

`--warn-limit <n>` adds a second, lower threshold below `--limit`. Chains whose hop
count falls in `[warn-limit, limit)` render at `severity: "warning"` (`WARNING` in text,
`::warning` in the GitHub format, `"level": "warning"` in SARIF, …) but never fail the
run — only chains at or over `--limit` set exit code `1`. Useful for tightening a hop
budget gradually: warn today, promote to a hard failure once the codebase is clean.

`0` disables the warn tier entirely (no warnings emitted); `--limit 0` likewise disables
the fail tier, leaving only warnings. `--min-classes <n>` is a complementary filter:
it suppresses any chain traversing fewer than `n` distinct classes regardless of
severity, so you can scope a noisy warn tier to genuinely wide chains.

## Baseline

Adopting phptramp on a codebase with existing tramp data? `--baseline`
snapshots today's findings so CI only gates *new* chains, with
refactor-stable fingerprints that survive renames and moved lines — and
`--fail-on-stale` nudges you to prune entries as you fix them. The full
workflow is in [docs/baseline.md](docs/baseline.md).

## IDE integration

PhpStorm (and any IDE with external-tool support) can run phptramp per file via
`--file`; a documented External Tool + File Watcher recipe is in
[docs/phpstorm.md](docs/phpstorm.md). `--format=checkstyle` and `--format=sarif`
cover CI annotation ecosystems, including GitHub Code Scanning.

## Non-goals

- **No auto-fix.** Fixing tramp data means refactoring (parameter objects, DI rewiring),
  which is not mechanically safe. phptramp reports; you decide.
- **No second production dependency.** `nikic/php-parser` is the only one, deliberately.

## Contributing

Contributions are welcome — see the
[contributing guide](.github/CONTRIBUTING.md) for the workflow and the CI
gates, and the [code of conduct](CODE_OF_CONDUCT.md). Bugs are best reported
with the [bug report form](https://github.com/larspohlmann/phptramp/issues/new?template=bug_report.yml).

## License

[MIT](LICENSE)
