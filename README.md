# phptramp

> Detect **tramp data** in PHP codebases: parameters that get passed through chains of
> methods that never use them — "this parameter was passed through 4 classes / 5 methods
> before being used."

**Status: v0.1.0** — the first release. phptramp detects tramp data across
file boundaries: `phptramp --folder src` stitches forwarding chains across files
and prints findings in any of seven formats, exiting `0`/`1`/`2`. A
`phptramp.json` config file, `#[TrampIgnore]`/`// phptramp-ignore` suppression,
and a `--warn-limit` warning tier are all wired up. Diff-aware mode
(`--changed-only`/`--git-base`/`--diff`) and baselining
(`--generate-baseline`/`--baseline`/`--fail-on-stale`) are shipped too — see
below. A per-file index cache (default-on, `--no-cache` to disable) makes warm
re-runs fast enough for per-save IDE use. The classifier and whole-project
index remain inspectable via `--dump-index`.

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

### Construction and delegation aren't tramp data

A subclass constructor that forwards a parameter on to `parent::__construct()` — or
any other `parent::` call — is still the same object handling its own value, not a
value crossing a collaborator boundary. That node scores neither a hop nor a class and
is marked `(parent)` in the chain. `self::`/`static::` calls are unaffected — same
class, ordinary hop.

```text
FINDING  $config: 1 pass-through hop across 2 classes
  origin    Demo\SpecificHandler::__construct($config)  src/Demo.php:19→21  (parent)
  hop 2     Demo\BaseHandler::__construct($config)      src/Demo.php:11→13
  terminal  Demo\Mailer::send($config)                  src/Demo.php:29  (stored)
```

The chain shows three nodes but scores only one hop across two classes — the
`(parent)` marker on the origin is why: `SpecificHandler::__construct` delegates to
its base rather than crossing to a collaborator, so it doesn't count.

