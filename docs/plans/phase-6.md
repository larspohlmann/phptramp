# Phase 6 Implementation Plan — Index cache, IDE recipe, v0.1.0 release

> **For agentic workers:** Self-contained; assumes no conversation context. Read
> `CLAUDE.md` (toolchain, Clean Code house style, git-flow workflow) and the
> "Frozen core semantics" + Phase 6 sections of `docs/plan.md` first — this plan
> expands them into steps and repeats what it relies on. Work task-by-task with
> checkboxes; strict TDD; one task per commit. If your environment provides
> plan-execution skills (superpowers' subagent-driven-development or
> executing-plans), use them; otherwise execute task-by-task in order.

**Goal:** warm re-runs become fast enough for per-save IDE use (per-file index
cache), PhpStorm integration is documented, the docs get their release pass, the
post-v0.1 roadmap is written down — and v0.1.0 ships through the repo's
tag-triggered release workflow onto Packagist.

**Architecture:** `Indexer` splits into a per-file unit (`FileIndexer` →
`FileIndex`) and a merging orchestrator — a pure refactor that makes each file's
index independently cacheable. `src/Cache/FileIndexCache` stores one serialized
`FileIndex` per source file, keyed by content identity (path, mtime, size) plus
tool/format version; any validation failure is a silent miss, never an error.
The cache is on by default at `.phptramp.cache/` with `--no-cache` and a `cache`
config key. No analysis semantics change anywhere in this phase.

**Tech Stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod
dependency). Serialization via PHP `serialize()`/`unserialize()` with an
explicit `allowed_classes` whitelist.

## Global Constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit,
  PHPMD-clean for every touched `src/` file, Clean Code house style, strict TDD,
  one task per commit.
- **GitHub issue for this phase: #11.** Branch `feature/11-cache-and-release`
  off `develop` (already created, carries this plan). Commits `type(#11): …`.
  PR into `develop` with `Closes #11`; diff-scoped Infection gate (minMsi 80)
  applies. The release itself (Task 6) happens AFTER that PR merges, on
  git-flow release branches, per `CLAUDE.md`'s workflow — not on this branch.
- **Cache is transparency-critical:** a stale or corrupt cache must never
  change findings. Every failure path (unreadable file, bad payload, version
  mismatch, class-whitelist rejection) is a miss followed by a fresh parse.
  No exception may escape the cache layer.
- **Measured baseline, recorded 2026-08-11 on the maintainer's machine
  (Apple Silicon, PHP 8.4.23):** cold `--folder vendor` = 4,610 files /
  601,867 lines in **≈ 19.6s**; cold `--folder src` (36 files) ≈ 0.24s.
  Extrapolation: ≈ 1.6s cold per 50k LOC. This is why parallel indexing
  (master-plan sketch 6.2) is **deferred to the roadmap, not built**: the
  master plan's own rule is "performance last, when profiling has real data,"
  and the data says the cache alone meets the stated warm-run target while a
  worker pool would add a `proc_open` protocol + serialization boundary for a
  goal already met. Record this deferral in `docs/plan.md` (Task 5).

## Existing interfaces this phase consumes (Phase 1–5, verbatim)

- `Indexer::index(list<string> $files): MethodIndex` — builds one
  `IndexingVisitor` + traverser (NameResolver, ParentConnectingVisitor,
  visitor), loops files via `indexFile()` collecting error strings, throws
  `ParseException` with all errors joined, then assembles
  `new MethodIndex($this->classifyPending($visitor->pending()), $visitor->classes(), $visitor->suppressions())`.
- `IndexingVisitor` accumulates across files (`setFile()` between traversals);
  exposes `pending(): list<PendingMethod>`, `classes(): array<string, ClassInfo>`,
  `suppressions(): SuppressionIndex`.
