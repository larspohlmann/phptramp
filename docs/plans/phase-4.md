# Phase 4 Implementation Plan — Diff-aware mode + self-gating dogfood

> **For agentic workers:** Self-contained; assumes no conversation context. Read
> `CLAUDE.md` (toolchain, Clean Code house style, git-flow workflow) and the
> "Frozen core semantics" + Phase 4 sections of `docs/plan.md` first — this plan
> expands them into steps and repeats what it relies on. Work task-by-task with
> checkboxes; strict TDD; one task per commit.

**Goal:** the flagship feature — `phptramp --changed-only --git-base origin/main`
reports only chains touching the diff and marks *which hops are yours* — plus two
items pulled forward at the maintainer's request: a `composer tramp` script backed
by the repo's own `phptramp.dist.json`, and the CI dogfood step becoming a real
gate at limit 3 / warn-limit 2.

**Architecture:** A new `src/Diff/` layer, fully decoupled from the chain walk:
`DiffParser` turns a unified diff into `ChangedLines`; `GitDiffRunner` produces
that diff from git when none is piped in; `ChangedChainFilter` post-processes the
finished `Finding` list — dropping chains that don't intersect the diff and
rebuilding the kept ones with `Hop->changed` set (immutably; the chain layer never
learns about diffs). Reporters render the mark. Self-gating rides on a new
`--no-config` escape hatch so the repo's own config cannot leak into tests.

**Tech stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency).
`GitDiffRunner` shells out to `git` via `proc_open` — that is an invocation, not a
dependency; it only runs when `--changed-only` needs a diff nobody piped in.

## Global constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit,
  PHPMD-clean for every touched `src/` file, Clean Code house style, strict TDD,
  one task per commit.
- **GitHub issue for this phase: #7.** Branch `feature/7-diff-aware-mode` off
  `develop` (already created, carries this plan). Commits `type(#7): …`. PR into
  `develop` with `Closes #7`; diff-scoped Infection gate (minMsi 80) applies.
- Frozen semantics rule 9 is the contract: any-hop intersection at changed-line
  granularity — a hop matches if its *declaration line* or its *forwarding
  call-site line* falls in the diff; a chain is reported iff ≥ 1 hop matches;
  terminal nodes are not hops and never qualify a chain.
- Diff-aware mode is a **reporting filter**: the whole configured project is
  still indexed and chains still stitch across unchanged files. Never narrow the
  analysis scope to the diff.
- Exit-code contract unchanged (`0`/`1`/`2`); thresholds/severity apply *after*
  the changed-only filter.

## Existing interfaces this phase consumes (Phase 1–3, verbatim)

- `Finding{ string $param, string $origin, ?string $terminal, TerminalKind $terminalKind, int $hops, list<Hop> $chain, int $classes, list<string> $notes, list<string> $trace }`.
- `Hop{ string $fqmn, ?string $class, string $file, int $line, ?int $forwardLine }`
  — `file` realpath-absolute; `forwardLine` null on the terminal node; the chain
  includes the terminal node only for kinds `used|stored|&-terminated|unused-end`.
- `Options` — `changedOnly` and `gitBase` ('origin/main' default) already parse;
  both currently unused downstream. README documents them as "reserved".
- `Thresholds::severityOf(Finding): ?Severity`; `Reporter` interface;
  `ReporterFactory::create(Options): Reporter`; reporters receive **unfiltered**
  findings and apply thresholds themselves.
- Reporter output contracts pinned in `tests/Report/*Test.php` (exact-string) and
  `tests/fixtures/*/expected-findings.json` (json, end-to-end).
- Fixture harness (`tests/FixtureTest.php`): folder mode runs from the **repo
  root** with `--folder <case>/src --format json` + optional
  `phptramp-args.json`; config mode chdirs into the fixture dir when a
  `phptramp.json` is present. **Folder mode's repo-root cwd is why Task 6 needs
  `--no-config` before the repo gets its own config file.**
- `Application::__construct($stdout = null, $stderr = null)` — streams injected
  for tests; `analyze()` builds index → findings → thresholds → reporter.
- Self-run baseline (measured on the Phase 3 tree, `bin/phptramp --folder src`):
  70 one-hop chains, 6 two-hop, **one 4-hop finding** —
  `$caller` through `CallResolver::resolve → resolveFunction → functionTarget →
  functionCandidates → namespaceOf [used]`. Task 6 resolves it.

## File structure (this phase)