Teams that additionally don't want chains ending in a constructor or value object can
set `--exclude-terminal stored` (see [CLI](#cli) and [Configuration](#configuration)).

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

This repository gates itself with exactly such a script: `composer tramp` runs
`bin/phptramp` against the paths and thresholds in its own `phptramp.dist.json`
(`paths: src`, `limit: 6`, `warn-limit: 4`), and CI fails the build on any finding at or
over the limit. That is the same `composer tramp` invocation a consuming project gets from
the snippet above — here the paths come from config, so no `--folder` is needed; a consumer
can configure `paths` the same way or pass `--folder src` inline. (`--no-config` bypasses
the config file entirely when you want to drive everything from flags.)

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
  --exclude-terminal <kind> Do not report chains ending in <kind> (repeatable; used|stored|&-terminated|unused-end|external|truncated)
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

phptramp reads `phptramp.json` (falling back to `phptramp.dist.json`) from the current
working directory. CLI flags always take precedence over the config file. An unknown
key, or a value of the wrong type, is a config error rather than a silently ignored typo.

```json
{
    "paths": ["src"],
    "exclude": ["src/Legacy/*.php"],
    "limit": 3,
    "warnLimit": 2,
    "minClasses": 0,
    "format": "json"
}
```

| Key | Type | Same as |
|---|---|---|
| `paths` | list of strings | entries ending in `.php` become `--file`s, everything else a `--folder` |
| `exclude` | list of strings | `fnmatch` globs matched against each folder-discovered file's path, relative to the config file's directory; explicit files (`paths` entries ending in `.php`) are never excluded |
| `limit` | integer | `--limit` |
| `warnLimit` | integer | `--warn-limit` |
| `minClasses` | integer | `--min-classes` |
| `excludeTerminals` | list of strings | `--exclude-terminal`; **unions** with the flag rather than being replaced by it — `{"excludeTerminals":["stored"]}` plus `--exclude-terminal external` on the command line excludes both. That is the opposite of `paths`/`--folder`, where the first CLI path replaces config-seeded paths: the config file is project policy, and a one-off CI run adds to it rather than overriding it. |
| `format` | string | `--format` |
| `baseline` | string | `--baseline` |
| `cache` | string | directory for the per-file index cache (default: `.phptramp.cache/`, resolved relative to the config file's directory) |

## Index cache

phptramp caches each source file's parsed index under `.phptramp.cache/` (a
per-file cache, default-on). The cache is what makes warm re-runs — including
per-save IDE invocations — cheap: the whole-project index (the part call
resolution needs) rebuilds from cache instead of re-parsing every file.

- **Disable** with `--no-cache`, or **move the directory** with the `cache`
  config key (see [Configuration](#configuration)).
- **Identity-validated and version-keyed.** Each entry stores the source
  file's path, mtime, and size, plus the tool and payload-format versions. Any
  mismatch — an edited file, a bumped version, a corrupt or stale entry — is
  a silent cache miss followed by a fresh parse. A stale or corrupt cache
  **never changes findings**.
- **Safe to wipe.** Delete `.phptramp.cache/` any time; the next run simply
  re-parses and repopulates it. Add `/.phptramp.cache/` to your own
  `.gitignore` so the cache is not committed.

### Performance

Recorded 2026-08-11 on the maintainer's machine (Apple Silicon, PHP 8.4.23):

| Run | Wall-clock | Notes |
|---|---|---|
| `--folder vendor` cold | ≈ 19.6s | 4,610 files / 601,867 lines parsed |
| `--folder vendor` warm | ≈ 4.5s | cache hot — indexing ≈ 0; the residual is chain resolution + reporting |
| `--folder src` cold (36 files) | ≈ 0.24s | small project baseline |
| Extrapolation | ≈ 1.6s cold per 50k LOC | linear in parsed lines |

The cache eliminates the parsing portion of a run (≈ 15s saved on the
`--folder vendor` measurement); chain resolution and reporting are unchanged.
On a typical `src/` the warm per-save run is effectively instant after the
first cold run. See [docs/phpstorm.md](docs/phpstorm.md) for the IDE use this
enables.

## Output formats

`--format` selects the renderer; all seven are implemented and each has an exact-string
unit test. `json` is additionally exercised end-to-end by the `tests/fixtures/` harness,
which always runs with `--format json`.

`pretty` is the default when STDOUT is a TTY. In `--color=auto` (the default),
non-TTY invocations (pipes, CI redirection) fall back to `text` automatically.
`--color=never` keeps plain `pretty` in a pipe; `--color=always` keeps colored
`pretty` (the escape hatch for piping into `less -R`). Use `--color=never` to
suppress color on a TTY, or set the `NO_COLOR` environment variable. `NO_COLOR`
is honored only in `auto`; `always` and `never` are absolute.

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

`--baseline` keeps pre-existing tramp data from gating CI on a codebase you adopt
phptramp against mid-flight. The fingerprint is refactor-stable: a sha1 over the
chain's semantic identity only (origin FQMN, parameter name, terminal token — never
line numbers or intermediate hops), so shortening a chain, moving a file, or shifting
a line does *not* "re-open" a baselined finding. The terminal token is the terminal
FQMN when the chain resolves one, else the terminal kind (`external`/`truncated`);
a chain whose truncation reason changes when resolution improves stays baselined.

The adoption story:

1. **Snapshot.** Run `phptramp --folder src --generate-baseline phptramp-baseline.json`
   once on the current tree. The file is sorted, stable JSON — one entry per finding,
   diff-clean to commit and review.
2. **Commit.** Check `phptramp-baseline.json` into the repo alongside `phptramp.json`.
3. **Gate.** Run `phptramp --folder src --baseline phptramp-baseline.json` in CI.
   Every entry in the file is invisible to the reporters and the exit code —
   identical to not existing. New or re-shaped chains above `--limit` fail the build
   as usual.
4. **Shrink.** Fix a baselined chain and the matching entry becomes *stale*: phptramp
   prints `phptramp: stale baseline entry: <…>` on stderr and (with `--fail-on-stale`)
   exits `1`, nudging you to delete the line and let the gate cover that chain again.

`--fail-on-stale` opts a repo into strict stale hygiene — exit `1` whenever a
baseline entry or a suppression matches nothing. Without it, stale lines are
stderr-only warnings and never change the exit code.

### `--changed-only` interaction

Stale detection is **skipped entirely under `--changed-only`** (full runs only).
The diff filter removes most chains before the baseline is matched, so "stale"
would be almost everything almost always — meaningless noise. Compose
`--changed-only --baseline` on PRs to gate on *new* chains touching the diff while
the baseline keeps the rest quiet; run a full scan (without `--changed-only`) on a
schedule or nightly to surface stale entries and prune the file. See
[docs/ci.md](docs/ci.md) for the legacy-adoption recipe.

## IDE integration

PhpStorm (and any IDE with external-tool support) can run phptramp per file via
`--file`; a documented External Tool + File Watcher recipe is in
[docs/phpstorm.md](docs/phpstorm.md). `--format=checkstyle` and `--format=sarif`
cover CI annotation ecosystems, including GitHub Code Scanning.

## Non-goals

- **No auto-fix.** Fixing tramp data means refactoring (parameter objects, DI rewiring),
  which is not mechanically safe. phptramp reports; you decide.

## License

MIT
