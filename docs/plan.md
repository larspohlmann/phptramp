# phptramp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking. Phase 1 is written at full
> step-by-step granularity; Phases 2–6 are written at task granularity and each phase
> MUST be expanded into a step-by-step TDD plan (`docs/plans/phase-N.md`, same
> conventions as Phase 1 below) before its implementation starts.

**Goal:** A Composer-installable static analyzer that reports *tramp data* — parameters
passed through chains of PHP methods that never use them — usable as a pure CLI call, in
CI (diff-aware, baseline-able), and from IDEs.

**Architecture:** A six-stage pipeline: file discovery → per-method indexing & parameter
usage classification (nikic/php-parser AST) → call-target resolution → cross-file chain
stitching → filtering (limit / diff / baseline / suppressions) → reporting. Analysis
always indexes the whole configured project; `--file` and `--changed-only` are *reporting*
filters, not analysis shortcuts (chains cross files).

**Tech Stack:** PHP ≥ 8.2, nikic/php-parser ^5 (only production dependency), PHPUnit
11/12, PHPStan (level max, on itself), GitHub Actions.

## Global Constraints

- Package `larspohlmann/phptramp`, MIT, binary `vendor/bin/phptramp`, runtime PHP `>=8.2`.
- Only production dependency: `nikic/php-parser ^5`. No symfony/console — a hand-rolled
  `ArgvParser` keeps the tool conflict-free as a `require-dev` install.
- Exit codes: `0` = no findings ≥ limit, `1` = findings ≥ limit, `2` = tool error.
- Default `--limit` is `3`. Threshold counts **pass-through hops only** (see semantics).
- Config file `phptramp.json` / `phptramp.dist.json`; precedence CLI > config > defaults.
- Fixture-first TDD: every semantic rule in this document exists as a fixture under
  `tests/fixtures/<case>/` before the code that satisfies it.
- Full QA toolchain green in every commit: PHPStan level max (`src/`, `bin/`), PHPCS
  PSR-12 (`src/`, `tests/` minus fixtures), PHPMD codesize (`src/`), PHPUnit. PRs
  additionally pass diff-scoped Infection mutation testing (minMsi 80) — for a tool
  whose product is semantic correctness, escaped mutants in classifier/resolver code
  are exactly the bugs users would hit. `composer check` runs cs+stan+md+test locally.
- **Non-goal:** auto-fix. Never plan or implement it.

---

## Frozen core semantics (design-review decisions)

These were fixed in the design review and are **not** up for reinterpretation during
implementation. Every bullet is a fixture.

