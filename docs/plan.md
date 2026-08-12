# phptramp Implementation Plan

> **For agentic workers:** This plan is self-contained — it assumes no prior
> conversation context and no specific tooling/skill ecosystem. Read "Working on this
> repo" below before writing any code. Steps use checkbox (`- [ ]`) syntax for
> tracking; check them off as you go. Phase 1 is written at full step-by-step
> granularity; Phases 2–6 are written at task granularity and each phase MUST be
> expanded into a step-by-step TDD plan (`docs/plans/phase-N.md`, same conventions and
> the same level of literal detail as Phase 1 below) before its implementation starts.
> If your environment provides plan-execution skills (e.g. superpowers'
> subagent-driven-development), use them; if not, execute task-by-task in order.

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

## Working on this repo

Everything an implementer needs that is not code:

- **Setup:** `composer update` (no lock file is committed — this is a library).
  Verify with `composer check`; it must pass before you start and after every commit.
- **Quality gate:** `composer check` = PHPCS (PSR-12) + PHPStan (level max) + PHPMD
  (codesize) + PHPUnit. Individual: `composer cs` / `cs:fix` / `stan` / `md` / `test`.
  CI (`.github/workflows/ci.yml`) runs tests on PHP 8.2/8.3/8.4, static analysis on
  8.4, and diff-scoped Infection mutation testing (minMsi 80) on pull requests.
- **Process — strict TDD, one task at a time:** write the failing test first, run it
  and confirm it fails for the expected reason, implement the minimal code, run green,
  run `composer check`, commit. Never write implementation code without a failing test.
  Never batch multiple tasks into one commit.
- **Commits:** imperative, conventional-commit style (`feat:`, `fix:`, `test:`,
  `ci:`, `docs:`); body explains why when it isn't obvious. Push to `main` directly is
  fine for now (single maintainer); if you open PRs, the mutation job gates them.
- **Semantics questions:** the "Frozen core semantics" section below is the contract.
  If a case is genuinely not covered there or in the Phase 2 algorithm specs, do not
  improvise silently — pick the conservative option (fewer findings, truncate with a
  note), add a fixture documenting the choice, and flag it in the commit message.
- **When a phase is done:** run the "Self-review checklist" at the bottom of this file,
  update this plan's checkboxes and status markers, then write `docs/plans/phase-N.md`
  for the next phase before touching its code.

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
   it purely forwards; terminal never counted). Report when hops ≥ `--limit` (default 6
   since v0.1.x; was 3 in the initial design review, raised after real-world use showed
   3-hop chains are too common to gate by default). `--warn-limit` (default 4) adds a
   warning tier below the limit. `--min-classes <n>` (default 0 = off) suppresses chains
   traversing fewer than `n` distinct classes. `0` on either threshold disables that
   tier. Classes traversed is supplementary output, never the primary threshold, but can
   gate via `--min-classes`.
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
10. **Baseline fingerprint:** `sha1(originFQMN + "\0" + paramName + "\0" + terminalToken)`
     — deliberately excludes line numbers and intermediate hops so refactors don't churn
     the baseline. The terminal component is the terminal FQMN when the chain has one,
     else the `TerminalKind` backing value (`external` / `truncated`) — NOT a per-reason
     category. Coarser is deliberate: a chain whose truncation reason changes when
     resolution improves stays baselined; re-opening grandfathered findings on
     analyzer upgrades would destroy trust in the baseline.
11. **Suppression:** `#[TrampIgnore]` attribute (on method or parameter) and
    `// phptramp-ignore` line comment; a chain is suppressed if any hop is suppressed.
    Stale suppressions and stale baseline entries are reported (Phase 5 — shipped).

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

## Phase 1 — Usage classifier + index  ✅

**Deliverable:** `phptramp --folder <dir> --dump-index` prints the classified method
index as JSON. This makes the classifier — the semantic heart — independently shippable,
testable, and debuggable before any chain logic exists.

### Task 1.1: ArgvParser + Options