- `MethodIndex::__construct(array $methods, array $classes = [], SuppressionIndex $suppressions = new SuppressionIndex([], [], []))`.
- Value objects that end up inside a cached payload — all `final` with
  `readonly` props, safely `serialize()`-able:
  `MethodInfo{ fqmn, file, line, params, class }`,
  `ParamInfo{ name, position, fate, forwards, byRef, variadic, type, storedOnly }`,
  `ParamFate` (pure enum), `ForwardSite{ callee, argKey, line }`,
  `CalleeRef{ kind, name, receiverHint }`, `CalleeKind` (string-backed enum),
  `ClassInfo{ name, kind, parent, interfaces, traits }`, `ClassKind` (pure enum).
- `SuppressionIndex::__construct(list<string> $methods, list<array{string, string}> $params, array<string, list<int>> $lines)`
  plus `keys()` and the static key builders (Phase 5).
- `Options` (readonly value object) + `ArgvParser` (VALUE_FLAGS / BOOL_FLAGS
  consts, `applyValueFlag`/`applyBoolFlag` matches) + `ConfigLoader` with
  strict unknown-key errors — the pattern for adding `--no-cache` / `cache`.
- `Application::VERSION = '0.1.0-dev'` at `src/Console/Application.php:34`.
- Fixture harness folder mode runs from the repo root with `--no-config`;
  `ApplicationTest` uses temp dirs + injected `php://memory` streams.
- Release process (from `CLAUDE.md`, quoted because Task 6 follows it
  literally): git-flow AVH, per-clone config via `git flow init -d` with tag
  prefix `v`; pushing a `vX.Y.Z` tag on a `main` commit runs
  `.github/workflows/release.yml`, which guards CI was green on that SHA,
  publishes the GitHub Release with notes from merged PRs, writes
  `CHANGELOG.md`, and carries the commit back onto `develop`.

## File structure (this phase)

```
src/Index/FileIndex.php          NEW  one file's methods/classes/suppression parts (Task 1)
src/Index/FileIndexer.php        NEW  path -> FileIndex (single-file parse)        (Task 1)
src/Index/Indexer.php            MOD  merge orchestrator; optional cache           (Tasks 1, 3)
src/Index/IndexingVisitor.php    MOD  + suppressionParts() raw accessor            (Task 1)
src/Cache/FileIndexCache.php     NEW  get/put with identity+version validation     (Task 2)
src/Console/ArgvParser.php       MOD  --no-cache                                   (Task 3)
src/Console/Options.php          MOD  + noCache, + cacheDir                        (Task 3)
src/Config/ConfigLoader.php      MOD  `cache` key (string dir)                     (Task 3)
src/Console/Application.php      MOD  cache wiring; later VERSION bumps            (Tasks 3, 6)
.gitignore                       MOD  /.phptramp.cache/                            (Task 3)
docs/phpstorm.md                 NEW  External Tool + File Watcher recipe          (Task 4)
docs/ci.md, README.md            MOD  cross-links, cache section, release pass     (Task 4)
docs/plan.md                     MOD  roadmap + deferral notes + phase status      (Task 5)
tests/Index/FileIndexerTest.php  NEW                                               (Task 1)
tests/Cache/FileIndexCacheTest.php NEW                                             (Task 2)
tests/…                          extended                                          (Tasks 1–3)
```

---

### Task 1: `FileIndexer` + `FileIndex` (pure refactor, no cache yet)

Per-file caching needs per-file indexing units. Today one visitor accumulates
across all files; afterwards each file parses independently and `Indexer`
merges. **No behavior change** — the whole existing suite is the safety net,
and per-file classification is safe because `UsageClassifier` only ever reads
one method's own body.

**Files:**
- Create: `src/Index/FileIndex.php`, `src/Index/FileIndexer.php`
- Modify: `src/Index/Indexer.php`, `src/Index/IndexingVisitor.php`
- Test: `tests/Index/FileIndexerTest.php`; existing `IndexerTest`,
  `UsageClassifierTest`, fixture harness must pass untouched

**Interfaces:**
- Consumes: `IndexingVisitor`, `UsageClassifier`, the value objects listed
  above.
- Produces:

```php
/** Everything the index learns from one source file. Serializable as a unit. */
final class FileIndex
{
    /**
     * @param array<string, MethodInfo> $methods keyed by FQMN
     * @param array<string, ClassInfo> $classes keyed by FQCN
     * @param list<string> $suppressedMethods
     * @param list<array{string, string}> $suppressedParams
     * @param array<string, list<int>> $suppressedLines
     */
    public function __construct(
        public readonly array $methods,
        public readonly array $classes,
        public readonly array $suppressedMethods,
        public readonly array $suppressedParams,
        public readonly array $suppressedLines,
    ) {}
}

final class FileIndexer
{
    /** Parses and classifies ONE file. @throws ParseException on read/parse failure (single file's message) */
    public function index(string $file): FileIndex;
}

// IndexingVisitor addition — the raw parts suppressions() already aggregates:
/** @return array{methods: list<string>, params: list<array{string, string}>, lines: array<string, list<int>>} */
public function suppressionParts(): array;
```

`Indexer::index()` becomes: for each file call `FileIndexer` (collecting
per-file `ParseException` messages, still throwing them joined at the end —
same aggregate message format), then merge in input order: later files win on
duplicate FQMN/FQCN keys (`$merged[$key] = $value` in file order — this is the
current last-wins behavior of the accumulating visitor, preserved), suppression
parts concatenated then built into one `SuppressionIndex`. Input order comes
from `FileLocator` (sorted), so `MethodIndex` insertion order — and therefore
chain-walk determinism — is unchanged.

- [ ] **Step 1: Write the failing test** (`FileIndexerTest`, temp-file helper
  pattern): one file with a namespaced class + method + `#[TrampIgnore]` param
  + `// phptramp-ignore` comment → `FileIndex` carries the method (classified),
  the class, and all three suppression part kinds; unreadable path throws
  `ParseException` naming the file; syntax error throws naming the file.
- [ ] **Step 2:** `vendor/bin/phpunit --filter FileIndexerTest` → FAIL (class
  not found).
- [ ] **Step 3:** implement `FileIndex`, `FileIndexer` (per-file visitor +
  traverser instances), `suppressionParts()`; rewrite `Indexer::index()` as the
  merge loop.
- [ ] **Step 4:** `vendor/bin/phpunit` — the WHOLE suite, not just the new
  test; then `composer check`; then `composer tramp` (self-gate must be
  byte-identical).
- [ ] **Step 5:** commit `refactor(#11): per-file indexing units behind the Indexer`.

### Task 2: `FileIndexCache`

**Files:**
- Create: `src/Cache/FileIndexCache.php`
- Test: `tests/Cache/FileIndexCacheTest.php`

**Interfaces:**
- Consumes: `FileIndex` (Task 1), `Application::VERSION`.
- Produces:

```php
final class FileIndexCache
{
    /** Bump when FileIndex or any nested value object changes shape. */
    private const FORMAT = 1;

    public function __construct(private readonly string $directory) {}

    /** Null on any miss: absent, unreadable, version/format/identity mismatch, corrupt payload. */
    public function get(string $file): ?FileIndex;

    /** Writes best-effort; a failed write (unwritable dir, full disk) is silently ignored. */
    public function put(string $file, FileIndex $index): void;
}
```

Mechanics, pinned:

- Cache entry path: `<directory>/<sha1(realpath of source file)>.cache`.
- Payload: `serialize(['format' => self::FORMAT, 'version' => Application::VERSION, 'path' => $realpath, 'mtime' => $mtime, 'size' => $size, 'index' => $fileIndex])`.
- `get()` validates format, version, path, and the file's *current* mtime+size
  against the stored ones; `unserialize(..., ['allowed_classes' => [FileIndex::class, MethodInfo::class, ParamInfo::class, ParamFate::class, ForwardSite::class, CalleeRef::class, CalleeKind::class, ClassInfo::class, ClassKind::class]])`;
  `false`/wrong-type results are misses. Directory is created lazily on first
  `put()` (`mkdir` recursive, `@`-suppressed — creation failure just means no
  caching).

- [ ] **Step 1: Write the failing test:** put→get round-trips an equal
  `FileIndex` (use `assertEquals`); get on a never-put file → null; touching
  the source file (`touch($file, time() + 10)`) → null; content change
  (size) → null; garbage bytes in the entry → null; entry written by a
  different `FORMAT` (write the array by hand with `'format' => 0`) → null;
  `put()` into a read-only directory does not throw.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green +
  `composer check`.
