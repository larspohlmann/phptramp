# Phase 5 Implementation Plan — Baseline

> **For agentic workers:** Self-contained; assumes no conversation context. Read
> `CLAUDE.md` (toolchain, Clean Code house style, git-flow workflow) and the
> "Frozen core semantics" + Phase 5 sections of `docs/plan.md` first — this plan
> expands them into steps and repeats what it relies on. Work task-by-task with
> checkboxes; strict TDD; one task per commit. If your environment provides
> plan-execution skills (superpowers' subagent-driven-development or
> executing-plans), use them; otherwise execute task-by-task in order.

**Goal:** legacy codebases can adopt phptramp without a cleanup-first big bang:
`--generate-baseline` records today's findings as refactor-stable fingerprints,
`--baseline` (or the config key) silences exactly those, and stale entries — in
the baseline or in `#[TrampIgnore]` suppressions — are reported so grandfathered
debt cannot quietly rot into permanent blindness.

**Architecture:** A new `src/Baseline/` layer: `Fingerprint` (pure function
Finding → hash), `Baseline` (strict file parse, membership, generation). Both
consume/produce plain findings, wired into `Application` as one more
post-processing filter in the established chain
(changed-only → **baseline** → thresholds). Stale-suppression detection requires
knowing which suppressions *fired*, so suppression moves out of `ChainTraversal`
into a fired-tracking `SuppressionFilter` (the `ChangedChainFilter` pattern) —
a pure refactor with no semantic change, pinned by the existing tests.

**Tech Stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency).

## Global Constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit,
  PHPMD-clean for every touched `src/` file, Clean Code house style, strict TDD,
  one task per commit.
- **GitHub issue for this phase: #9.** Branch `feature/9-baseline` off `develop`
  (already created, carries this plan). Commits `type(#9): …`. PR into `develop`
  with `Closes #9`; diff-scoped Infection gate (minMsi 80) applies.
- Frozen semantics rule 10 is the contract, with one **refinement this plan
  makes normative** (record it in `docs/plan.md` when closing the phase): the
  fingerprint's terminal component is the terminal FQMN when the chain has one,
  else the `TerminalKind` backing value (`external` / `truncated`) — *not* a
  per-reason category. Coarser is deliberate: a chain whose truncation reason
  changes when resolution improves stays baselined; re-opening grandfathered
  findings on analyzer upgrades would destroy trust in the baseline.
- Exit-code contract unchanged: `0` no errors, `1` ≥ 1 error-severity finding,
  `2` tool error. Stale entries alone never flip the exit code unless
  `--fail-on-stale` is given.
- Filter order in `Application`, pinned: build findings → changed-only filter →
  suppression filter → baseline filter → thresholds → reporter.

## Existing interfaces this phase consumes (Phase 1–4, verbatim)

- `Finding{ string $param, string $origin, ?string $terminal, TerminalKind $terminalKind, int $hops, list<Hop> $chain, int $classes, list<string> $notes, list<string> $trace }`.
- `Hop{ string $fqmn, ?string $class, string $file, int $line, ?int $forwardLine, bool $changed = false }`.
- `TerminalKind: string { Used='used', Stored='stored', ByRef='&-terminated', Unused='unused-end', External='external', Truncated='truncated' }`.
- `Options` already carries `?string $baseline = null` and
  `?string $generateBaseline = null` (parsed since Phase 1, unused downstream);
  the `baseline` config key is already accepted by `ConfigLoader`.
- `SuppressionIndex{ suppressesMethod(string $fqmn): bool, suppressesParam(string $fqmn, string $param): bool, suppressesLine(string $file, int $line): bool }`,
  reached via `MethodIndex::suppressions()`.
- `ChainTraversal` (internal to `ChainBuilder`) currently applies suppression
  itself: `isSuppressedChain()` drops a chain when any hop matches by method,
  param, declaration line, the line above it, or the forwarding line
  (`src/Chain/ChainTraversal.php:189–233`). Task 3 relocates exactly these
  rules; the line/line-above/forwardLine triple is the semantics to preserve.
