# Phase 3 Implementation Plan — CI & config surface

> **For agentic workers:** Self-contained; assumes no conversation context. Read
> `CLAUDE.md` (toolchain, Clean Code house style, git-flow workflow) and the
> "Frozen core semantics" + Phase 3 sections of `docs/plan.md` first — this plan
> expands them into steps and repeats what it relies on. Work task-by-task with
> checkboxes; strict TDD; one task per commit.

**Goal:** phptramp becomes CI-shaped: machine formats (`json`, `github`,
`checkstyle`, `sarif`), a `phptramp.json` config file with CLI-over-config
precedence and `exclude` globs, `#[TrampIgnore]` / `// phptramp-ignore`
suppression, `--warn-limit` warning severity, and `--format=summary`.

**Architecture:** The chain layer stays untouched except for one filter hook
(suppression). New `src/Config/` loads and merges the config file *under* CLI
flags by seeding `ArgvParser` with config-derived defaults. `src/Report/` grows a
`Reporter` interface, a `Thresholds`/`Severity` pair (severity is a *reporting*
concept — `Finding` stays threshold-agnostic), and one reporter per format behind
a factory. Suppression is collected during indexing (attributes + comments are
only visible there) and exposed via `MethodIndex::suppressions()` so existing
call sites keep working.

**Tech stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency).

## Global constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit,
  PHPMD-clean for every touched `src/` file, Clean Code house style, strict TDD,
  one task per commit.
- **GitHub issue for this phase: #5.** Branch `feature/5-ci-config-surface` off
  `develop` (already created, carries this plan). Commits `type(#5): …`. PR into
  `develop` with `Closes #5`; diff-scoped Infection gate (minMsi 80) applies.
- Frozen semantics rule 11 (suppression) is the contract for Task 7. Reporter
  output contracts pinned in this plan are byte-for-byte — a fixture asserts each.
- Exit-code contract unchanged: `0` no errors, `1` ≥ 1 error-severity finding,
  `2` tool error. Warnings never affect the exit code.

## Existing interfaces this phase consumes (Phase 1–2, verbatim)

- `Finding{ string $param, string $origin, ?string $terminal, TerminalKind $terminalKind, int $hops, list<Hop> $chain, int $classes, list<string> $notes, list<string> $trace }`
  — chain includes the terminal node only for kinds `used|stored|&-terminated|unused-end`.
- `Hop{ string $fqmn, ?string $class, string $file, int $line, ?int $forwardLine }`
  — `file` is realpath-absolute; `forwardLine` null on the terminal node.
- `TerminalKind: string { Used='used', Stored='stored', ByRef='&-terminated', Unused='unused-end', External='external', Truncated='truncated' }`.
- `ChainBuilder::__construct(CallResolver)`, `::build(MethodIndex): list<Finding>`
  (unfiltered; delegates to `ChainTraversal`).
- `TextReporter::__construct(int $limit, bool $explain = false)`,
  `::render(list<Finding>): string` — output format pinned in
  `tests/Report/TextReporterTest.php`.
- `ArgvParser::parse(list<string> $args): Options` — already accepts
  `--warn-limit`, `--format` with the six valid values, `--baseline`; unknown
  flags/formats throw `InvalidArgsException`. `Options` already carries
  `warnLimit`, `format`, `baseline` (all currently unused downstream).
- `Application::analyze()` currently hard-rejects `format !== 'text'` with exit 2
  — that guard dies in Task 2.
- `FileLocator::locate(Options): list<string>` — folders + files, realpath,
  sorted; no exclude support yet.
- `MethodIndex{ get(), all(), classInfo() }`, built solely by `Indexer::index()`.
- Fixture harness `tests/FixtureTest.php`: `expected-index.json` compares
  `--dump-index` through the CLI; `expected-findings.json` currently calls
  `ChainBuilder` directly (flattened chains) — Task 3 switches it to the CLI.

## File structure (this phase)

