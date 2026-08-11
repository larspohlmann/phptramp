# Phase 2 Implementation Plan — Chain stitching → *usable tool*

> **For agentic workers:** Self-contained; assumes no conversation context. Read
> `CLAUDE.md` (toolchain, Clean Code house style, git-flow workflow) and the
> "Frozen core semantics" + Phase 2 sections of `docs/plan.md` first — this plan
> expands them into steps and repeats what it relies on. Work task-by-task with
> checkboxes; strict TDD; one task per commit.

**Goal:** `phptramp --folder src --limit 3` prints text findings for cross-file
tramp-data chains and exits 0/1/2.

**Architecture:** Two new layers on top of the Phase 1 index. `src/Resolve/` turns a
syntactic `ForwardSite` into a concrete target method (or an honest non-answer);
`src/Chain/` walks the resulting forward-graph from origins to terminals and emits
`Finding`s. `src/Report/TextReporter` renders them. No Phase 1 semantics change —
the only Index-layer edits are two *additive* facts the resolver needs
(`ClassKind`, `storedOnly`).

**Tech stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency).

## Global constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit,
  PHPMD-clean for every touched `src/` file, Clean Code non-negotiables, house style
  `final` + `readonly` value objects, typed exceptions, guard clauses.
- **GitHub issue for this phase: #3.** Branch `feature/3-chain-stitching` off
  `develop` (`git flow feature start 3-chain-stitching`). Commits use `type(#3): …`.
  PR into `develop` with `Closes #3`; the diff-scoped Infection gate (minMsi 80)
  applies — classifier/resolver/chain code is exactly where escaped mutants equal
  wrong findings, so kill them, never tune the threshold.
- Frozen semantics (`docs/plan.md`) are the contract; the Phase 2 algorithm specs
  there (CallResolver rules 1–4, ChainBuilder steps 1–5) are normative. Uncovered
  case → conservative choice + fixture + flag in the commit message.
- Exit codes: `0` no findings ≥ limit, `1` at least one, `2` tool error. Default
  limit 3. In this phase findings below `--limit` are neither printed nor counted
  (`--warn-limit` display arrives in Phase 3).

## Existing interfaces this phase consumes (Phase 1, verbatim)

- `MethodIndex::get(string $fqmn): ?MethodInfo`, `::all(): iterable<string, MethodInfo>`,
  `::classInfo(string $fqcn): ?ClassInfo`.
- `MethodInfo{ string $fqmn, string $file, int $line, list<ParamInfo> $params, ?string $class }`.
- `ParamInfo{ string $name, int $position, ParamFate $fate, list<ForwardSite> $forwards, bool $byRef, bool $variadic, ?string $type }`.
- `ParamFate::{PureForward, Used, ByRefTerminated, Unused}`.
- `ForwardSite{ CalleeRef $callee, int|string $argKey, int $line }`.
- `CalleeRef{ CalleeKind $kind, string $name, ?string $receiverHint }` —
  `receiverHint` is `'this'`, a parameter name, an inferred FQCN, or `'raw'`
  (untypeable); null for `Func`/`Instantiation`.
- `CalleeKind::{Func('function'), Method('method'), StaticCall('static'), Instantiation('new')}`.
- FQMN format: `Ns\Class::method` for methods, `Ns\func` for functions.
- Test helper pattern (from `UsageClassifierTest`): write a code string to a temp
  file, run the real `Indexer`, assert on the result — reuse it everywhere below.

## File structure (this phase)