- [ ] **Step 5:** commit `feat(#11): per-file index cache`.

### Task 3: Wiring — default-on cache, `--no-cache`, `cache` config key

**Files:**
- Modify: `src/Console/ArgvParser.php`, `src/Console/Options.php`,
  `src/Config/ConfigLoader.php`, `src/Index/Indexer.php`,
  `src/Console/Application.php`, `.gitignore`
- Test: extend `ArgvParserTest`, `ConfigLoaderTest`, `ApplicationTest`

**Interfaces:**
- Consumes: `FileIndexCache` (Task 2).
- Produces: `Options` gains `public readonly bool $noCache = false` and
  `public readonly string $cacheDir = '.phptramp.cache'`;
  `Indexer::__construct(private readonly ?FileIndexCache $cache = null)` —
  every existing `new Indexer()` call site (tests included) keeps working,
  uncached.

Behavior, pinned:

- `--no-cache` (BOOL_FLAGS) disables entirely; config key `cache` (string)
  overrides the directory, resolved relative to the config file's directory
  (= cwd), same as `exclude`. CLI-over-config precedence via the existing
  defaults-seeding mechanic.
- `Application` constructs `new Indexer($options->noCache ? null : new FileIndexCache($options->cacheDir))`
  in both `analyze()` and `dumpIndex()`.
- Cache hits skip `FileIndexer` per file; misses parse then `put()`. The
  aggregate-`ParseException` contract is unchanged (a cached file cannot be a
  parse error — it parsed when cached; identity checks force re-parse after
  edits).
- Repo's own `.gitignore` gains `/.phptramp.cache/`; README tells consumers to
  do the same.

- [ ] **Step 1: Write the failing tests:** parser: `--no-cache` sets the flag.
  ConfigLoader: `"cache": "build/tramp-cache"` lands in `cacheDir`;
  `"cache": 5` throws. Application (temp dir, temp cache dir): run twice with
  the same cache dir → second run's stdout byte-identical to the first and the
  cache dir contains one `.cache` entry per source file; edit a source file
  between runs → new finding appears (identity invalidation end-to-end);
  `--no-cache` leaves the cache dir absent.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** full suite +
  `composer check` + `composer tramp`; manual sanity: time
  `bin/phptramp --folder vendor --limit 99 --format summary` cold vs warm and
  record both numbers in the commit body (expect ≈ 19.6s cold → far under 1s
  of indexing warm; report actual).
- [ ] **Step 5:** commit `feat(#11): default-on index cache with --no-cache and cache config key`.

### Task 4: PhpStorm recipe + docs release pass

**Files:**
- Create: `docs/phpstorm.md`
- Modify: `README.md`, `docs/ci.md`

`docs/phpstorm.md` content (write it fully, not as an outline):

- **External Tool** setup, field by field: Program
  `$ProjectFileDir$/vendor/bin/phptramp`, Arguments `--file $FilePath$`,
  Working directory `$ProjectFileDir$` (so `phptramp.json` and the cache are
  found); Output Filters `$FILE_PATH$:$LINE$` so findings are clickable; note
  that the whole-project index still builds (config `paths` supplies it) and
  that the warm cache is what makes per-save invocation viable (cite the
  measured numbers from the Global Constraints).
- **File Watcher** variant for on-save runs (scope: project PHP files; program
  and arguments identical; "Auto-save edited files" off to avoid feedback
  loops).
- **Keyboard shortcut / before-commit** one-liners; `--format checkstyle` +
  SARIF pointers for Qodana/Code-Scanning users; link back to `docs/ci.md`.

