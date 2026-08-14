# PhpStorm recipe

phptramp runs per file from any IDE with external-tool support; PhpStorm is the
common case. Two variants are documented below — a manual **External Tool** for
"analyze this file now" and a **File Watcher** for "analyze on save" — plus
one-liners for keyboard-shortcut and before-commit use. The cache is what
makes per-save viable: the whole-project index (the part call resolution needs)
rebuilds from cache, so an on-save run pays only the per-file parse plus the
unchanged chain-resolution and reporting pipeline. See the measured numbers at
the bottom of this file.

phptramp always indexes the whole configured project; `--file` is a
*reporting* filter, not an analysis shortcut (chains cross files). The
`paths`/`--folder` configured in `phptramp.json` (or passed on the CLI) supply
the project index, and `--file $FilePath$` restricts the reported findings to
chains touching that one file.

## External Tool

**Settings → Tools → External Tools → +**

| Field | Value |
|---|---|
| Name | `phptramp` |
| Program | `$ProjectFileDir$/vendor/bin/phptramp` |
| Arguments | `--file $FilePath$` |
| Working directory | `$ProjectFileDir$` |
| Output filters | `$FILE_PATH$:$LINE$` |

Run it via **Code → Run External Tool → phptramp** (or a shortcut — see below).
Findings print to the tool console; the `$FILE_PATH$:$LINE$` output filter
turns every `path:line` occurrence into a clickable link back into the editor.

The working directory is `$ProjectFileDir$` so `phptramp.json` (or
`phptramp.dist.json`) and the `.phptramp.cache/` directory are picked up from the
project root. If your config lives elsewhere, set the working directory
accordingly — phptramp resolves config and the cache relative to the current
working directory.

The first run on a project is cold: every file parses once and the cache is
written. Subsequent runs — External Tool, File Watcher, CLI, CI — read the
warm cache and skip parsing, so the per-save invocation cost drops to the
per-file parse of the one edited file plus chain resolution. On a typical
`src/` the warm per-save run is effectively instant; see the numbers below.

## File Watcher (on-save)

**Settings → Tools → File Watchers → +** (the built-in "phptramp" template is
not required — add a custom watcher with these fields):

| Field | Value |
|---|---|
| Name | `phptramp` |
| Scope | `Project PHP Files` (or a custom scope) |
| Program | `$ProjectFileDir$/vendor/bin/phptramp` |
| Arguments | `--file $FilePath$` |
| Working directory | `$ProjectFileDir$` |
| Output filters | `$FILE_PATH$:$LINE$` |

Set **Scope** to **Project PHP Files** (**Settings → Scopes → Project PHP
Files**, or a custom scope) so the watcher fires only on the project's PHP
sources. Program and arguments are identical to the External Tool.

Leave **"Auto-save edited files to trigger the watcher"** **OFF**. With it on,
the watcher fires phptramp, phptramp may report and the editor re-saves, which
re-triggers the watcher — a feedback loop. OFF means the watcher runs once per
explicit save.

## Keyboard shortcut / before-commit

Bind the External Tool to a keymap entry (**Settings → Keymap → External Tools
→ phptramp**) for an "analyze this file" shortcut. For a pre-commit pass, run
it against staged files from a terminal:

```bash
git diff --cached --name-only --diff-filter=d -- '*.php' \
  | xargs -r -I{} vendor/bin/phptramp --file {}
```

Or, if you keep it simple, a single `--folder src` run in a pre-commit hook is
the same full-scan gate this repo uses on itself — see [docs/ci.md](ci.md) for
the GitHub Actions and GitLab equivalents.

## Qodana / Code Scanning (checkstyle & SARIF)

phptramp's `--format checkstyle` and `--format sarif` renderers feed the
standard CI annotation ecosystems:

- **`--format checkstyle`** — for Qodana/JetBrains and any tool that consumes
  Checkstyle XML. Pipe the output to a file the IDE's inspection viewer reads,
  or surface it in the PhpStorm **Problems** tool window via a Qodana
  configuration that invokes phptramp.
- **`--format sarif`** — for GitHub Code Scanning and any SARIF consumer.
  Upload the SARIF file with the `github/codeql-action/upload-sarif` action;
  see [docs/ci.md](ci.md) for the annotation-recipe patterns (`--format github`
  is the lighter-weight in-PR alternative).

## Measured performance

Recorded 2026-08-11 on the maintainer's machine (Apple Silicon, PHP 8.4.23):

| Run | Wall-clock | Notes |
|---|---|---|
| `--folder vendor` cold | ≈ 19.6s | 4,610 files / 601,867 lines parsed |
| `--folder vendor` warm | ≈ 4.5s | cache hot — indexing ≈ 0 (all cache hits); the residual is chain resolution + reporting |
| `--folder src` cold (36 files) | ≈ 0.24s | small project baseline |
| Extrapolation | ≈ 1.6s cold per 50k LOC | linear in parsed lines |

The cache eliminates the parsing portion of a run. On `--folder vendor` that
is ≈ 15s saved (≈ 19.6s cold → ≈ 4.5s warm). Chain resolution and reporting
are unchanged by the cache — they run on the (now cache-built) index the same
way cold or warm.

What this means for IDE use: a `--file` invocation still builds the whole
project index (call resolution needs it), but warm it does so from cache. The
per-save cost is therefore the per-file parse of the one edited file plus the
unchanged resolution/reporting pipeline — on a typical `src/` that is
effectively instant after the first cold run. The cache is what makes per-save
viable; it does **not** speed up the resolution/reporting half of a full scan.