```
src/Index/ClassKind.php          NEW  enum: concrete|abstract|interface|trait|enum   (Task 1)
src/Index/ClassInfo.php          MOD  + readonly ClassKind $kind                     (Task 1)
src/Index/IndexingVisitor.php    MOD  record the kind                                (Task 1)
src/Index/ParamInfo.php          MOD  + readonly bool $storedOnly                    (Task 4)
src/Index/UsageClassifier.php    MOD  detect stores-only usage                       (Task 4)
src/Resolve/ClassHierarchy.php   NEW  trait flattening, parent walk, implementations (Task 2)
src/Resolve/Resolution.php       NEW  interface (marker)                             (Task 3)
src/Resolve/ResolvedTarget.php   NEW  final: fqmn + boundParam                       (Task 3)
src/Resolve/ExternalTarget.php   NEW  final: chain leaves analyzed code              (Task 3)
src/Resolve/TruncatedResolution.php NEW final: reason string                         (Task 3)
src/Resolve/ReceiverTyper.php    NEW  receiverHint -> FQCN (keeps CallResolver PHPMD-clean) (Task 3)
src/Resolve/CallResolver.php     NEW  ForwardSite + caller -> Resolution             (Task 3)
src/Chain/TerminalKind.php       NEW  enum with output tokens                        (Task 5)
src/Chain/Hop.php                NEW  one chain node for reporting                   (Task 5)
src/Chain/Finding.php            NEW  one reported chain                             (Task 5)
src/Chain/ChainBuilder.php       NEW  index -> list<Finding>                         (Task 5)
src/Report/TextReporter.php      NEW  findings -> text                               (Task 6)
src/Console/Application.php      MOD  default pipeline + exit codes; --explain       (Tasks 6–7)
tests/…                          mirrors all of the above
tests/fixtures/…                 end-to-end cases                                    (Task 8)
.github/workflows/ci.yml         MOD  dogfood step                                   (Task 8)
```

---

### Task 1: `ClassKind` on `ClassInfo`

The resolver must distinguish "concrete class" (chain can enter) from
"interface/abstract" (needs implementation lookup) — `ClassInfo` doesn't carry that.

**Files:** create `src/Index/ClassKind.php`; modify `src/Index/ClassInfo.php`,
`src/Index/IndexingVisitor.php`; test `tests/Index/IndexerTest.php` (extend).

**Produces:**

```php
enum ClassKind
{
    case ConcreteClass;
    case AbstractClass;
    // Trailing underscores mirror php-parser's node names (Interface_, Trait_, Enum_).
    case Interface_;
    case Trait_;
    case Enum_;
}
```

`ClassInfo` gains `public readonly ClassKind $kind` (second constructor arg, after
`$name`). `IndexingVisitor::recordClass()` maps the node type; `Class_` checks
`$node->isAbstract()`.

- [x] **Step 1:** extend `IndexerTest` with one test: index a code string containing
  a concrete class, an abstract class, an interface, a trait, and an enum; assert
  `classInfo(...)->kind` for all five.
- [x] **Step 2:** run → red (enum missing).
- [x] **Step 3:** implement; fix the two `ClassInfo` construction sites.
- [x] **Step 4:** green; `composer check` green.
- [x] **Step 5:** commit `feat(#3): record class kind in the index`.

### Task 2: `ClassHierarchy`

**Files:** create `src/Resolve/ClassHierarchy.php`;
test `tests/Resolve/ClassHierarchyTest.php`.

**Produces:**

```php
final class ClassHierarchy
{
    public function __construct(private readonly MethodIndex $index) {}

    /** Direct declaration, then used traits, then the parent chain — first hit wins. */
    public function methodOn(string $fqcn, string $method): ?MethodInfo;

    /** True for Interface_ and AbstractClass kinds. */
    public function isAbstractType(string $fqcn): bool;

    /**
     * Concrete classes/enums that implement the interface (transitively, incl. via
     * parents) or extend the abstract class (transitively). Sorted for determinism.
     * @return list<string>
     */
    public function implementationsOf(string $fqcn): array;
}
```

Cycle guard: parent/interface walks carry a visited set — malformed input code must
not hang the analyzer.

- [x] **Step 1:** failing tests, built on the temp-file Indexer helper:
  method found directly; via trait `use`; via parent; via grandparent; trait beats
  parent (PHP semantics); unknown class → null. `implementationsOf`: interface with
  one concrete impl; with two (sorted); via `extends` chain of interfaces; via
  abstract base; abstract class itself never listed; unknown name → `[]`.
  `isAbstractType` for all five kinds.
- [x] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [x] **Step 5:** commit `feat(#3): class hierarchy with trait flattening and implementation lookup`.

### Task 3: `Resolution` + `ReceiverTyper` + `CallResolver`

**Files:** create the six `src/Resolve/` files listed above;
tests `tests/Resolve/CallResolverTest.php` (ReceiverTyper is covered through it —
it is an internal helper, not an API).

**Produces:**