```
src/Diff/DiffException.php        NEW                                            (Task 1)
src/Diff/ChangedLines.php         NEW  file -> changed-line set, intersection    (Task 1)
src/Diff/DiffParser.php           NEW  unified diff text -> ChangedLines         (Task 1)
src/Diff/GitDiffRunner.php        NEW  git diff --unified=0 base...HEAD          (Task 2)
src/Chain/Hop.php                 MOD  + readonly bool $changed = false          (Task 3)
src/Diff/ChangedChainFilter.php   NEW  drop non-intersecting, mark kept hops     (Task 3)
src/Console/ArgvParser.php        MOD  --diff <path|->  (implies --changed-only) (Task 4)
src/Console/Options.php           MOD  + readonly ?string $diff = null           (Task 4)
src/Console/Application.php       MOD  diff acquisition + filter wiring; $stdin  (Task 4)
src/Report/TextReporter.php       MOD  *YOURS* mark                              (Task 5)
src/Report/JsonReporter.php       MOD  "changed" key in changed-only runs        (Task 5)
src/Report/GithubReporter.php     MOD  anchor annotation at first changed hop    (Task 5)
src/Console/ArgvParser.php        MOD  --no-config                               (Task 6)
phptramp.dist.json                NEW  the repo's own config                     (Task 6)
composer.json                     MOD  "tramp" script                            (Task 6)
src/Resolve/CallResolver.php      MOD  resolve the 4-hop $caller chain           (Task 6)
.github/workflows/ci.yml          MOD  dogfood becomes composer tramp (real gate)(Task 6)
docs/ci.md                        NEW  CI cookbook                               (Task 7)
tests/…                           mirrors all of the above
tests/fixtures/changed-*          NEW  diff-aware end-to-end cases               (Task 7)
```

---

### Task 1: `DiffParser` + `ChangedLines`

**Files:** create `src/Diff/{DiffException,ChangedLines,DiffParser}.php`;
tests `tests/Diff/DiffParserTest.php`, `tests/Diff/ChangedLinesTest.php`.

**Produces:**

```php
final class DiffException extends \RuntimeException
{
}

final class ChangedLines
{
    /** @param array<string, list<int>> $linesByFile paths exactly as the diff names them */
    public function __construct(private readonly array $linesByFile) {}

    public function isEmpty(): bool;

    public function containsLine(string $file, int $line): bool;

    /**
     * Re-key every path via realpath($baseDirectory . '/' . $path); paths that
     * do not resolve (deleted files) are dropped. Hop->file is realpath-absolute,
     * so intersection must happen against a resolved instance.
     */
    public function resolveAgainst(string $baseDirectory): self;
}

final class DiffParser
{
    /** @throws DiffException when the text is not a unified diff */
    public function parse(string $unifiedDiff): ChangedLines;
}
```

Parsing rules (pin each as a test):

- Only the **new-file side** matters: file from `+++ b/<path>` (strip `a/`/`b/`
  prefixes; tolerate none), lines from hunk headers `@@ -o,n +s,c @@` → lines
  `s … s+c-1`. Omitted count (`+s`) means `c = 1`; `c = 0` (pure deletion hunk)
  contributes nothing.
- `+++ /dev/null` (file deletion) → file skipped entirely.
- Rename/copy/mode/index header lines and `\ No newline at end of file` are
  tolerated noise. CRLF input is accepted (`\r` stripped before matching).
- Text containing no `+++`/`@@` structure at all → `DiffException` — except the
  empty string, which parses to an empty `ChangedLines` (an empty diff is a
  legitimate "nothing changed" answer from git, not garbage).