**Files:**
- Create: `src/Console/ArgvParser.php`, `src/Console/Options.php`,
  `src/Console/InvalidArgsException.php` (`extends \RuntimeException`)
- Test: `tests/Console/ArgvParserTest.php`

**Interfaces:**
- Produces: `ArgvParser::parse(array $args): Options` — `$args` excludes the program
  name (Application slices argv). Throws `InvalidArgsException`; Application catches
  it, prints the message to stderr, exits 2.
  `Options` readonly properties: `list<string> $folders = []`,
  `list<string> $files = []`, `int $limit = 3`, `?int $warnLimit = null`,
  `string $format = 'pretty'`, `bool $explain = false`, `bool $changedOnly = false`,
  `string $gitBase = 'origin/main'`, `?string $baseline = null`,
  `?string $generateBaseline = null`, `bool $dumpIndex = false`,
  `bool $help = false`, `bool $version = false`. (Default flipped from `text`
  to `pretty` in Phase 3; `Application` downgrades `pretty` → `text` on
  non-TTY STDOUT when `--color=auto`, so pipes/CI stay plain.)

- [x] **Step 1: Write the failing tests** (`tests/Console/ArgvParserTest.php`):

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Console;

use PhpTramp\Console\ArgvParser;
use PhpTramp\Console\InvalidArgsException;
use PhpTramp\Console\Options;
use PHPUnit\Framework\TestCase;

final class ArgvParserTest extends TestCase
{
    private function parse(string ...$args): Options
    {
        return (new ArgvParser())->parse(array_values($args));
    }

    public function testDefaults(): void
    {
        $o = $this->parse();
        self::assertSame([], $o->folders);
        self::assertSame([], $o->files);
        self::assertSame(3, $o->limit);
        self::assertNull($o->warnLimit);
        self::assertSame('pretty', $o->format);
        self::assertFalse($o->explain);
        self::assertFalse($o->changedOnly);
        self::assertSame('origin/main', $o->gitBase);
        self::assertNull($o->baseline);
        self::assertFalse($o->dumpIndex);
    }

    public function testFolderAccumulates(): void
    {
        self::assertSame(['a', 'b'], $this->parse('--folder', 'a', '--folder', 'b')->folders);
    }

    public function testFilesSplitsOnComma(): void
    {
        self::assertSame(['a.php', 'b.php'], $this->parse('--files', 'a.php,b.php')->files);
    }

    public function testFileAppendsToFiles(): void
    {
        self::assertSame(['a.php', 'b.php'], $this->parse('--file', 'a.php', '--file', 'b.php')->files);
    }

    public function testEqualsSyntaxWorks(): void
    {
        self::assertSame(5, $this->parse('--limit=5')->limit);
    }

    public function testLimitAsSeparateArg(): void
    {
        self::assertSame(7, $this->parse('--limit', '7')->limit);
    }