```php
interface Resolution {}

final class ResolvedTarget implements Resolution
{
    public function __construct(
        public readonly string $fqmn,
        public readonly string $boundParam,
    ) {}
}

final class ExternalTarget implements Resolution
{
    /** Why the target counts as outside analyzed code — for --explain. */
    public function __construct(public readonly string $detail) {}
}

final class TruncatedResolution implements Resolution
{
    public function __construct(public readonly string $reason) {}
}

final class CallResolver
{
    public function __construct(
        private readonly MethodIndex $index,
        private readonly ClassHierarchy $hierarchy,
    ) {}

    public function resolve(ForwardSite $site, MethodInfo $caller): Resolution;
}
```

Behavior = the normative rules 1–4 in `docs/plan.md` Phase 2.2, with these
bindings to Phase 1 reality:

- `CalleeKind::Func`: `CalleeRef->name` is already resolved by NameResolver where
  possible. In index → `ResolvedTarget`; else `ExternalTarget('function not in index')`.
- `CalleeKind::StaticCall`: `name` is the class (`self`/`static` already resolved to
  the current class by NameResolver; if the literal strings `self`/`static`/`parent`
  survive, map them via `$caller->class` / its `ClassInfo->parent`). Method lookup via
  `ClassHierarchy::methodOn`. Class not in index → External; method missing →
  `TruncatedResolution('method not found')`.