1. **Hop definition — pure forwarding only.** A method is a hop for parameter `$p` iff
   every occurrence of `$p` in its body is as a whole argument to a call (renaming via
   the callee's parameter name is irrelevant). Any other occurrence — property fetch,
   method call on `$p`, array access, use in an expression, assignment to or from `$p`,
   `return $p`, capture in a closure — makes the method a **terminal use** and ends the
   chain there.
2. **By-ref receipt terminates.** A parameter declared `&$p` is `ByRefTerminated`: the
   method may write to it, so it is never a mindless hop. Reported with an
   `&-terminated` note.
3. **Constructor storage is use.** `$this->x = $p` (incl. promoted properties) ends the
   chain at the constructor. "Field tramping" through properties is future work, not v0.1.
4. **Threshold metric:** number of pure-forward methods in the chain (origin included if
   it purely forwards; terminal never counted). Report when hops ≥ `--limit` (default 3).
   Classes traversed is supplementary output, never the threshold.
5. **Call resolution (v0.1):** plain functions, `self::`/`static::`/`ClassName::`
   statics, `$this->m()`, calls on values with a single declared class type (params,
   typed properties, `new X`), constructors, trait methods (through `use`), named
   arguments (mapped to parameters), variadic spread forwarding
   (`function a(...$args) { b(...$args); }` is a hop).
6. **Interfaces/abstract types:** follow the call **only when exactly one implementation
   exists in the analyzed code**; otherwise truncate with note
   `"N implementations, chain truncated"`. Record the fan-out count (future
   `--follow-all-implementations`).
7. **Out of scope v0.1** (truncate with a note where detectable): closures / arrow
   functions, first-class callable syntax, `call_user_func*`, `__call`/`__callStatic`,
   dynamic method names, `func_get_args()`.
8. **Recursion:** cycles in the forward graph are detected with a visited set and
   reported once with a `(recursive)` note; never loop.
9. **Diff-aware mode:** any-hop intersection at changed-**line** granularity. A chain is
   reported iff at least one hop's signature line or forwarding call-site line falls in
   the diff. Intersecting hops are marked in output ("this hop is yours").
10. **Baseline fingerprint:** `sha1(originFQMN + "\0" + paramName + "\0" + terminalFQMN)`
    — deliberately excludes line numbers and intermediate hops so refactors don't churn
    the baseline.
11. **Suppression:** `#[TrampIgnore]` attribute (on method or parameter) and
    `// phptramp-ignore` line comment; a chain is suppressed if any hop is suppressed.
    Stale suppressions and stale baseline entries are reported (Phase 5).

---

## File structure (target state)

```
bin/phptramp                      entry point (done, Phase 0)
src/
  Console/
    Application.php               wiring: parse args -> run pipeline -> exit code (Phase 0 stub)
    ArgvParser.php                argv -> Options, no dependencies        (Phase 1)
    Options.php                   immutable value object of all settings  (Phase 1)
  Config/
    ConfigLoader.php              phptramp.json loading + CLI precedence  (Phase 3)
  Discovery/
    FileLocator.php               --folder/--file/--files + excludes -> file list (Phase 1)
  Index/
    ParamFate.php                 enum PureForward|Used|ByRefTerminated|Unused (Phase 1)
    ForwardSite.php               one forwarding call site                (Phase 1)
    CalleeRef.php                 syntactic call target (kind + name + receiver hint) (Phase 1)
    ParamInfo.php                 param name, position, fate, forwards    (Phase 1)
    MethodInfo.php                FQMN, file, line, params, class context (Phase 1)
    MethodIndex.php               map FQMN -> MethodInfo + class hierarchy facts (Phase 1)
    Indexer.php                   files -> MethodIndex (php-parser, NameResolver) (Phase 1)
    UsageClassifier.php           AST of one method -> ParamInfo[]        (Phase 1, the heart)
  Resolve/
    ClassHierarchy.php            interface -> implementations, trait flattening (Phase 2)
    CallResolver.php              CalleeRef + context -> target FQMN | TruncationReason (Phase 2)
  Chain/
    ChainBuilder.php              index + resolver -> Finding[]           (Phase 2)
    Hop.php, Finding.php          value objects                           (Phase 2)
  Diff/
    DiffParser.php                unified diff / git exec -> ChangedLines (Phase 4)
    ChangedLines.php              file -> line-range set, intersection queries (Phase 4)
  Baseline/
    Fingerprint.php, Baseline.php generate/load/match/stale detection     (Phase 5)
  Ignore/
    TrampIgnore.php               the attribute class (shipped for IDE autocomplete) (Phase 3)
    SuppressionIndex.php          attribute + comment scan                (Phase 3)
  Report/
    TextReporter.php              (Phase 2)
    JsonReporter.php, GithubReporter.php, CheckstyleReporter.php,
    SarifReporter.php, SummaryReporter.php                                (Phase 3)
tests/
  fixtures/<case>/src/*.php       tiny input codebases
  fixtures/<case>/expected.json   expected analyzer output
  ...unit tests mirroring src/
```

**Fixture harness contract (Phase 1 Task 1.5, used by every later phase):** a
`FixtureTest` runs the full pipeline over `tests/fixtures/<case>/src` and compares
normalized JSON output against `expected.json`. Adding a semantic rule = adding a
fixture directory. `expected.json` schema (frozen early, versioned with the tool):

```json
{
    "findings": [
        {
            "param": "config",
            "origin": "Demo\\Controller::handle",
            "terminal": "Demo\\Mailer::__construct",
            "terminalKind": "stored",
            "hops": 3,
            "chain": [
                {"method": "Demo\\Controller::handle", "role": "hop"},
                {"method": "Demo\\ServiceA::process", "role": "hop"},
                {"method": "Demo\\ServiceB::run", "role": "hop"},
                {"method": "Demo\\Mailer::__construct", "role": "terminal"}
            ],
            "classes": 4,
            "notes": []
        }
    ]
}
```

---

## Phase 0 — Scaffold  ✅ (this commit)

Repo, composer.json, `bin/phptramp` + help/version stub with tests, PHPUnit/PHPStan/CI
skeleton, README with the `composer tramp` script recipe, this plan.

---

## Phase 1 — Usage classifier + index

**Deliverable:** `phptramp --folder <dir> --dump-index` prints the classified method
index as JSON. This makes the classifier — the semantic heart — independently shippable,
testable, and debuggable before any chain logic exists.

### Task 1.1: ArgvParser + Options

**Files:**
- Create: `src/Console/ArgvParser.php`, `src/Console/Options.php`
- Test: `tests/Console/ArgvParserTest.php`

**Interfaces:**
- Produces: `ArgvParser::parse(array $args): Options` (throws
  `InvalidArgsException` with message for exit code 2);
  `Options` readonly properties: `array $folders`, `array $files`, `int $limit = 3`,
  `?int $warnLimit`, `string $format = 'text'`, `bool $explain`, `bool $changedOnly`,
  `string $gitBase = 'origin/main'`, `?string $baseline`, `?string $generateBaseline`,
  `bool $dumpIndex`, `bool $help`, `bool $version`.

- [ ] **Step 1: Write failing tests** — `--folder a --folder b` accumulates;
  `--files a.php,b.php` splits; `--limit 5` casts to int; `--limit x` throws;
  unknown flag throws; `--file x.php` appends to `files`.
- [ ] **Step 2:** `vendor/bin/phpunit --filter ArgvParserTest` → FAIL (class not found).
- [ ] **Step 3:** Implement: a `while` loop over args; flags taking values read the next
  element; `str_contains($arg, '=')` also supported (`--limit=5`).
- [ ] **Step 4:** Test passes; `composer stan` green.
- [ ] **Step 5:** Commit `feat: argv parser and options object`.

### Task 1.2: FileLocator

**Files:**
- Create: `src/Discovery/FileLocator.php`
- Test: `tests/Discovery/FileLocatorTest.php` (uses a tmp dir tree built in setUp)

**Interfaces:**
- Produces: `FileLocator::locate(Options $o): list<string>` — recursive `*.php` under
  each folder (RecursiveDirectoryIterator), plus explicit files; deduplicated, sorted,
  realpath-normalized; nonexistent path → `InvalidArgsException`.

- [ ] Steps: failing test (folder recursion, dedup, sorted, missing path throws) → red →
  implement → green → commit `feat: file discovery`.

### Task 1.3: Indexer skeleton (methods + params, no classification)

**Files:**
- Create: `src/Index/{Indexer,MethodIndex,MethodInfo,ParamInfo,ParamFate,ForwardSite,CalleeRef}.php`
- Test: `tests/Index/IndexerTest.php`

**Interfaces:**
- Produces: `Indexer::index(list<string> $files): MethodIndex`.
  FQMN format: `Ns\Class::method` for methods, `Ns\func` for functions (the `::`
  separator distinguishes them everywhere downstream).
  `MethodIndex::get(string $fqmn): ?MethodInfo`, `MethodIndex::all(): iterable`.
  Also records per class: implemented interfaces, parent, used traits (raw names —
  hierarchy semantics live in Phase 2's `ClassHierarchy`).
- Uses php-parser `NodeTraverser` + `NameResolver` (FQ names for free), `ParentConnectingVisitor`.
  Parse errors → collect and report file + message, exit 2.

- [ ] Steps: failing test (fixture file with namespace, class, trait use, function;
  assert FQMNs, param names/positions, by-ref flag, variadic flag, interface list) →
  red → implement → green → commit `feat: method index`.

### Task 1.4: UsageClassifier — the heart

**Files:**
- Create: `src/Index/UsageClassifier.php` (invoked by Indexer per method body)
- Test: `tests/Index/UsageClassifierTest.php` — one test per frozen-semantics rule.

**Classification algorithm (per parameter `$p` of one method body):**
1. If declared by-ref → `ByRefTerminated`, done.
2. Walk the body AST. Skip subtrees of `Closure`/`ArrowFunction`/nested class — but if
   `$p` appears in a closure's `use()` list or arrow-fn body → mark `Used` (frozen rule 7).
3. For each `Variable` node named `p`, classify its parent context:
   - Parent is `Arg` whose value is exactly the variable, attached to a `FuncCall` /
     `MethodCall` / `StaticCall` / `New_` → record a `ForwardSite` (CalleeRef kind:
     function|method|static|new; receiver hint: `this`|param name|typed local class|raw;
     arg position or name for named args; line).
   - `...$p` spread where `$p` is variadic and spread is the whole arg → `ForwardSite`.
   - Anything else (PropertyFetch base, MethodCall var, ArrayDimFetch, BinaryOp, Assign
     in either position, Return_, foreach subject, cast, interpolation, isset/empty…)
     → `Used`, stop scanning this param.
4. Zero occurrences → `Unused`. Forwards only → `PureForward`. Any use → `Used`
   (forwards list kept anyway — Phase 4 needs call-site lines even for terminals).

**Test cases (each later duplicated as a pipeline fixture):** pure single forward;
forward + property read = Used; assignment = Used; return = Used; closure capture = Used;
by-ref param; variadic spread forward; named-arg forward records arg name; two forwards
of same param records two sites; `$this->x = $p` in constructor = Used(stored).

- [ ] Steps per TDD: all tests red → implement visitor → green → stan green → commit
  `feat: parameter usage classifier`.

### Task 1.5: `--dump-index` wiring + fixture harness

**Files:**
- Modify: `src/Console/Application.php` (replace stub branch)
- Create: `tests/FixtureTest.php`, `tests/fixtures/classifier-smoke/{src/Demo.php,expected-index.json}`

**Interfaces:**
- Produces: `phptramp --folder x --dump-index` → JSON
  `{"methods": {"<fqmn>": {"file": ..., "params": [{"name","fate","forwards":[...]}]}}}`;
  the `FixtureTest` harness described above (Phase 1 variant compares `--dump-index`
  output; Phase 2 extends it to findings/`expected.json`).

- [ ] Steps: failing fixture test → wire pipeline in Application → green → commit
  `feat: --dump-index and fixture harness`. **Phase exit:** CI matrix green.

---

## Phase 2 — Chain stitching → *usable tool*

**Deliverable:** `phptramp --folder src --limit 3` prints text findings, exits 0/1/2.

Tasks (expand to steps in `docs/plans/phase-2.md` before starting):

- **2.1 ClassHierarchy:** flatten traits into classes; map interface/abstract →
  concrete implementations across the whole index; expose
  `implementationsOf(string $iface): list<string>`.
  Fixtures: trait method chain; interface with one impl; interface with two impls.
- **2.2 CallResolver:** `resolve(CalleeRef, MethodInfo $context): Resolution` where
  `Resolution = target FQMN | Truncation(reason)`. Rules table = frozen semantics 5–7.
  Receiver typing sources, in order: `$this`, parameter declared types, typed property
  of `$this`, `new X` assigned to local (single assignment only). Named args map via
  target signature. Unresolvable → `Truncation('dynamic call'| 'untyped receiver' | ...)`.
- **2.3 ChainBuilder:** build edge set `(callerFqmn, param) --forward--> (calleeFqmn,
  param)` from all `PureForward` params via CallResolver. Origins = nodes with no
  incoming edge. DFS from each origin with visited set (frozen rule 8); at each end
  emit `Finding{param, origin, terminal|truncation, hops, chain[], classes, notes}`.
  Hops = count of PureForward nodes on the path. Terminal kinds: `used`, `stored`
  (single assignment to property), `&-terminated`, `truncated:<reason>`, `unused-end`.
- **2.4 TextReporter + exit codes + `--explain`:** render as in README example;
  `--explain` appends per-finding the Truncation/resolution trace. Exit 1 iff any
  finding with terminal != truncated? No — exit 1 iff hops ≥ limit regardless of
  terminal kind (truncated chains that already exceed the limit are still findings;
  note says the chain is *at least* that long).
- **2.5 End-to-end fixtures:** the README 3-hop chain; chain broken by use at hop 2;
  interface single-impl chain; multi-impl truncation; variadic decorator chain;
  recursion; static + function mixed chain. Dogfood job added to CI
  (`phptramp --folder src` — report-only at first: `--limit 99`).

---

## Phase 3 — CI & config surface

**Deliverable:** all output formats, config file, suppression, dual thresholds, summary.

- **3.1 ConfigLoader:** `phptramp.json` then `phptramp.dist.json`; keys `paths`,
  `exclude`, `limit`, `warnLimit`, `format`, `baseline`; strict unknown-key error;
  precedence CLI > config > defaults (merge into `Options`). `exclude` glob patterns
  feed FileLocator.
- **3.2 Formats:** `JsonReporter` (the `expected.json` schema — one schema everywhere),
  `GithubReporter` (`::error file=...,line=...::...` per origin, `::notice` per hop),
  `CheckstyleReporter`, `SarifReporter` (rule id `phptramp.trampData`). Fixtures assert
  each format byte-for-byte.
- **3.3 Suppression:** `#[TrampIgnore]` attribute class + `// phptramp-ignore` comments
  scanned during indexing into a `SuppressionIndex`; ChainBuilder drops chains with any
  suppressed hop (frozen rule 11).
- **3.4 Dual thresholds:** `--warn-limit` — findings in `[warn, limit)` render as
  warnings, never affect exit code. GitHub format uses `::warning`.
- **3.5 SummaryReporter:** `--format=summary` — histogram of chain lengths, top 10
  longest chains, most-forwarded parameters.

---

## Phase 4 — Diff-aware mode (flagship)

**Deliverable:** `phptramp --changed-only --git-base origin/main` reports only chains
intersecting the diff and marks which hops are the user's.

- **4.1 DiffParser + ChangedLines:** parse unified diff from stdin (`--changed-only -`)
  or by executing `git diff --unified=0 <base>...HEAD` (note: three-dot, merge-base
  semantics, matching CI expectations); produce `file -> set<line>` of *added/modified*
  lines, realpath-normalized.
- **4.2 Intersection filter:** frozen rule 9 — hop matches if its declaration line or
  its forwarding call-site line ∈ changed lines of its file. Chain reported iff ≥ 1 hop
  matches.
- **4.3 Hop marking:** `Hop::isChanged` flows into every reporter; text renders
  `hop 2  *YOURS*`, json gets `"changed": true`, github annotates changed hops as the
  error location — the "your edit made this chain longer" experience.
- **4.4 CI cookbook page:** `docs/ci.md` with copy-paste GitHub Actions and GitLab
  snippets for PR-only gating.

---

## Phase 5 — Baseline

- **5.1 Fingerprint:** frozen rule 10, exact bytes:
  `sha1(origin . "\0" . param . "\0" . terminal)`. Truncated chains use
  `truncated:<reason-category>` as terminal so resolution improvements don't silently
  re-open baselined findings with a different reason string.
- **5.2 Generate/consume:** `--generate-baseline phptramp-baseline.json` (sorted, one
  fingerprint per line-ish JSON for clean diffs); `--baseline` / config `baseline` key
  filters findings before exit-code computation.
- **5.3 Stale detection:** baseline entries matching nothing and `#[TrampIgnore]`
  suppressing nothing → warnings (never exit 1 by default; `--fail-on-stale` opts in).

---

## Phase 6 — Performance & release

- **6.1 Index cache:** per-file serialized `MethodInfo[]` keyed by (path, mtime, size,
  tool version) under `.phptramp.cache/`; `--no-cache` flag. Target: warm re-run on a
  50k-LOC codebase < 1s (this is what makes IDE per-save invocation viable).
- **6.2 Parallel indexing:** worker pool via `proc_open` of self with a hidden
  `--worker` mode over file shards (no pcntl dependency — works on Windows CI). Decide
  worker count from `nproc`, flag `--jobs`.
- **6.3 Docs:** PhpStorm External Tool + File Watcher recipe (`docs/phpstorm.md`),
  finalize `docs/ci.md`, README rewrite against the real tool output.
- **6.4 Release:** dogfood job gating (`--limit 3`, real), Packagist submission, tag
  `v0.1.0`. Roadmap notes (explicit maybe-laters): `--follow-all-implementations`,
  field/property tramping, native PhpStorm plugin, composer-plugin command package.

---

## Self-review checklist (run at every phase end)

1. Every frozen-semantics bullet touched this phase has a fixture.
2. `composer check` (cs + stan + md + test) green, tests green on 8.2/8.3/8.4 matrix.
3. Help text (`Application::helpText`), README, and this plan agree with actual flags.
4. No placeholder output ("TODO", "not implemented") reachable from shipped flags.