```
src/Report/Severity.php           NEW  enum error|warning                        (Task 1)
src/Report/Thresholds.php         NEW  limit + warnLimit -> severityOf(Finding)  (Task 1)
src/Report/Reporter.php           NEW  interface                                 (Task 1)
src/Report/ReporterFactory.php    NEW  format string -> Reporter                 (Task 1)
src/Report/TextReporter.php       MOD  implements Reporter; renders warnings     (Task 1)
src/Console/Application.php       MOD  thresholds + factory wiring; exit on errors only (Task 1)
src/Report/Paths.php              NEW  cwd-relativizing helper for all reporters (Task 2)
src/Report/JsonReporter.php       NEW                                            (Task 2)
tests/FixtureTest.php             MOD  findings mode goes through the CLI        (Task 3)
src/Config/ConfigException.php    NEW                                            (Task 4)
src/Config/ConfigLoader.php       NEW  phptramp.json -> seed Options             (Task 4)
src/Console/ArgvParser.php        MOD  parse(args, Options $defaults)            (Task 4)
src/Console/Options.php           MOD  + readonly list<string> $exclude          (Task 5)
src/Discovery/FileLocator.php     MOD  exclude glob filter                       (Task 5)
src/Report/GithubReporter.php     NEW                                            (Task 6)
src/Report/CheckstyleReporter.php NEW                                            (Task 6)
src/Report/SarifReporter.php      NEW                                            (Task 6)
src/Ignore/TrampIgnore.php        NEW  the attribute class                       (Task 7)
src/Ignore/SuppressionIndex.php   NEW                                            (Task 7)
src/Index/IndexingVisitor.php     MOD  collect attributes + ignore comments      (Task 7)
src/Index/MethodIndex.php         MOD  + suppressions(): SuppressionIndex        (Task 7)
src/Chain/ChainTraversal.php      MOD  drop chains with a suppressed hop         (Task 7)
src/Report/SummaryReporter.php    NEW                                            (Task 8)
tests/…                           mirrors all of the above
tests/fixtures/…                  format/config/suppression cases                (Tasks 3, 9)
```

---

### Task 1: `Severity`, `Thresholds`, `Reporter` interface, warning rendering

`--warn-limit` becomes real. Severity is computed at reporting time so `Finding`
stays a pure chain fact.

**Files:** create `src/Report/{Severity,Thresholds,Reporter,ReporterFactory}.php`;
modify `src/Report/TextReporter.php`, `src/Console/Application.php`;
tests `tests/Report/ThresholdsTest.php`, extend `TextReporterTest`,
`ApplicationTest`.

**Produces:**

```php
enum Severity
{
    case Error;
    case Warning;
}

final class Thresholds
{
    /** @throws InvalidArgsException if warnLimit >= limit (a warn bar above the fail bar is a config error) */
    public function __construct(
        public readonly int $limit,
        public readonly ?int $warnLimit,
    ) {}

    /** Null = below every threshold: not reported at all. */
    public function severityOf(Finding $finding): ?Severity;
}

interface Reporter
{
    /** @param list<Finding> $findings ALL findings, unfiltered — each reporter applies $thresholds itself */
    public function render(array $findings): string;
}

final class ReporterFactory
{
    /** Constructed from Options; returns the Reporter for Options->format. */
    public function create(Options $options): Reporter;
}
```

Behavior:

- `severityOf`: hops ≥ limit → `Error`; warnLimit set and hops ≥ warnLimit →
  `Warning`; else null.
- `TextReporter` header for warnings is `WARNING` instead of `FINDING`; summary
  line becomes `N findings (E errors, W warnings; limit: 3 hops, warn-limit: 2 hops).`
  — with warnLimit unset, existing pinned output must NOT change (regression
  guard: the current `TextReporterTest` expectations stay green untouched).
- `Application`: exit 1 iff any `Error`; reporters receive unfiltered findings.
  The `format !== 'text'` guard stays for exactly the formats the factory cannot
  build *yet* (it shrinks task by task and dies in Task 6).

- [ ] **Step 1:** failing tests — `ThresholdsTest` (error at limit, warning in
  `[warn, limit)`, null below, `warnLimit >= limit` throws); `TextReporterTest`
  warning block + mixed summary line; `ApplicationTest`: warn-only run exits 0
  but prints `WARNING`.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + `composer check`.
- [ ] **Step 5:** commit `feat(#5): dual thresholds with warning severity`.

### Task 2: `JsonReporter` + `Paths`

**Files:** create `src/Report/{Paths,JsonReporter}.php`;
test `tests/Report/JsonReporterTest.php`.