- `ChangedChainFilter::filter(list<Finding>): list<Finding>` — the pattern
  Task 3 copies (immutable post-processing filter owned by `Application`).
- `Thresholds::severityOf(Finding): ?Severity` (null = not reported);
  `ReporterFactory::create(Options): Reporter`; reporters filter by thresholds
  themselves.
- `Application::__construct($stdout = null, $stderr = null, $stdin = null)` —
  streams injected in tests via `php://memory`; `analyze()` at
  `src/Console/Application.php:124` is the wiring point.
- Fixture harness: folder mode runs from the repo root with
  `--folder <case>/src --format json --no-config` + optional
  `phptramp-args.json`; paths inside `phptramp-args.json` are relative to the
  repo root. Config mode chdirs into the fixture dir.
- Test helper pattern: write a code string to a temp file, run the real
  pipeline, assert — used by every existing test layer.

## File structure (this phase)

```
src/Baseline/BaselineException.php  NEW                                       (Task 2)
src/Baseline/Fingerprint.php        NEW  Finding -> sha1 + human-context line (Task 1)
src/Baseline/Baseline.php           NEW  parse / membership / generate        (Task 2)
src/Ignore/SuppressionFilter.php    NEW  fired-tracking chain filter          (Task 3)
src/Ignore/SuppressionIndex.php     MOD  + keys() and key builders            (Task 3)
src/Chain/ChainTraversal.php        MOD  suppression logic removed            (Task 3)
src/Console/Application.php         MOD  generate/consume/stale wiring        (Tasks 4–6)
src/Console/ArgvParser.php          MOD  --fail-on-stale                      (Task 6)
src/Console/Options.php             MOD  + readonly bool $failOnStale = false (Task 6)
tests/Baseline/…                    NEW  mirrors src/Baseline
tests/Ignore/SuppressionFilterTest.php NEW                                    (Task 3)
tests/fixtures/baseline-*           NEW  end-to-end cases                     (Task 7)
docs/ci.md, README.md, docs/plan.md MOD  adoption recipe + status             (Task 7)
```

---

### Task 1: `Fingerprint`

**Files:**
- Create: `src/Baseline/Fingerprint.php`
- Test: `tests/Baseline/FingerprintTest.php`

**Interfaces:**
- Produces:

```php
final class Fingerprint
{
    /** sha1(origin . "\0" . param . "\0" . terminalToken) — see terminalToken(). */
    public static function of(Finding $finding): string;

    /**
     * The baseline file line for a finding: "<sha1> <origin> $<param> -> <terminalToken>".
     * Everything after the first space is human context; parsing ignores it.
     */
    public static function line(Finding $finding): string;

    /** Terminal FQMN when the chain has one, else the TerminalKind backing value. */
    private static function terminalToken(Finding $finding): string;
}
```

Stability properties, each a test: hash is *independent* of hop count, of
intermediate hops, of every `Hop` field (line numbers, files, changed flags), of
notes/trace — two findings differing only in those collide by design (shortening
a chain must not "re-open" it). Hash *differs* on origin, on param, and on
terminal. A truncated and an external chain from the same origin+param differ
(`truncated` vs `external` tokens).

- [ ] **Step 1: Write the failing test.** Build findings with the existing
  hand-construction pattern from `tests/Diff/ChangedChainFilterTest.php`
  (a private `finding(...)` helper with defaulted args). Cases: known-input hash
  equality (`sha1("A::a\0p\0B::b")` computed in the test, not copied from the
  implementation); same-except-hops collision; line-number-change collision;
  origin/param/terminal each alter the hash; `terminal null` +
  `TerminalKind::Truncated` uses token `truncated`; `line()` format
  `"<hash> Demo\\A::a $p -> Demo\\B::b"`.
- [ ] **Step 2:** run `vendor/bin/phpunit --filter FingerprintTest` → FAIL
  (class not found).