    public function testNonNumericLimitThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--limit', 'x');
    }

    public function testMissingValueThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--folder');
    }

    public function testUnknownFlagThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--nope');
    }

    public function testUnknownFormatThrows(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->parse('--format', 'xml');
    }
}
```

- [x] **Step 2:** `vendor/bin/phpunit --filter ArgvParserTest` → FAIL (class not found).
- [x] **Step 3:** Implement: a `while` loop over args; flags taking values read the next
  element; `--flag=value` split on the first `=`. Valid formats:
  `text|json|github|checkstyle|sarif|summary`.
- [x] **Step 4:** Test passes; `composer check` green.
- [x] **Step 5:** Commit `feat: argv parser and options object`.

### Task 1.2: FileLocator

**Files:**
- Create: `src/Discovery/FileLocator.php`
- Test: `tests/Discovery/FileLocatorTest.php` (uses a tmp dir tree built in setUp)

**Interfaces:**
- Produces: `FileLocator::locate(Options $o): list<string>` — recursive `*.php` under
  each folder (RecursiveDirectoryIterator), plus explicit files; deduplicated, sorted,
  realpath-normalized; nonexistent path → `InvalidArgsException`.

- [x] Steps: failing test (folder recursion, dedup, sorted, missing path throws) → red →
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

- [x] Steps: failing test (fixture file with namespace, class, trait use, function;
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

- [x] **Step 1: Write the failing tests** (`tests/Index/UsageClassifierTest.php`).
  The helper wraps a method body in a class, runs the real Indexer over a temp file,
  and returns the classified params — so these tests pin the *pipeline's* semantics,
  not a mock's:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Index;

use PhpTramp\Index\Indexer;
use PhpTramp\Index\ParamFate;
use PhpTramp\Index\ParamInfo;
use PHPUnit\Framework\TestCase;

final class UsageClassifierTest extends TestCase
{
    /** @return array<string, ParamInfo> keyed by param name, for method C::m */
    private function classify(string $body, string $params = 'Cfg $p'): array
    {
        $code = "<?php class Cfg {} class C { public function m({$params}) { {$body} } }";
        $file = tempnam(sys_get_temp_dir(), 'phptramp') . '.php';
        file_put_contents($file, $code);
        try {
            $method = (new Indexer())->index([$file])->get('C::m');
            self::assertNotNull($method);
            $byName = [];
            foreach ($method->params as $param) {
                $byName[$param->name] = $param;
            }

            return $byName;
        } finally {
            unlink($file);
        }
    }

    public function testPureSingleForwardIsPureForward(): void
    {
        $p = $this->classify('other($p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(1, $p->forwards);
    }

    public function testForwardPlusPropertyReadIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('log($p->env); other($p);')['p']->fate);
    }

    public function testMethodCallOnParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$p->run();')['p']->fate);
    }

    public function testAssignmentFromParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$x = $p; other($p);')['p']->fate);
    }

    public function testReassignmentOfParamIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$p = null; other($p);')['p']->fate);
    }

    public function testReturnIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('return $p;')['p']->fate);
    }

    public function testStringInterpolationIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('echo "x{$p}";')['p']->fate);
    }

    public function testClosureCaptureIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$f = function () use ($p) { other($p); };')['p']->fate);
    }

    public function testArrowFnBodyReferenceIsUsed(): void
    {
        self::assertSame(ParamFate::Used, $this->classify('$f = fn () => other($p);')['p']->fate);
    }

    public function testByRefParamIsByRefTerminated(): void
    {
        self::assertSame(ParamFate::ByRefTerminated, $this->classify('other($p);', 'Cfg &$p')['p']->fate);
    }

    public function testVariadicSpreadForwardIsPureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('other(...$p);', 'Cfg ...$p')['p']->fate);
    }

    public function testNamedArgForwardRecordsArgName(): void
    {
        $p = $this->classify('other(cfg: $p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertSame('cfg', $p->forwards[0]->argKey);
    }

    public function testPositionalForwardRecordsPosition(): void
    {
        $p = $this->classify('other(1, $p);')['p'];
        self::assertSame(1, $p->forwards[0]->argKey);
    }

    public function testTwoForwardsRecordTwoSites(): void
    {
        $p = $this->classify('a($p); b($p);')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(2, $p->forwards);
    }

    public function testForwardIntoNestedCallIsForwardToInnerCallee(): void
    {
        // $p is an argument of wrap(), not of outer(): the edge goes to wrap().
        $p = $this->classify('outer(wrap($p));')['p'];
        self::assertSame(ParamFate::PureForward, $p->fate);
        self::assertCount(1, $p->forwards);
    }

    public function testConstructorArgForwardIsPureForward(): void
    {
        self::assertSame(ParamFate::PureForward, $this->classify('new Cfg($p);')['p']->fate);
    }

    public function testUntouchedParamIsUnused(): void
    {
        self::assertSame(ParamFate::Unused, $this->classify('log(1);')['p']->fate);
    }
}
```

  Plus one test outside the helper (own code string) pinning frozen rule 3:
  a constructor body `$this->x = $p;` → `Used`; same for a promoted property
  `public function __construct(private Cfg $p) {}` → `Used`.