- [ ] **Step 1:** failing tests from literal diff strings covering every rule
  above, plus `ChangedLinesTest` for `containsLine` and `resolveAgainst`
  (temp files; a diff path that doesn't exist disappears).
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + `composer check`.
- [ ] **Step 5:** commit `feat(#7): unified diff parser and changed-line set`.

### Task 2: `GitDiffRunner`

**Files:** create `src/Diff/GitDiffRunner.php`;
test `tests/Diff/GitDiffRunnerTest.php`.

**Produces:**

```php
final class GitDiffRunner
{
    /**
     * `git diff --unified=0 <base>...HEAD` in $workingDirectory (three-dot:
     * merge-base semantics, matching what CI reviews).
     * @throws DiffException with git's stderr when git fails (no repo, unknown ref)
     */
    public function run(string $base, string $workingDirectory): string;
}
```

`proc_open` with stdout/stderr pipes; non-zero exit → `DiffException` carrying
stderr. No shell string interpolation — pass an argv array (base ref is
user-supplied input).

- [ ] **Step 1:** failing integration tests against a throwaway repo built in a
  temp dir (`git init -b main`, commit, edit on a branch, commit): diff text
  contains the expected `@@` hunk; unknown base ref throws with git's message;
  non-repo directory throws. (git exists on all CI runners and dev machines —
  acceptable test dependency; guard with a `git --version` skip-check.)
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#7): git diff runner`.

### Task 3: `Hop->changed` + `ChangedChainFilter`

**Files:** modify `src/Chain/Hop.php` (additive last constructor arg
`public readonly bool $changed = false` — existing call sites untouched);
create `src/Diff/ChangedChainFilter.php`;
test `tests/Diff/ChangedChainFilterTest.php`.

**Produces:**

```php
final class ChangedChainFilter
{
    public function __construct(private readonly ChangedLines $changedLines) {}

    /**
     * Frozen rule 9. Keeps findings with >= 1 changed hop, rebuilt so each kept
     * finding's hops carry `changed`. Hop matches iff its declaration `line` or
     * its `forwardLine` is in the diff for its file. The terminal node (the
     * chain entry with forwardLine === null and a terminal-kind that keeps it
     * in the chain) never matches and is never marked.
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings): array;
}
```

Immutability: never mutate — construct new `Hop`s/`Finding`s for kept chains.

- [ ] **Step 1:** failing tests with hand-built findings: middle hop's
  forwardLine in diff → kept, exactly that hop marked; declaration line match →
  kept; only the terminal's line in diff → dropped; no intersection → dropped;
  order and every non-hop `Finding` field preserved on rebuild; empty
  `ChangedLines` → everything dropped.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#7): changed-chain filter with hop marking`.

### Task 4: CLI wiring — `--diff`, stdin, `Application`

**Files:** modify `src/Console/ArgvParser.php`, `src/Console/Options.php`,
`src/Console/Application.php`; tests extend `ArgvParserTest`, `ApplicationTest`.

Contract:

- New value flag `--diff <path|->`: read the unified diff from a file, or from
  stdin when the value is `-`, instead of executing git. **`--diff` implies
  `--changed-only`** (specifying a diff source and not wanting the filter is
  contradictory). `Options` gains `public readonly ?string $diff = null`.
- `Application::__construct` gains a third optional stream param
  `$stdin = null` (defaults to `\STDIN`), matching the existing
  stdout/stderr injection pattern — tests feed `php://memory` streams.
- `analyze()` pipeline order: build index → findings → **changed-only filter**
  (when active) → thresholds → reporter. Diff acquisition: `Options->diff`
  null → `GitDiffRunner->run($options->gitBase, getcwd())`; `'-'` →
  `stream_get_contents($stdin)`; path → `file_get_contents` (unreadable →
  error). `DiffException` → message on stderr, exit 2.
- `--changed-only` with an empty diff is a **success**: `No tramp data found`,
  exit 0 (a doc-only PR must not fail).

- [ ] **Step 1:** failing tests — parser: `--diff x.patch` sets `diff` and
  forces `changedOnly`; `--diff` without value throws. Application (temp dir
  with a 3-hop chain file): `--changed-only --diff <file>` where the diff
  touches hop 2's forwarding line → finding reported, exit 1;
  diff touching only an unrelated line → `No tramp data found`, exit 0;
  `--diff -` reads the same diff from the injected stdin stream; unreadable
  diff path → exit 2; malformed diff text → exit 2.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#7): --changed-only pipeline with --diff and stdin input`.

### Task 5: Reporter marking — "this hop is yours"

**Files:** modify `src/Report/{TextReporter,JsonReporter,GithubReporter}.php`;
tests extend the three reporter test files.

Contracts (pin byte-for-byte):

- **text:** changed hops get `  *YOURS*` appended after the location column:

```
FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)   src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)    src/Demo.php:18  *YOURS*
  hop 3     Demo\ServiceB::run($config)        src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)  src/Demo.php:32  (stored)
```

- **json:** every chain entry gains `"changed": true|false` — **only in
  changed-only runs** (outside them the key is absent, keeping every Phase 3
  fixture byte-stable; the field would be meaningless noise there anyway —
  document this conditionality in the README schema note).
- **github:** the `::error`/`::warning` annotation anchors at the **first changed
  hop** (fall back to the origin if — impossible after the filter, but code
  defensively — none is marked); its title gains the suffix
  ` (hop N of the chain, changed by this diff)` when anchored off-origin.
  Notices for the remaining hops as before.
- **checkstyle / sarif:** deliberately unchanged this phase — their consumers
  (CI annotation ingestion, code scanning) key on stable locations; revisit with
  real usage feedback. State this in the commit body.

- [ ] Steps: failing exact-string tests (marked text block; json with and
  without the key; github anchored at hop 2) → red → implement → green + check →
  commit `feat(#7): mark changed hops in text, json and github output`.

### Task 6: `composer tramp`, self-config, dogfood gate

The maintainer's two requests, plus the prerequisite that makes them safe.
Sub-steps are ordered so the suite is green after every commit.

**Files:** modify `src/Console/ArgvParser.php`, `src/Console/Options.php`,
`src/Console/Application.php`, `tests/FixtureTest.php` (folder mode),
`composer.json`, `.github/workflows/ci.yml`, `src/Resolve/CallResolver.php`;
create `phptramp.dist.json`.

- [ ] **Step 1 — `--no-config`:** bool flag; `Options->noConfig`; `Application`
  skips `ConfigLoader` when set. Test-first in `ArgvParserTest` +
  `ApplicationTest` (a config file in cwd is ignored under `--no-config`).
  The fixture harness's **folder mode** and every `ApplicationTest` invocation
  that runs from the repo root add `--no-config` — this is what keeps the root
  config (next step) out of the tests. Config mode stays config-driven on
  purpose. Commit `feat(#7): --no-config escape hatch`.
- [ ] **Step 2 — the repo's own config + script:** create `phptramp.dist.json`
  (`.dist` so a developer can locally override with an uncommitted
  `phptramp.json`):

```json
{
    "paths": ["src"],
    "limit": 3,
    "warnLimit": 2
}
```

  and in `composer.json` scripts: `"tramp": "@php bin/phptramp"` — config
  supplies paths and thresholds; args still pass through
  (`composer tramp -- --format summary`). Thresholds are measured, not guessed:
  the current tree has one 4-hop finding (error at limit 3) and six 2-hop
  chains (warnings at warn-limit 2) — the gate is strict enough to bite and
  quiet enough to stay credible. Run `composer tramp` — it must **fail** with
  exactly the `CallResolver` finding; that red run is this step's "failing
  test". Commit `feat(#7): composer tramp script and self-config` (red gate is
  fine — the gate is not in CI until Step 4).