- `CalleeKind::Method`: type the receiver via `ReceiverTyper` from
  `CalleeRef->receiverHint`, in this order — `'this'` → `$caller->class`; a string
  matching one of `$caller->params` by name → that param's declared `$type`
  (**check param names before class names**: a hint is ambiguous, and a parameter
  in the caller's own signature is the closer binding); otherwise treat the hint as
  an FQCN; `'raw'`/null → `TruncatedResolution('untyped receiver')`.
  A param type that is not a single class name (contains `|` or `&`, is a builtin
  like `array`) → `TruncatedResolution('unresolvable type')`. Typed receiver:
  concrete in index → `methodOn` lookup; `isAbstractType` →
  `implementationsOf`: exactly 1 → resolve into it, 0 →
  `ExternalTarget('no implementation in index')`, N →
  `TruncatedResolution('N implementations')` (embed the number). Type not in
  index → External.
- `CalleeKind::Instantiation`: `methodOn($name, '__construct')`; class or
  constructor not found → External.
- **Arg binding** on a found target: `argKey` string → param by name; int → param by
  position, where positions ≥ the variadic collector's position bind to the variadic
  param. No match → `TruncatedResolution('argument does not bind to a parameter')`.

- [x] **Step 1:** failing tests (temp-file Indexer helper; one test per rule):
  function in index; `sprintf` → External; static via class name; static inherited
  from parent; `$this->m()`; method via param declared type; via inline-new receiver
  (Phase 1 emits the inferred FQCN hint); interface single impl → that impl;
  interface two impls → Truncated mentioning `2`; interface zero impls → External;
  union-typed receiver → Truncated; `raw` hint → Truncated('untyped receiver');
  `new X($p)` with constructor; `new X($p)` without constructor → External;
  named-arg binding (`argKey 'cfg'` → param `cfg`); positional past variadic binds
  to variadic; positional arg beyond the last param → Truncated.
- [x] **Step 2:** red. **Step 3:** implement (`CallResolver` dispatches per kind;
  `ReceiverTyper` owns hint→FQCN; both stay PHPMD-clean — extract, don't nest).
- [x] **Step 4:** green + check. **Step 5:** commit `feat(#3): call resolver`.

### Task 4: `storedOnly` on `ParamInfo`

Terminal kind `stored` ("the constructor merely stores it") needs one extra
classifier fact: fate is `Used` *and* every use is a property assignment
`$this->x = $p` — or the param is constructor-promoted.

**Files:** modify `src/Index/ParamInfo.php` (add
`public readonly bool $storedOnly = false` as last constructor arg),
`src/Index/UsageClassifier.php`; test `tests/Index/UsageClassifierTest.php`.

- [x] **Step 1:** failing tests: `$this->x = $p;` → `Used` + `storedOnly` true;
  promoted `private Cfg $p` → true; `$this->x = $p; log($p->env);` → false;
  `other($p);` (PureForward) → false; plain `log($p);` → false.
- [x] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [x] **Step 5:** commit `feat(#3): classify stores-only parameter usage`.

### Task 5: `Chain/` value objects + `ChainBuilder`

**Files:** create the four `src/Chain/` files;
test `tests/Chain/ChainBuilderTest.php`.

**Produces:**

```php
enum TerminalKind: string
{
    case Used = 'used';
    case Stored = 'stored';
    case ByRef = '&-terminated';
    case Unused = 'unused-end';
    case External = 'external';
    case Truncated = 'truncated';
}

final class Hop
{
    public function __construct(
        public readonly string $fqmn,
        public readonly ?string $class,
        public readonly string $file,
        public readonly int $line,
        /** Line of the forwarding call site; null on the terminal node. */
        public readonly ?int $forwardLine,
    ) {}
}

final class Finding
{
    /**
     * @param list<Hop> $chain hops in order; includes the terminal node only when
     *                         terminalKind is used|stored|&-terminated|unused-end
     * @param list<string> $notes  human-readable, e.g. "interface X: 2 implementations, chain truncated"
     * @param list<string> $trace  per-edge resolution trace, rendered only by --explain
     */
    public function __construct(
        public readonly string $param,
        public readonly string $origin,
        public readonly ?string $terminal,
        public readonly TerminalKind $terminalKind,
        public readonly int $hops,
        public readonly array $chain,
        public readonly int $classes,
        public readonly array $notes,
        public readonly array $trace,
    ) {}
}

final class ChainBuilder
{
    public function __construct(private readonly CallResolver $resolver) {}

    /** @return list<Finding> every maximal chain, unfiltered (thresholding is the caller's job) */
    public function build(MethodIndex $index): array;
}
```

Behavior = the normative ChainBuilder steps 1–5 in `docs/plan.md` Phase 2.3.
Bindings and determinism decisions:

- Nodes are `(fqmn, paramName)` for params with fate `PureForward`. Edges follow
  `ResolvedTarget`s; `External`/`Truncated` resolutions and child fates
  `Used`/`ByRefTerminated`/`Unused` end a path.
- `terminalKind` mapping: child `Used` + `storedOnly` → `Stored`; child `Used` →
  `Used`; `ByRefTerminated` → `ByRef`; `Unused` → `Unused`; `ExternalTarget` →
  `External` (terminal null); `TruncatedResolution` → `Truncated` (terminal null,
  reason appended to notes as `truncated: <reason>`).
- `hops` = pure-forward nodes on the path. `classes` = distinct non-null `Hop->class`
  values incl. terminal.
- Determinism: iterate `MethodIndex::all()` in insertion order (files are sorted by
  FileLocator); explore a node's forwards in source order; findings sorted by
  (origin file, origin line, param name) before returning.
- Every edge appends one `trace` line:
  `"<callerFqmn>: <kind>:<name> -> <targetFqmn>"` (or `-> external: <detail>` /
  `-> truncated: <reason>`).

- [x] **Step 1:** failing tests (temp-file helper; assert against `Finding` fields):
  the 3-hop README chain (origin/terminal/hops=3/classes=4/`Stored`); use at hop 2
  splits the chain (finding of 1 hop ending `Used`); branch to two callees → two
  findings sharing the origin; by-ref callee → `ByRef`; callee never touching the
  param → `Unused`; forward into `sprintf` → `External`, terminal null; two impls →
  `Truncated` + note; direct recursion `a($p)` calls `a($p)` → one finding with
  `(recursive)` note, terminates; entry-less cycle (a↔b, no other caller) → still
  reported once with `(recursive)`; hops ≥ limit is NOT applied here (a 1-hop chain
  is returned).
- [x] **Step 2:** red. **Step 3:** implement (DFS with the current path as visited
  set; keep it PHPMD-clean — the walk, terminal mapping, and finding assembly are
  separate methods or a small helper class).
- [x] **Step 4:** green + check. **Step 5:** commit `feat(#3): chain builder`.

### Task 6: `TextReporter`, default pipeline, exit codes

**Files:** create `src/Report/TextReporter.php`; modify
`src/Console/Application.php`; tests `tests/Report/TextReporterTest.php`,
`tests/Console/ApplicationTest.php` (extend).

**Output contract (pin byte-for-byte in the test).** Hop labels: the origin line is
labeled `origin`, subsequent pure-forward nodes `hop 2`, `hop 3`, … — the origin *is*
hop 1, and the summary line counts it:

```
FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)   src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)    src/Demo.php:18
  hop 3     Demo\ServiceB::run($config)        src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)  src/Demo.php:32   (stored)

1 finding (limit: 3 hops).
```

Columns are two-space separated with the method column padded to the longest entry
per finding. Chains ending in `External`/`Truncated` have no `terminal` line; their
notes render as `  note      truncated: 2 implementations` after the last hop.
No findings → `No tramp data found (limit: 3 hops).` and exit 0.

`Application`: the default invocation (paths given, no `--dump-index`) runs
FileLocator → Indexer → ClassHierarchy/CallResolver → ChainBuilder, filters
`hops >= limit`, renders, and returns 1 if anything was reported, else 0. The
Phase 1 "not implemented" branch dies. Formats other than `text` return exit 2 with
`format not implemented until Phase 3`.

- [x] **Step 1:** failing reporter test (exact-string, both finding shapes and the
  empty case) + Application tests: 3-hop fixture code → exit 1 and output contains
  `FINDING`; same code with `--limit 4` → exit 0 and `No tramp data found`;
  `--format json` → exit 2.
- [x] **Step 2:** red. **Step 3:** implement. **Step 4:** green + check.
- [x] **Step 5:** commit `feat(#3): text reporter, default pipeline, exit codes`.

### Task 7: `--explain`

**Files:** modify `src/Report/TextReporter.php`, `src/Console/Application.php`;
tests extend `tests/Report/TextReporterTest.php`.

With `Options->explain`, each finding is followed by its `trace` lines indented
under an `explain:` header; without the flag, output is unchanged (pin both).

- [x] Steps: failing test → red → implement → green + check →
  commit `feat(#3): --explain resolution traces`.

### Task 8: End-to-end fixtures, harness extension, dogfood

**Files:** modify `tests/FixtureTest.php`; create fixture dirs; modify
`.github/workflows/ci.yml`, `README.md`, `docs/plan.md` (status), `CLAUDE.md`
(only if a convention changed — otherwise leave it).

**Harness:** alongside `expected-index.json`, a fixture may carry
`expected-findings.json`. For those, `FixtureTest` runs Indexer + ChainBuilder
directly (the CLI JSON format is Phase 3; the harness switches to
`--format=json` then) and compares this normalized shape, chain as flat FQMNs:

```json
{
    "findings": [
        {
            "param": "config",
            "origin": "Demo\\Controller::handle",
            "terminal": "Demo\\Mailer::__construct",
            "terminalKind": "stored",
            "hops": 3,
            "chain": ["Demo\\Controller::handle", "Demo\\ServiceA::process", "Demo\\ServiceB::run", "Demo\\Mailer::__construct"],
            "classes": 4,
            "notes": []
        }
    ]
}
```

**Fixtures** (each pins one frozen-semantics rule end-to-end):
`classifier-smoke` gains `expected-findings.json` (above); new dirs
`use-breaks-chain`, `interface-single-impl`, `interface-multi-impl` (truncation +
note), `variadic-decorator` (a `...$args` proxy chain — the classic worst offender),
`recursion`, `static-function-mix` (function → static → method in one chain).

**Dogfood:** a step in the `static` CI job after PHPMD:
`bin/phptramp --folder src --limit 99` — report-only (limit 99 cannot fail) until
the codebase is chain-clean, then Phase 6 lowers it to a real gate. Update
`README.md` status ("Phase 2: chain reporting works") and check off Phase 2 in
`docs/plan.md`.

- [x] Steps: harness extension test-first (red on the first new fixture) → fixtures
  one by one → green + check → commit `test(#3): end-to-end chain fixtures` →
  CI/docs edits → commit `ci(#3): dogfood phptramp on itself (report-only)`.

---

## Done when

1. Every task committed on `feature/3-chain-stitching`; `composer check` green at
   every commit; `composer infection:diff` ≥ 80 MSI locally.
2. All Phase 2 bullets in `docs/plan.md` check off; README/help text updated
   (help already documents the flags — verify wording still matches behavior).
3. PR into `develop` with `Closes #3`; CI (matrix + static + mutation) green;
   after merge, verify issue #3 auto-closed.