- [x] **Step 2:** run → all red (enum/classes missing).
- [x] **Step 3:** implement the visitor per the algorithm above.
- [x] **Step 4:** all green; `composer check` green.
- [x] **Step 5:** Commit `feat: parameter usage classifier`.

### Task 1.5: `--dump-index` wiring + fixture harness

**Files:**
- Modify: `src/Console/Application.php` (replace stub branch)
- Create: `tests/FixtureTest.php`, `tests/fixtures/classifier-smoke/{src/Demo.php,expected-index.json}`

**Interfaces:**
- Produces: `phptramp --folder x --dump-index` → JSON
  `{"methods": {"<fqmn>": {"file": ..., "params": [{"name","fate","forwards":[...]}]}}}`;
  the `FixtureTest` harness described above (Phase 1 variant compares `--dump-index`
  output; Phase 2 extends it to findings/`expected.json`).

**Fixture (verbatim):** `tests/fixtures/classifier-smoke/src/Demo.php` — the README
chain, which Phase 2 will reuse for the first end-to-end finding:

```php
<?php

namespace Demo;

class Cfg
{
}

class Controller
{
    public function handle(Cfg $config): void
    {
        (new ServiceA())->process($config);
    }
}

class ServiceA
{
    public function process(Cfg $config): void
    {
        (new ServiceB())->run($config);
    }
}

class ServiceB
{
    public function run(Cfg $config): void
    {
        new Mailer($config);
    }
}

class Mailer
{
    private Cfg $config;

    public function __construct(Cfg $config)
    {
        $this->config = $config;
    }
}
```

`expected-index.json` (paths relative to the fixture dir; forwards show
`kind:callee@argKey`):

```json
{
    "methods": {
        "Demo\\Controller::handle": {
            "file": "src/Demo.php",
            "params": [{"name": "config", "fate": "PureForward", "forwards": ["method:process@0"]}]
        },
        "Demo\\ServiceA::process": {
            "file": "src/Demo.php",
            "params": [{"name": "config", "fate": "PureForward", "forwards": ["method:run@0"]}]
        },
        "Demo\\ServiceB::run": {
            "file": "src/Demo.php",
            "params": [{"name": "config", "fate": "PureForward", "forwards": ["new:Demo\\Mailer@0"]}]
        },
        "Demo\\Mailer::__construct": {
            "file": "src/Demo.php",
            "params": [{"name": "config", "fate": "Used", "forwards": []}]
        }
    }
}
```

(Methods with no params are omitted from `--dump-index` output to keep it stable.)

- [x] Steps: failing fixture test → wire pipeline in Application → green → commit
  `feat: --dump-index and fixture harness`. **Phase exit:** CI matrix green.

---

## Phase 2 — Chain stitching → *usable tool*  ✅ (issue #3)

**Deliverable:** `phptramp --folder src --limit 3` prints text findings, exits 0/1/2.

**Detailed step-by-step plan: [docs/plans/phase-2.md](plans/phase-2.md)** — written
against the actual Phase 1 interfaces; where it refines the task sketches below
(e.g. `ClassKind`, `storedOnly`, `Resolution` as an interface with three
implementations), the detailed plan wins.

Tasks (expanded in `docs/plans/phase-2.md`):

- **2.1 ClassHierarchy:** flatten traits into classes; map interface/abstract →
  concrete implementations across the whole index; expose
  `implementationsOf(string $iface): list<string>`.
  Fixtures: trait method chain; interface with one impl; interface with two impls.