**Produces:** `Paths::relativize(string $absolute): string` — path relative to the
cwd passed at construction when the path lies under it, else unchanged. Every
machine reporter uses it (GitHub annotations and SARIF need workspace-relative
paths; JSON follows for consistency).

**Output contract (pin byte-for-byte;** `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`,
trailing newline**):**

```json
{
    "limit": 3,
    "warnLimit": 2,
    "findings": [
        {
            "param": "config",
            "severity": "error",
            "origin": "Demo\\Controller::handle",
            "terminal": "Demo\\Mailer::__construct",
            "terminalKind": "stored",
            "hops": 3,
            "classes": 4,
            "chain": [
                {"method": "Demo\\Controller::handle", "role": "origin", "file": "src/Demo.php", "line": 12, "forwardLine": 14},
                {"method": "Demo\\ServiceA::process", "role": "hop", "file": "src/Demo.php", "line": 18, "forwardLine": 20},
                {"method": "Demo\\ServiceB::run", "role": "hop", "file": "src/Demo.php", "line": 24, "forwardLine": 26},
                {"method": "Demo\\Mailer::__construct", "role": "terminal", "file": "src/Demo.php", "line": 32, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

`warnLimit` renders as JSON `null` when unset. Roles: first chain entry `origin`,
last `terminal` only when the terminal node is in the chain (see `Finding`
contract), everything else `hop`. Findings below every threshold are omitted.
`severity` is `"error"` or `"warning"`. Empty run → `"findings": []`.

- [ ] Steps: failing test (full document for the 3-hop case, warning case, empty
  case) → red → implement → green + check →
  commit `feat(#5): json reporter`.

### Task 3: Fixture harness through the CLI

**Files:** modify `tests/FixtureTest.php`; rewrite every
`tests/fixtures/*/expected-findings.json` to the Task 2 schema.

The findings mode now invokes `Application` with
`['phptramp', '--folder', <src>, '--format', 'json', '--limit', '1']` — real CLI,
real exit codes (`--limit 1` so every chain is an error and short chains stay
visible). Normalization stays: strip the case-dir prefix from `file` values.
Assert exit 1 when findings exist, 0 when not.

- [ ] Steps: adapt the harness test-first (red on the first fixture), rewrite the
  seven expected files by hand (do NOT generate them from the tool's own output —
  a wrong tool would then pin its own bug; derive them from the fixture source),
  green + check → commit `test(#5): fixture harness exercises the CLI json path`.

### Task 4: `ConfigLoader` + precedence

**Files:** create `src/Config/{ConfigException,ConfigLoader}.php`; modify
`src/Console/ArgvParser.php`, `src/Console/Application.php`;
tests `tests/Config/ConfigLoaderTest.php`, extend `ArgvParserTest`,
`ApplicationTest`.

**Produces:**

```php
final class ConfigLoader
{
    /**
     * Reads phptramp.json, falling back to phptramp.dist.json, in $directory.
     * Missing both -> Options defaults. Unknown key, non-JSON, or wrong value
     * type -> ConfigException (exit 2). Never consults the environment.
     */
    public function load(string $directory): Options;
}
```

- Recognized keys, mapped onto `Options`: `paths` (list of strings; entries
  ending `.php` become `files`, all others `folders`), `exclude` (list of glob
  strings — storage arrives in Task 5, the loader already accepts the key),
  `limit`, `warnLimit`, `format`, `baseline`. Anything else →
  `ConfigException("unknown config key: <key>")` — strict by design, typos must
  not silently disable gating.
- **Precedence mechanic (CLI > config > defaults):**
  `ArgvParser::parse(array $args, Options $defaults = new Options())` — the
  parser's `reset()` seeds every field from `$defaults` instead of literals. Path
  precedence is all-or-nothing: the first `--folder`/`--file`/`--files` flag
  clears *both* seeded lists (config paths replaced entirely, never mixed).
- `Application` wires `ConfigLoader->load(getcwd())` as the parser's defaults;
  a `ConfigException` prints to stderr and exits 2.

- [ ] **Step 1:** failing tests — loader: reads `phptramp.json`; falls back to
  `.dist`; prefers non-dist; missing → defaults; unknown key throws; `paths`
  splits files/folders; wrong type (`"limit": "three"`) throws. Parser: seeded
  defaults survive when flags absent; `--limit` overrides seed; first path flag
  clears seeded paths. Application (chdir into a temp dir in try/finally):
  config `limit: 1` makes a 1-hop chain fail without any CLI flag.
- [ ] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#5): phptramp.json config with CLI-over-config precedence`.

### Task 5: `exclude` globs in `FileLocator`

**Files:** modify `src/Console/Options.php` (add
`public readonly array $exclude = []`, `@param list<string>`),
`src/Config/ConfigLoader.php` (store the key), `src/Discovery/FileLocator.php`;
tests extend `FileLocatorTest`, `ConfigLoaderTest`.

Config-only (no CLI flag — YAGNI until asked; the master plan lists none).
Semantics, pinned: patterns are `fnmatch` globs with `FNM_PATHNAME` **not** set
(so `vendor/*` matches arbitrarily deep), matched against the path *relative to
the config directory* (= cwd), after realpath normalization. A file matching any
pattern is dropped after collection, before sorting.

- [ ] Steps: failing tests (`vendor/*` drops nested file; `*Test.php` drops by
  basename; explicit `--file` beats exclude — an explicitly named file is always
  analyzed) → red → implement → green + check →
  commit `feat(#5): exclude globs from config`.

### Task 6: `GithubReporter`, `CheckstyleReporter`, `SarifReporter`

**Files:** create the three reporters; register in `ReporterFactory` (the last
format guard in `Application` dies); tests one file per reporter.

**GitHub contract (pin):** one annotation per finding at the origin, `::error` /
`::warning` by severity, plus one `::notice` per subsequent hop. Message data
escaping per Actions spec: `%` → `%25`, `\r` → `%0D`, `\n` → `%0A`.

```
::error file=src/Demo.php,line=12,title=phptramp::$config: 3 pass-through hops across 4 classes (terminal: Demo\Mailer::__construct [stored])
::notice file=src/Demo.php,line=18,title=phptramp::hop 2 of $config chain from Demo\Controller::handle
```

Empty run → empty string, exit 0.

**Checkstyle contract (pin):** findings grouped by origin file, one `<error>` per
finding at the origin line, `source="phptramp.trampData"`, message XML-escaped
(`htmlspecialchars(..., ENT_XML1 | ENT_QUOTES)`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<checkstyle version="3.0">
  <file name="src/Demo.php">
    <error line="12" severity="error" message="$config: 3 pass-through hops across 4 classes (terminal: Demo\Mailer::__construct [stored])" source="phptramp.trampData"/>
  </file>
</checkstyle>
```

**SARIF contract (pin):** SARIF 2.1.0, one run, driver `name` `phptramp`,
`informationUri` `https://github.com/larspohlmann/phptramp`, **no `version`
property** (optional per spec; omitting keeps fixtures stable across releases),
one rule `phptramp.trampData`. One result per finding: `ruleId`, `level`
(`error`/`warning`), `message.text` (same message as checkstyle), one location at
the origin (`artifactLocation.uri` relative, `region.startLine`), and one
`relatedLocations` entry per further hop. Encoded
`JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`.

- [ ] Steps per reporter (three sub-cycles, ONE commit each): failing exact-string
  test (error case, warning case, empty case) → red → implement → green + check →
  commits `feat(#5): github actions reporter`, `feat(#5): checkstyle reporter`,
  `feat(#5): sarif reporter`.

### Task 7: Suppression

**Files:** create `src/Ignore/{TrampIgnore,SuppressionIndex}.php`; modify
`src/Index/IndexingVisitor.php`, `src/Index/MethodIndex.php` (constructor gains
`SuppressionIndex $suppressions = new SuppressionIndex([], [], [])` — additive,
existing call sites keep working — plus `suppressions(): SuppressionIndex`),
`src/Chain/ChainTraversal.php`; tests `tests/Ignore/SuppressionIndexTest.php`,
extend `ChainBuilderTest`.

**Produces:**

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_PARAMETER)]
final class TrampIgnore
{
}