README release pass: status paragraph loses phase numbering ("v0.1.0"), cache
section added ("delete `.phptramp.cache/` any time; it is identity-validated
and version-keyed"), gitignore note, performance numbers, PhpStorm link.
`docs/ci.md`: link `docs/phpstorm.md`, verify every recipe still matches the
real flags (they changed in no task of this phase — this is a read-through,
fix only drift found).

- [ ] Steps: write `docs/phpstorm.md` → README + ci.md edits → `composer check`
  (docs-only, still run it) → commit `docs(#11): phpstorm recipe and docs release pass`.

### Task 5: Roadmap + master-plan close-out

**Files:**
- Modify: `docs/plan.md`

- Mark Phase 6 tasks/sections done as they land (this task can trail Task 6's
  release by a commit — see ordering note there). Add to the Phase 6 section:
  parallel indexing (sketch 6.2) **deferred with the measurement** from Global
  Constraints — the honest sentence is "the data said no, not yet", not
  "descoped".
- New final section `## Post-v0.1 roadmap` listing, each with one sentence of
  rationale and its origin: `--follow-all-implementations` (fan-out counts
  already recorded since Phase 2), field/property tramping (constructor-stored
  values re-forwarded via properties — the Phase 0 "future work" note), native
  PhpStorm plugin (inline squiggles; External Tool recipe is the stopgap),
  composer-plugin command package (`composer tramp` without the script
  snippet — Option B from the Phase 0 design review), parallel indexing (the
  deferral above, with numbers), `--fail-on-stale` config key (CLI-only since
  Phase 5, add on demand).

- [ ] Steps: edit → commit `docs(#11): post-v0.1 roadmap and phase 6 status`.

### Task 6: Release v0.1.0

**Prerequisite:** the `feature/11-cache-and-release` PR (Tasks 1–5) is merged
into `develop` and CI is green there. The release follows `CLAUDE.md`'s
workflow **exactly**; commands below are that workflow spelled out. Any
deviation the repo's git-flow config forces (e.g. missing `git flow init`) is
fixed by `git flow init -d` (tag prefix `v`), never improvised around.

- [ ] **Step 1:** preflight — on `develop`: `git pull --ff-only`,
  `gh run list --limit 1` shows the merge commit green; `composer check` and
  `composer tramp` green locally; `composer infection` (full sweep, not diff)
  run once and surviving-mutant count noted in the release PR/notes if any.
- [ ] **Step 2:** `git flow release start 0.1.0`.
- [ ] **Step 3:** bump `Application::VERSION` to `'0.1.0'`; adjust the version
  test expectation; `composer check`; commit
  `chore: version 0.1.0` (release-branch commit, no issue number — the plain
  form per `CLAUDE.md`).
- [ ] **Step 4:** `git flow release finish -m "v0.1.0" 0.1.0` (merges to
  `main`, tags `v0.1.0`, back-merges `develop`).
- [ ] **Step 5:** on `develop`: bump `Application::VERSION` to `'0.2.0-dev'`
  (+ test), `composer check`, commit `chore: open 0.2.0-dev`.
- [ ] **Step 6:** `git push origin develop main v0.1.0` — the tag on the
  `main` commit triggers `release.yml`: it guards CI was green on that SHA,
  publishes the GitHub Release with generated notes, writes `CHANGELOG.md`,
  and carries the changelog commit back onto `develop`. Watch it:
  `gh run watch` / `gh release view v0.1.0`. Then `git pull --ff-only` on
  `develop` to pick up the carried-back changelog commit.
- [ ] **Step 7 — MAINTAINER ACTION (cannot be done by an agent):** submit
  `https://github.com/larspohlmann/phptramp` on packagist.org (requires the
  maintainer's Packagist login) and enable the GitHub hook for auto-updates.
- [ ] **Step 8:** verify from the outside: in a scratch directory,
  `composer require --dev phptramp/phptramp:^0.1` succeeds and
  `vendor/bin/phptramp --version` prints `phptramp 0.1.0`.

---

## Done when

1. Tasks 1–5 merged into `develop` via a PR with `Closes #11`; CI (matrix +
   static + dogfood + mutation) green; `composer tramp` output identical before
   and after the cache refactor.
2. Warm-run indexing measured and recorded; parallel-indexing deferral and the
   roadmap are in `docs/plan.md`.
3. `v0.1.0` exists as a GitHub Release with generated notes; `CHANGELOG.md`
   updated; `develop` carries the back-merge; the package installs from
   Packagist (after the maintainer's submission) and prints `phptramp 0.1.0`.