- **2.2 CallResolver:** `resolve(ForwardSite $site, MethodInfo $caller): Resolution`
  where `Resolution = Target(fqmn, boundParamName) | External | Truncation(reason)`.
  **`External` is a semantic decision, not an error:** a forward whose target is not in
  the index (`sprintf`, vendor code, unknown class) *terminates the chain like a use*
  — the value left analyzed code, and we cannot call the callee a mindless hop.
  Resolution rules, in order, per `CalleeRef.kind`:
  1. `function`: try the namespaced name, then the global fallback (NameResolver
     provides both). In index → Target; not in index → External.
  2. `static` (`Cls::m`, `self::m`, `static::m`, `parent::m`): resolve the class name
     (`self`/`static` → current class — late static binding is approximated by the
     current class in v0.1; `parent` → parent class), then walk up the parent chain
     for the method. Class unknown → External; class known, method not found →
     Truncation(`method not found`).
  3. `method` on a receiver: type the receiver from, in order: `$this` (current
     class), an inline `(new X(...))` receiver expression, a parameter's declared
     type, a typed property of `$this`, a local assigned exactly once from `new X`.
     Untypeable → Truncation(`untyped receiver`).
     Receiver type is a class in index → parent-chain method lookup. Interface or
     abstract → `ClassHierarchy::implementationsOf()`: exactly 1 → that
     implementation; 0 → External; N → Truncation(`N implementations`). Union or
     intersection type → Truncation(`union type`). Type not in index → External.
  4. `new`: constructor lookup with parent-chain walk; class not in index or no
     constructor found → External.
  Arg binding on Target: named arg → param by name; positional → position, with all
  positions ≥ the variadic collector's position binding to the variadic param.
  Named/positional arg matching no param → Truncation(`arg mismatch`).
- **2.3 ChainBuilder:** Nodes are `(fqmn, paramName)`. For every param with fate
  `PureForward` and each of its ForwardSites, resolve: `Target` → edge to
  `(target, boundParam)`; `External`/`Truncation` → a terminal leaf attached to that
  site. Then:
  1. **Origins** = `PureForward` nodes with no incoming edge. After the main pass, any
     unvisited `PureForward` node that has edges (an entry-less cycle) is also taken
     as an origin and its finding gets a `(recursive)` note.
  2. **DFS** from each origin, current path as the visited set (frozen rule 8 — a node
     already on the path ends the branch with a `(recursive)` note).
   3. **Branching:** every outgoing edge is explored; each root-to-terminal path is its
      own Finding. A param forwarded to two callees therefore yields two findings —
      they share the origin but differ in terminal, which is what the baseline
      fingerprint keys on. A param forwarded to the same callee via multiple call
      sites within one method yields one Finding per call site; they share origin,
      param, and terminal and differ only in the divergent hop's `forwardLine`,
      which every reporter surfaces. They share a fingerprint (the fingerprint
      excludes lines by design).
  4. **Terminals:** child fate `Used` → terminal kind `used` (or `stored` when the
     body is a single property assignment); `ByRefTerminated` → `&-terminated`;
     `Unused` → `unused-end` (dead forwarding — the value goes nowhere); leaf from
     resolution → `external` or `truncated:<reason>`.
  5. **Hops** = count of `PureForward` nodes on the path (terminal never counted).
     `Finding{param, origin, terminal, terminalKind, hops, chain[], classes, notes}`;
     `classes` = distinct declaring classes across chain incl. terminal.
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

## Phase 3 — CI & config surface  ✅ (issue #5)

**Deliverable:** all output formats, config file, suppression, dual thresholds, summary.

**Detailed step-by-step plan: [docs/plans/phase-3.md](plans/phase-3.md)** — written
against the actual Phase 1–2 interfaces; where it refines the sketches below (e.g.
`Severity`/`Thresholds` as reporting-layer concepts, config precedence via
parser-default seeding, suppression exposed through `MethodIndex::suppressions()`),
the detailed plan wins.

- [x] **3.1 ConfigLoader:** `phptramp.json` then `phptramp.dist.json`; keys `paths`,
  `exclude`, `limit`, `warnLimit`, `format`, `baseline`; strict unknown-key error;
  precedence CLI > config > defaults (merge into `Options`). `exclude` glob patterns
  feed FileLocator.