final class SuppressionIndex
{
    /**
     * @param list<string> $methods      suppressed method FQMNs (method- or class-level attribute)
     * @param list<array{string, string}> $params  [fqmn, paramName] pairs
     * @param array<string, list<int>> $lines      file -> lines carrying an ignore comment
     */
    public function __construct(...);

    public function suppressesMethod(string $fqmn): bool;
    public function suppressesParam(string $fqmn, string $param): bool;
    public function suppressesLine(string $file, int $line): bool;
}
```

Semantics (frozen rule 11, made concrete):

- **Attribute matching is by short name:** any attribute whose name's last
  segment is `TrampIgnore` counts — analyzed codebases must not be forced to
  autoload our exact class. Class-level attribute suppresses every method of the
  class; method-/function-level every param of the method; param-level that param.
- **Comment form:** a `// phptramp-ignore` line comment (anywhere in the line,
  e.g. after code) suppresses when it sits on the method's declaration line, the
  line directly above it (doc-comment style), or on a forwarding call-site line
  (suppressing chains through that specific edge).
- **Chain filter (in `ChainTraversal`):** a finding is dropped when ANY of its
  hop nodes is suppressed — method-suppressed, param-suppressed for the chain's
  param at that hop, or the hop's `forwardLine`/declaration line is
  comment-suppressed. Terminal nodes never suppress (they are not hops).