- [ ] **Step 3:** implement.
- [ ] **Step 4:** green; `composer check` green.
- [ ] **Step 5:** commit `feat(#9): refactor-stable finding fingerprints`.

### Task 2: `Baseline` + `BaselineException`

**Files:**
- Create: `src/Baseline/Baseline.php`, `src/Baseline/BaselineException.php`
  (`extends \RuntimeException`)
- Test: `tests/Baseline/BaselineTest.php`

**Interfaces:**
- Consumes: `Fingerprint::of()`, `Fingerprint::line()` (Task 1).
- Produces:

```php
final class Baseline
{
    /** @param list<string> $fingerprints sha1 hashes only, context already stripped */
    private function __construct(private readonly array $fingerprints) {}

    /**
     * Strict parse of a baseline document. Shape:
     * {"fingerprints": ["<sha1> optional human context", ...]}
     * Unknown top-level key, non-list, or non-string entry -> BaselineException
     * (same philosophy as ConfigLoader: a typo must not silently un-gate CI).
     * The first whitespace-delimited token of each entry is the hash.
     */
    public static function fromJson(string $json): self;

    public function has(Finding $finding): bool;

    /** True for entries whose hash matched no finding passed to matchedBy(). */
    public function staleEntries(array $findings): array;  // list<string> raw entry lines

    /** The full generated document for findings: sorted lines, trailing newline. */
    public static function generate(array $findings): string;  // @param list<Finding>
}
```

`generate()` sorts by the whole rendered line (origin-alphabetical therefore
diff-stable), `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`, trailing newline —
byte-stable across runs on an unchanged tree.

- [ ] **Step 1: Write the failing test.** Round-trip: `generate()` of two
  hand-built findings parses via `fromJson()` and `has()` accepts both, rejects
  a third; sorted order pinned byte-for-byte; hash extraction ignores context
  (`"<hash> anything at all"` still matches); `staleEntries()` returns the
  unmatched raw line; strict-parse failures: `{"fingerprint": []}` (unknown
  key), `{"fingerprints": "x"}`, `{"fingerprints": [1]}`, invalid JSON — each
  throws `BaselineException` with the offending detail in the message.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + `composer check`.
- [ ] **Step 5:** commit `feat(#9): baseline document parse and generation`.

### Task 3: Suppression becomes a fired-tracking filter (pure refactor)

Stale-ignore detection (Task 6) must know which suppression entries ever
matched. `ChainTraversal` decides silently today; move the decision into a
filter that reports.

**Files:**
- Create: `src/Ignore/SuppressionFilter.php`
- Modify: `src/Ignore/SuppressionIndex.php`, `src/Chain/ChainTraversal.php`
  (delete `isSuppressedChain`/`isSuppressedHop` and the drop at line ~189),
  `src/Console/Application.php` (apply the filter after `ChangedChainFilter`)
- Test: `tests/Ignore/SuppressionFilterTest.php`; move the suppression cases
  out of `tests/Chain/ChainBuilderTest.php` (they now assert through the filter)

**Interfaces:**
- Produces:

```php
// SuppressionIndex additions — key builders shared by filter and stale report:
public static function methodKey(string $fqmn): string;          // "method:<fqmn>"
public static function paramKey(string $fqmn, string $param): string; // "param:<fqmn>::$<param>"
public static function lineKey(string $file, int $line): string; // "line:<file>:<line>"
/** Every configured suppression entry as a key. @return list<string> */
public function keys(): array;

final class SuppressionOutcome
{
    /**
     * @param list<Finding> $kept
     * @param list<string> $firedKeys keys (see SuppressionIndex builders) that dropped >= 1 chain
     */
    public function __construct(
        public readonly array $kept,
        public readonly array $firedKeys,
    ) {}
}

final class SuppressionFilter
{
    public function __construct(private readonly SuppressionIndex $suppressions) {}

    /** @param list<Finding> $findings */
    public function filter(array $findings): SuppressionOutcome;
}
```

