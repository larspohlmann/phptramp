# phptramp

> Detect **tramp data** in PHP codebases: parameters that get passed through chains of
> methods that never use them — "this parameter was passed through 4 classes / 5 methods
> before being used."

**Status: Phase 3.** Cross-file chain reporting works: `phptramp --folder src`
stitches forwarding chains across files and prints findings in any of six formats,
exiting `0`/`1`/`2`. A `phptramp.json` config file, `#[TrampIgnore]`/
`// phptramp-ignore` suppression, and a `--warn-limit` warning tier are all wired
up. The classifier and whole-project index remain inspectable via `--dump-index`.
See [docs/plan.md](docs/plan.md) for the full implementation plan and current phase.

## What it does

```text
$ vendor/bin/phptramp --folder src --limit 3

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)     src/Http/Controller.php:21
  hop 2     App\Service\ServiceA::process($config)   src/Service/ServiceA.php:14
  hop 3     App\Service\ServiceB::run($config)       src/Service/ServiceB.php:9
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
composer tramp -- --format json --warn-limit 2
```

phptramp itself does not ship a `composer.json` script of this name — `vendor/bin/phptramp`
(or `bin/phptramp` inside this repo) is the invocation; the snippet above is a convenience
you add to a *consuming* project.

## CLI

```text
phptramp [options]

  --folder <dir>            Analyze all .php files under <dir> (repeatable)
  --file <path>             Analyze a single file
  --files <a,b,c>           Comma-separated list of files
  --limit <n>               Fail on chains with >= n pass-through hops (default: 3)
  --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops
  --format <fmt>            text|json|github|checkstyle|sarif|summary (default: text)
  --explain                 Show why chains ended (call resolution trace)
  --changed-only            Reserved for diff-aware mode (Phase 4) — accepted, not yet wired up
  --git-base <ref>          Reserved for diff-aware mode (Phase 4) — accepted, not yet wired up
  --baseline <file>         Reserved for baselining (Phase 5) — accepted, not yet wired up
  --generate-baseline <f>   Reserved for baselining (Phase 5) — accepted, not yet wired up
```

Exit codes: `0` no finding at error severity, `1` at least one finding at/over
`--limit`, `2` tool error — so it drops straight into any CI pipeline.

## Configuration

phptramp reads `phptramp.json` (falling back to `phptramp.dist.json`) from the current
working directory. CLI flags always take precedence over the config file. An unknown
key, or a value of the wrong type, is a config error rather than a silently ignored typo.

```json
{
    "paths": ["src"],
    "exclude": ["src/Legacy/*.php"],
    "limit": 3,
    "warnLimit": 2,
    "format": "json"
}
```

| Key | Type | Same as |
|---|---|---|
| `paths` | list of strings | entries ending in `.php` become `--file`s, everything else a `--folder` |
| `exclude` | list of strings | `fnmatch` globs matched against each folder-discovered file's path, relative to the config file's directory; explicit files (`paths` entries ending in `.php`) are never excluded |
| `limit` | integer | `--limit` |
| `warnLimit` | integer | `--warn-limit` |
| `format` | string | `--format` |
| `baseline` | string | `--baseline` (reserved for Phase 5) |

## Output formats

`--format` selects the renderer; all six are implemented and each has an exact-string
unit test. `json` is additionally exercised end-to-end by the `tests/fixtures/` harness,
which always runs with `--format json`.

| Format | One-line example |
|---|---|
| `text` | `FINDING  $config: 3 pass-through hops across 4 classes` |
| `json` | `{"limit":3,"warnLimit":null,"findings":[{"param":"config","severity":"error","hops":3,...}]}` |
| `github` | `::error file=src/Demo.php,line=12,title=phptramp%3A%3A$config%3A 3 pass-through hops across 4 classes (terminal%3A Demo\Mailer%3A%3A__construct [stored])` |
| `checkstyle` | `<error line="12" severity="error" message="$config: 3 pass-through hops across 4 classes (terminal: Demo\Mailer::__construct [stored])" source="phptramp.trampData"/>` |
| `sarif` | `{"ruleId":"phptramp.trampData","level":"error","message":{"text":"$config: 3 pass-through hops across 4 classes (terminal: Demo\\Mailer::__construct [stored])"}}` |
| `summary` | `12 chains total; 5 at or over the limit (limit: 3 hops).` |

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