- [x] **3.2 Formats:** `JsonReporter` (the `expected.json` schema — one schema everywhere),
  `GithubReporter` (`::error file=...,line=...::...` per origin, `::notice` per hop),
  `CheckstyleReporter`, `SarifReporter` (rule id `phptramp.trampData`). Fixtures assert
  each format byte-for-byte.
- [x] **3.3 Suppression:** `#[TrampIgnore]` attribute class + `// phptramp-ignore` comments
  scanned during indexing into a `SuppressionIndex`; ChainBuilder drops chains with any
  suppressed hop (frozen rule 11).
- [x] **3.4 Dual thresholds:** `--warn-limit` — findings in `[warn, limit)` render as
  warnings, never affect exit code. GitHub format uses `::warning`.
- [x] **3.5 SummaryReporter:** `--format=summary` — histogram of chain lengths, top 10
  longest chains, most-forwarded parameters.

`--changed-only`/`--git-base`/`--diff` were, at this point, accepted-but-inert CLI
flags — parsed into `Options` for forward compatibility, not yet consumed by
analysis; Phase 4 below wires them up. `--baseline`/`--generate-baseline` remain
accepted-but-inert pending Phase 5.

---

## Phase 4 — Diff-aware mode (flagship)  ✅ (issue #7)

**Deliverable:** `phptramp --changed-only --git-base origin/main` reports only chains
intersecting the diff and marks which hops are the user's.

**Detailed step-by-step plan: [docs/plans/phase-4.md](plans/phase-4.md)** — written
against the shipped Phase 1–3 interfaces; where it refines the sketches below
(diff via `--diff <path|->` instead of a positional `-`, a `--no-config` escape
hatch, marking via immutable Finding rebuild), the detailed plan wins. Two items
are pulled forward into this phase at the maintainer's request, both shipped here
rather than in Phase 6: the repo's own `composer tramp` script + `phptramp.dist.json`,
and the CI dogfood step becoming a real gate (limit 3, warn-limit 2 — thresholds
measured on the actual tree).

- [x] **4.1 DiffParser + ChangedLines:** parse unified diff from stdin (`--changed-only -`)
  or by executing `git diff --unified=0 <base>...HEAD` (note: three-dot, merge-base
  semantics, matching CI expectations); produce `file -> set<line>` of *added/modified*
  lines, realpath-normalized.
- [x] **4.2 Intersection filter:** frozen rule 9 — hop matches if its declaration line or
  its forwarding call-site line ∈ changed lines of its file. Chain reported iff ≥ 1 hop
  matches.
- [x] **4.3 Hop marking:** `Hop::isChanged` flows into every reporter; text renders
  `hop 2  *YOURS*`, json gets `"changed": true`, github annotates changed hops as the
  error location — the "your edit made this chain longer" experience.
- [x] **4.4 CI cookbook page:** `docs/ci.md` with copy-paste GitHub Actions and GitLab
  snippets for PR-only gating.

---

## Phase 5 — Baseline  ✅ (complete — issue #9)

**Detailed step-by-step plan: [docs/plans/phase-5.md](plans/phase-5.md)** — written
against the shipped Phase 1–4 interfaces; where it refines the sketches below
(fingerprint terminal token = terminal FQMN or the TerminalKind value rather than
a per-reason category; suppression refactored into a fired-tracking filter so
stale ignores are detectable; stale detection skipped under `--changed-only`),
the detailed plan wins.

- [x] **5.1 Fingerprint:** frozen rule 10, exact bytes:
  `sha1(origin . "\0" . param . "\0" . terminal)`. The terminal token is the
  terminal FQMN when the chain resolves one, else the `TerminalKind` backing
  value (`external` / `truncated`) — NOT a per-reason category — so resolution
  improvements don't silently re-open baselined findings with a different
  reason string.
- [x] **5.2 Generate/consume:** `--generate-baseline phptramp-baseline.json` (sorted, one
  fingerprint per line-ish JSON for clean diffs); `--baseline` / config `baseline` key
  filters findings before exit-code computation.