Semantics to preserve **exactly** (from `ChainTraversal::isSuppressedHop`):
a finding is dropped when any chain hop (terminal node included — it is in the
chain for kinds that keep it, and the current code checks every `PartialChain`
hop; verify against the moved tests, not from memory) matches by: suppressed
method FQMN, suppressed (FQMN, chain param), or a suppressed line that is the
hop's declaration line, the line directly above it, or its `forwardLine`.
`firedKeys` records every key that contributed to at least one drop,
deduplicated, in first-fired order.

- [ ] **Step 1:** port the suppression tests from `ChainBuilderTest` to
  `SuppressionFilterTest` unchanged in *behavior* (same fixture code strings,
  same expectations), plus new assertions on `firedKeys` (attribute drop fires
  its method key; comment drop fires its line key; an entry that matches
  nothing does not appear).
- [ ] **Step 2:** red (filter missing). **Step 3:** implement filter + index
  additions; gut `ChainTraversal`; rewire `Application`. The full suite —
  especially the untouched fixture harness — is the refactor's safety net.
- [ ] **Step 4:** green + `composer check`; run `composer tramp` (the self-gate
  must not change behavior).
- [ ] **Step 5:** commit `refactor(#9): suppression as a fired-tracking filter`.

### Task 4: `--generate-baseline`

**Files:**
- Modify: `src/Console/Application.php`
- Test: extend `tests/Console/ApplicationTest.php`

**Interfaces:**
- Consumes: `Baseline::generate()` (Task 2), the filter chain as wired after
  Task 3, `Thresholds`.

Behavior, pinned:

- `--generate-baseline <file>` runs the normal pipeline (changed-only and
  suppression filters included — a suppressed chain must NOT enter the
  baseline), collects every finding a reporter would show (severity non-null:
  **errors and warnings both** — warnings excluded would resurface as noise on
  every later run), writes the document, prints
  `baseline written: <file> (<N> findings)` to **stderr** (stdout stays clean
  for redirection), and exits `0` regardless of findings — generation is
  maintenance, not a gate.