- [ ] **Step 3 — resolve the finding:** refactor `CallResolver` so `$caller`
  stops tramping through `resolveFunction → functionTarget →
  functionCandidates` (the chain's terminal only wants the caller's namespace —
  derive it once in `resolve()` and pass the namespace, or introduce a narrow
  value object; pick what reads best against the house style). **Prefer the
  refactor; `#[TrampIgnore]` on our own flagship finding is the last resort**
  and needs a written justification in the commit body. `composer tramp` goes
  green (warnings may remain); full `composer check` green.
  Commit `refactor(#7): stop trampling caller context through CallResolver`.
- [ ] **Step 4 — the gate:** replace the report-only CI step with the real one:

```yaml
      # Dogfood: phptramp gates itself via its own config (limit 3, warn-limit 2).
      - name: Dogfood
        run: composer tramp
```

  Update the stale "Phase 6 lowers it" comment. README: document that the repo
  gates itself and that `composer tramp` inside this repo is the same
  invocation consumers get via the documented script snippet — the README's
  consuming-project snippet is now *also* how this repo actually runs. Commit
  `ci(#7): dogfood becomes a real gate via composer tramp`.

### Task 7: Diff fixtures, CI cookbook, close-out

**Files:** create fixture dirs, `docs/ci.md`; modify `README.md`,
`docs/plan.md` (status), help text in `Application` (`--changed-only`,
`--git-base`, `--diff` lose their "reserved" wording).

- **Fixtures** (folder mode + `phptramp-args.json` using `--diff` with a
  committed `changes.diff`; paths in the diff are relative to the repo root
  because folder mode runs from there):
  `changed-hop-reported` (diff touches a middle hop's forwarding line; expected
  json carries `"changed"` flags), `changed-only-filters-rest` (two chains, diff
  touches one — the other must not appear), `changed-terminal-not-enough` (diff
  touches only the terminal's line; expects zero findings).
- **docs/ci.md:** copy-paste recipes — GitHub Actions PR job (`fetch-depth: 0`,
  fetch the base ref, `--changed-only --git-base "origin/$BASE" --format
  github`), GitLab MR equivalent, piping any diff via
  `git diff … | phptramp --changed-only --diff - --format json`, and the
  full-scan + `composer tramp` pattern this repo itself uses. Note baseline
  (Phase 5) as the upcoming piece for legacy adoption.
- **README:** status → Phase 4; flagship section becomes present-tense with the
  `*YOURS*` example; json `"changed"` conditionality documented.
- **docs/plan.md:** check off Phase 4, mark ✅, note that the dogfood gate and
  `composer tramp` shipped here (pulled forward from Phase 6).

- [ ] Steps: fixtures test-first → green + check →
  commit `test(#7): diff-aware end-to-end fixtures` → docs →
  commit `docs(#7): CI cookbook and phase 4 status`.

---

## Done when

1. Every task committed on `feature/7-diff-aware-mode`; `composer check` green at
   every commit; `composer infection:diff` ≥ 80 MSI locally (`git fetch origin
   main` first).
2. `composer tramp` is green and gating in CI; the CallResolver chain is fixed,
   not suppressed (or the suppression is justified in writing).
3. All Phase 4 bullets in `docs/plan.md` checked off; README/help/docs/ci.md
   match actual behavior.
4. PR into `develop` with `Closes #7`; CI (matrix + static + dogfood + mutation)
   green; after merge, verify issue #7 auto-closed.