- [x] **5.3 Stale detection:** baseline entries matching nothing and `#[TrampIgnore]`
  suppressing nothing → warnings (never exit 1 by default; `--fail-on-stale` opts in).
  Stale detection is skipped entirely under `--changed-only` (full runs only).

---

## Phase 6 — Performance & release  (Tasks 1–5 done on this branch; release follows merge — issue #11)

**Detailed step-by-step plan: [docs/plans/phase-6.md](plans/phase-6.md)** — written
against the shipped Phase 1–5 interfaces; where it refines the sketches below, the
detailed plan wins. Tasks 1–5 (index cache, `--no-cache` + `cache` config key,
PhpStorm recipe, docs release pass, this roadmap close-out) land on
`feature/11-cache-and-release`; the v0.1.0 release (Task 6) follows the merge via the
git-flow release process described in `CLAUDE.md` — not on this branch.

- [x] **6.1 Index cache:** per-file serialized `MethodInfo[]` keyed by (path, mtime,
  size, tool version) under `.phptramp.cache/`; `--no-cache` flag and `cache` config key.
  Measured on `--folder vendor` (4,610 files / 601,867 lines): cold ≈ 19.6s → warm total
  ≈ 4.5s with indexing ≈ 0 (all cache hits; the residual is chain-resolution + reporting).
  Target was warm re-run on a 50k-LOC codebase < 1s — met, and IDE per-save invocation is
  viable on the warm path.
- **6.2 Parallel indexing:** deferred — **the data said no, not yet.** Measured cold
  indexing is ≈ 19.6s for the 600k-LOC vendor tree (≈ 1.6s per 50k LOC), and warm
  indexing with the 6.1 cache is ≈ 0 (all hits), so the per-file cache alone meets the
  stated warm-run target; the `proc_open`-of-self worker pool (no pcntl dependency,
  Windows-CI-friendly) is moved to the post-v0.1 roadmap with the measurement recorded.
- [x] **6.3 Docs:** PhpStorm External Tool + File Watcher recipe shipped at
  `docs/phpstorm.md`; `docs/ci.md` finalized and README release pass done against the
  real tool output.
- **6.4 Release:** (dogfood gating shipped early, in Phase 4) Packagist submission, tag
  `v0.1.0` — follows the merge of this branch into `develop` via the git-flow release
  process (see `CLAUDE.md`); not performed on this branch. The explicit maybe-laters
  (roadmap notes) move to the `## Post-v0.1 roadmap` section below.

---

## Self-review checklist (run at every phase end)

1. Every frozen-semantics bullet touched this phase has a fixture.
2. `composer check` (cs + stan + md + test) green, tests green on 8.2/8.3/8.4 matrix.
3. Help text (`Application::helpText`), README, and this plan agree with actual flags.
4. No placeholder output ("TODO", "not implemented") reachable from shipped flags.

---

## Post-v0.1 roadmap

Explicit maybe-laters recorded during Phases 0–6; each waits on a concrete consumer
ask unless the measurement already says "not yet".

- **`--follow-all-implementations`:** follow all N implementations of an interface
  instead of the single-implementation default — fan-out counts are already recorded
  since Phase 2 (see the single-implementation follow-through bullet in the Appendix),
  so this is a small flag, not a rewrite. Origin: Phase 2 frozen-semantics decision.
- **Field/property tramping:** constructor-stored values re-forwarded via properties
  (the parameter is captured, not just passed). Today the classifier tracks parameter
  forwarding, not property-mediated re-forwarding. Origin: Phase 0 "future work" note.
- **Native PhpStorm plugin:** inline squiggles in the editor. The External Tool / File
  Watcher recipe in `docs/phpstorm.md` is the stopgap. Origin: Phase 6.3 docs stopgap.
- **Composer-plugin command package:** `composer tramp` without the per-project
  script snippet. Origin: Option B from the Phase 0 design review.