- Unwritable target path → message on stderr, exit 2.
- `--generate-baseline` and `--baseline` together: generation wins and the
  consume flag is ignored *with a stderr note* (silently applying both would
  bake an old baseline's blind spots into the new one).

- [ ] **Step 1:** failing `ApplicationTest` cases (temp dir with the 3-hop
  chain code string used by existing tests): file written and parseable by
  `Baseline::fromJson()`, contains exactly the expected fingerprint count,
  exit 0, stderr message pinned; with `--warn-limit 2` a 2-hop chain is
  included; unwritable path (a directory as target) → exit 2.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#9): --generate-baseline`.

### Task 5: `--baseline` consumption

**Files:**
- Modify: `src/Console/Application.php`
- Test: extend `tests/Console/ApplicationTest.php`

**Interfaces:**
- Consumes: `Baseline::fromJson()`/`has()` (Task 2); `Options->baseline`
  (CLI flag and config key both already land there).

Behavior, pinned:

- Baseline loads at the top of `analyze()` (fail fast before the expensive
  index build); unreadable file or `BaselineException` → stderr, exit 2.
- Filter position per Global Constraints: after suppression, before
  thresholds. A baselined finding is invisible to every reporter and to the
  exit code — identical to not existing.
- `--baseline` with `--changed-only` composes (both filters apply); this is
  the "legacy codebase, diff-aware PR gate" flow from `docs/ci.md`.

- [ ] **Step 1:** failing tests: generate-then-consume round trip on the same
  temp tree → `No tramp data found`, exit 0; adding a *new* chain to the tree
  afterwards → only the new finding reported, exit 1; corrupt baseline file →
  exit 2; config-key variant (`phptramp.json` with `"baseline": "…"` in a
  chdir'd temp dir, pattern from the existing config tests).
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#9): --baseline filters known findings`.

### Task 6: Stale detection + `--fail-on-stale`

**Files:**
- Modify: `src/Console/ArgvParser.php` (bool flag `--fail-on-stale`),
  `src/Console/Options.php` (`public readonly bool $failOnStale = false`),
  `src/Console/Application.php`
- Test: extend `ArgvParserTest`, `ApplicationTest`

**Interfaces:**
- Consumes: `Baseline::staleEntries()` (Task 2),
  `SuppressionOutcome::$firedKeys` + `SuppressionIndex::keys()` (Task 3).

Behavior, pinned:

- After the pipeline runs: stale baseline entries = `staleEntries()` against
  the findings that reached the baseline filter; stale suppressions =
  `keys()` minus `firedKeys`. Each prints one stderr line:
  `phptramp: stale baseline entry: <raw line>` /
  `phptramp: stale suppression: <key>`.
- **Stale detection is skipped entirely under `--changed-only`** — the diff
  filter removes most chains before matching, so "stale" would be almost
  everything, almost always wrong. Full runs only. (Document this in README —
  it is the kind of surprise that files issues.)
- Exit code: unchanged by stale entries; with `--fail-on-stale`, any stale
  line makes a `0` become `1` (an actual error result stays `1`; a tool error
  stays `2`). CLI-only for now — no config key until someone asks (YAGNI).

- [ ] **Step 1:** failing tests — parser: flag sets `failOnStale`. Application:
  baseline containing one matching + one fabricated entry → exactly one stale
  stderr line, exit unchanged; same + `--fail-on-stale` → exit 1; an unused
  `#[TrampIgnore]` in the tree → stale suppression line; the same run under
  `--changed-only` with an empty diff → no stale output at all.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#9): stale baseline and suppression detection`.

### Task 7: Fixtures, docs, close-out

**Files:**
- Create: `tests/fixtures/baseline-filters-known/…`,
  `tests/fixtures/baseline-stale-entry/…`
- Modify: `README.md`, `docs/ci.md`, `docs/plan.md`,
  `src/Console/Application.php` (help text: the two baseline flags and
  `--fail-on-stale` lose/never get "reserved" wording)

- **Fixtures** (folder mode; `phptramp-args.json` paths are repo-root-relative):
  `baseline-filters-known` — `src/` with two chains, a committed
  `baseline.json` covering one, args
  `["--limit","1","--baseline","tests/fixtures/baseline-filters-known/baseline.json"]`,
  expected-findings.json shows only the other chain;
  `baseline-stale-entry` — baseline with one fabricated hash, expects the run's
  findings unchanged (stale is stderr-only, invisible to the json document —
  the harness compares stdout).
- **README:** new "Baseline" section — adoption story
  (`--generate-baseline` → commit the file → `--baseline` in CI → entries
  disappear as chains get fixed; stale reporting keeps the file honest;
  `--fail-on-stale` for strict repos), the changed-only interaction note, and
  the config `baseline` key row losing "(reserved for Phase 5)".
- **docs/ci.md:** the legacy-adoption recipe: full-scan job with baseline +
  diff-aware PR job composing `--changed-only --baseline`.
- **docs/plan.md:** check off Phase 5, mark ✅, record the fingerprint-token
  refinement (Global Constraints above) next to frozen rule 10.

- [ ] Steps: fixtures test-first → green + `composer check` →
  commit `test(#9): baseline end-to-end fixtures` → docs edits →
  commit `docs(#9): baseline usage docs and phase 5 status`.

---

## Done when

1. Every task committed on `feature/9-baseline`; `composer check` green at every
   commit; `composer infection:diff` ≥ 80 MSI locally (`git fetch origin main`
   first); `composer tramp` still green.
2. All Phase 5 bullets in `docs/plan.md` checked off; the fingerprint refinement
   is recorded beside frozen rule 10; README/help/docs/ci.md match behavior.
3. PR into `develop` with `Closes #9`; CI (matrix + static + dogfood + mutation)
   green; after merge, verify issue #9 auto-closed.