- Stale-suppression *detection* is Phase 5 — do not build it here.

- [ ] **Step 1:** failing tests — `SuppressionIndexTest` for the three lookups;
  `ChainBuilderTest`: attribute on middle hop kills the chain; attribute on the
  param only kills chains of that param; class-level kills all chains through the
  class; ignore comment on the forwarding line kills the chain; comment on an
  unrelated line does not; the README 3-hop chain without suppressions is still
  found (regression).
- [ ] **Step 2:** red. **Step 3:** implement (visitor collects during the existing
  traversal — attributes from `attrGroups`, comments via `getComments()` +
  line scan). **Step 4:** green + check.
- [ ] **Step 5:** commit `feat(#5): TrampIgnore attribute and comment suppression`.

### Task 8: `SummaryReporter`

**Files:** create `src/Report/SummaryReporter.php`; register in factory;
test `tests/Report/SummaryReporterTest.php`.

Summary is a whole-codebase overview: it renders **all** findings regardless of
thresholds (the point is triaging a legacy codebase before choosing a limit).
The exit code still follows the normal error rule. Contract (pin):

```
Chains by length:
  1 hop   ######## 8
  2 hops  #### 4
  3 hops  ## 2

Top 10 longest chains:
  3 hops  $config   Demo\Controller::handle -> Demo\Mailer::__construct [stored]
  3 hops  $request  Demo\Kernel::boot -> external

Most-forwarded parameters:
  6x  $config
  3x  $request

14 chains total; 2 at or over the limit (limit: 3 hops).
```

Bars scale to the widest bucket = 40 `#` max; counts sorted descending, ties
alphabetical; top-10 ties broken by origin FQMN.

- [ ] Steps: failing exact-string test (mixed case + empty case:
  `No chains found.`) → red → implement → green + check →
  commit `feat(#5): summary report`.

### Task 9: End-to-end fixtures, docs, phase close-out

**Files:** new fixture dirs; modify `README.md`, `docs/plan.md` (status),
`src/Console/Application.php` help text only if wording drifted.

- **Fixtures:** `warn-vs-error` (chains of 2 and 3 hops; `expected-findings.json`
  run with `--limit 3 --warn-limit 2` — extend the harness to read optional
  per-fixture CLI args from a `phptramp-args.json` next to the expectation
  file); `suppressed-chain` (attribute + comment variants, expects zero
  findings); `config-driven` (a `phptramp.json` inside the fixture exercising
  `paths` + `exclude` + `limit`).
- **README:** status → Phase 3; document config file, formats (one-line example
  each), suppression, `--warn-limit`, and the `composer tramp` script again
  against real behavior.
- **docs/plan.md:** check off Phase 3 bullets, mark the phase ✅.

- [ ] Steps: harness extension + fixtures test-first → green + check →
  commit `test(#5): format, config and suppression fixtures` → docs →
  commit `docs(#5): phase 3 status and usage docs`.

---

## Done when

1. Every task committed on `feature/5-ci-config-surface`; `composer check` green
   at every commit; `composer infection:diff` ≥ 80 MSI locally (`git fetch origin
   main` first).
2. All Phase 3 bullets in `docs/plan.md` checked off; README/help text match
   actual behavior; every reporter contract has an exact-string test AND a
   fixture.
3. PR into `develop` with `Closes #5`; CI (matrix + static + mutation) green;
   after merge, verify issue #5 auto-closed.