- **Parallel indexing:** the deferred 6.2 worker pool (`proc_open`-of-self over file
  shards, `--jobs`). The measurement — cold ≈ 19.6s for the 600k-LOC vendor tree,
  warm indexing ≈ 0 with the 6.1 cache — shows the cache alone meets the target, so
  this is "the data said no, not yet", not descoped. Origin: Phase 6.2 deferral.
- **`--fail-on-stale` config key:** CLI-only since Phase 5; add a `failOnStale` config
  key on demand when a consumer asks for it. Origin: Phase 5.3 stale-detection design.

---

## Appendix: design rationale (why the frozen decisions are what they are)

Recorded so a future implementer can make *aligned* trade-offs when hitting a wall,
instead of guessing.

- **Standalone on php-parser, not a PHPStan extension:** the product is cross-file
  *chain reporting* with its own CLI (`--folder/--file/--limit`, diff mode). PHPStan's
  node-local rule/collector model and fixed CLI fight that shape. Cost accepted:
  shallower type resolution — fine, because pure-forward detection mostly needs
  declared parameter types and `$this`.
- **No symfony/console (or any second prod dependency):** phptramp is a `require-dev`
  tool; every dependency is a potential version conflict inside consumer projects.
  A ~100-line argv parser is cheaper than a dependency forever.
- **Pure-forward-only hop definition:** the moment "partly used but also forwarded"
  counts, false positives explode and CI trust dies. A crisp definition ("this method
  had no business seeing this value") survives arguments in code review. Mixed
  use+forward can become an opt-in strict mode later without breaking anything.
- **By-ref receipt terminates:** the callee may write to it — calling that a mindless
  hop would be a false positive. Conservative beats clever everywhere in this tool.
- **External targets terminate (not truncate):** forwarding into `sprintf` or vendor
  code is the value being *consumed* from our perspective; reporting it as a
  suspicious dead end would spam every codebase.
- **Single-implementation interface follow-through:** DI codebases route everything
  through interfaces; ending chains there guts the tool. Following all N
  implementations turns one report into N near-duplicates. Exactly-one is the
  overwhelmingly common DI case and costs near-zero false positives. Fan-out counts
  are recorded so `--follow-all-implementations` stays a small feature, not a rewrite.
- **Hops-only metric, default 3:** hops measure the smell itself (methods that had no
  business seeing the value); total chain length and class counts vary with codebase
  style. Three is the conventional rule-of-three refactoring threshold.
- **Any-hop diff intersection:** the flagship CI finding is "your edit made an
  existing chain longer" — the new hop is in the diff, the rest of the chain is not,
  so origin-only or new-chains-only filtering would miss it.
- **Baseline fingerprint excludes lines and intermediate hops:** baselines must
  survive refactors; line numbers churn on every edit, and intermediate hops change
  when someone *shortens* a chain (which should count as progress, not as a new
  finding).
- **Whole-project indexing even for `--file`/`--changed-only`:** chains cross files;
  a per-file analysis cannot know a forward is hop 3 of 5. Speed comes from the
  Phase 6 cache, never from narrowing analysis scope.
- **No auto-fix, ever:** fixing tramp data means refactoring (parameter objects, DI
  rewiring) — not mechanically safe, and a half-right auto-fix destroys trust faster
  than no auto-fix.
- **Fixture-first testing:** the product *is* its semantics; every rule lives as an
  input codebase + expected output pair, so semantic regressions are structurally
  hard to ship. Mutation testing (Infection, diff-scoped, minMsi 80) guards the
  classifier/resolver where an escaped mutant literally equals a wrong finding.
- **Toolchain provenance:** PHPCS (PSR-12), PHPMD (codesize-only — complexity metrics
  are the one thing PHPStan max + PSR-12 don't cover), and diff-scoped Infection were
  adopted from the maintainer's simple-feed-reader project, including its hard-won
  CI comments (PR-only mutation, `--ignore-msi-with-no-mutations`, PCOV).
