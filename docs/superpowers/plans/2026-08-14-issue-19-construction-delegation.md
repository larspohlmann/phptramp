# Construction/Delegation Is Not Tramp Data — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop phptramp scoring `parent::` delegation as tramp data, and add an opt-in `--exclude-terminal` filter, so idiomatic object construction and delegation stop crowding out real findings ([issue #19](https://github.com/larspohlmann/phptramp/issues/19)).

**Architecture:** Three layers, in order. (1) `Finding::$hops` is currently overloaded as both the hop *score* and the *index* of the terminal node in `chain`; five reporters depend on that identity, so it must be cut before the score can change. (2) A `Hop` that forwards through `parent::` is marked and stops counting toward `hops` and `classes` — same object, not a collaborator. (3) A new `TerminalKindFilter` drops findings whose terminal kind the user excluded; default excludes nothing.

**Tech Stack:** PHP ≥ 8.2, `nikic/php-parser ^5` (the only production dependency), PHPUnit, PHPCS (PSR-12), PHPStan level max, PHPMD codesize, Infection.

**Design spec:** [docs/superpowers/specs/2026-08-14-issue-19-construction-delegation-design.md](../specs/2026-08-14-issue-19-construction-delegation-design.md)

**Branch:** `feature/19-construction-delegation` (already created, off `develop`).

## Global Constraints

- `declare(strict_types=1);` in every PHP file under `src/` and `tests/`.
- House style: `final class` with `readonly` promoted constructor properties; prefer new instances over setters.
- Conventional commits with the issue number in the type: `feat(#19): title`, `refactor(#19): title`, `test(#19): title`, `docs(#19): title`.
- **One task per commit.** Never batch tasks.
- `composer check` (cs + stan + md + test) must be green before every commit.
- **Every `src/` file you touch must be PHPMD-clean**, not merely free of new findings. Fix the design the metric points at; never tune the threshold.
- No second production dependency. No auto-fix. Both are documented non-goals.
- Comments explain *why*, never *what*. Match the density and tone of the existing comments — they justify defensive branches and record frozen-semantics decisions.
- Fixture inputs under `tests/fixtures/*/src/` are analyzer *input data* and are excluded from PSR-12; write them in ordinary readable PHP but do not worry about the linter there.
- Before opening the PR: `git fetch origin main && composer infection:diff` must clear `minMsi 80`.

## Deviation from the spec (deliberate, already reflected below)

The spec says terminal-kind names are validated "at both entry points"
(`ArgvParser` and `ConfigLoader`), each throwing its own exception type.
Implementing it that way would put the same name→`TerminalKind` mapping in
three places. Instead, validation lives **once**, in
`TerminalKindFilter::fromNames()`, which `Application::analyze()` calls
*before* `buildIndex()` — so a bad value still fails fast, before the
expensive work, with exit code 2. Task 4 amends the spec file to match.

## File Structure

**Created**

| Path | Responsibility |
|---|---|
| `src/Chain/TerminalKindFilter.php` | Drops findings whose `TerminalKind` the user excluded; owns the name→enum mapping and its validation |
| `tests/Chain/FindingTest.php` | Unit tests for `Finding::hasTerminalNode()` / `forwardingHops()` |
| `tests/Chain/TerminalKindTest.php` | Unit tests for `TerminalKind::keepsTerminalNode()` |
| `tests/Chain/TerminalKindFilterTest.php` | Unit tests for the filter and its name validation |
| `tests/fixtures/parent-delegation-hierarchy/` | Three-deep exception hierarchy — expects no findings |
| `tests/fixtures/parent-delegation-mixed/` | Subclass → parent → collaborator — parent hop discounted, collaborator hop counted |
| `tests/fixtures/exclude-terminal-stored/` | One `stored` and one `used` chain, `--exclude-terminal stored` — only the `used` one survives |

**Modified**

| Path | Change |
|---|---|
| `src/Chain/TerminalKind.php` | `keepsTerminalNode()` |
| `src/Chain/Finding.php` | `hasTerminalNode()`, `forwardingHops()` |
| `src/Chain/Hop.php` | `readonly bool $viaParent`; carried through `withChanged()` |
| `src/Chain/ChainTraversal.php` | Mark `viaParent` hops; score `hops`/`classes` around them |
| `src/Report/TextReporter.php` | Terminal node from `hasTerminalNode()`; `(parent)` annotation |
| `src/Report/PrettyReporter.php` | Same, plus drop the `str_starts_with('(')` annotation-styling sniff |
| `src/Report/JsonReporter.php` | Same, plus `"viaParent": true` when set |
| `src/Report/GithubReporter.php` | `forwardingHops()` instead of `array_slice(..., $hops, true)` |
| `src/Report/SarifReporter.php` | `forwardingHops()` instead of `for (... < $hops)` |
| `src/Console/Options.php` | `list<string> $excludeTerminals`, incl. `withFormat()` |
| `src/Console/ArgvParser.php` | Repeatable `--exclude-terminal` |
| `src/Config/ConfigLoader.php` | `excludeTerminals` config key |
| `src/Console/Application.php` | Wire the filter; help text |
| `docs/plan.md`, `README.md` | Frozen rule 4, appendix rationale, options docs |

---

## Task 1: Cut the `hops`-as-index coupling

Pure refactor. No user-visible output changes anywhere — every existing test must stay green byte-for-byte. This exists so Task 2 can change what `hops` means without breaking five reporters.

**Files:**
- Modify: `src/Chain/TerminalKind.php`
- Modify: `src/Chain/Finding.php`
- Modify: `src/Report/TextReporter.php:107-123`
- Modify: `src/Report/PrettyReporter.php:178-194`
- Modify: `src/Report/JsonReporter.php:78-92`
- Modify: `src/Report/GithubReporter.php:122-131`
- Modify: `src/Report/SarifReporter.php:105-127`
- Test: `tests/Chain/TerminalKindTest.php` (create), `tests/Chain/FindingTest.php` (create)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `TerminalKind::keepsTerminalNode(): bool`; `Finding::hasTerminalNode(): bool`; `Finding::forwardingHops(): list<Hop>`. Tasks 2 and 3 rely on all three.

- [ ] **Step 1: Write the failing tests**

Create `tests/Chain/TerminalKindTest.php`:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TerminalKindTest extends TestCase
{
    /**
     * @return iterable<string, array{0: TerminalKind, 1: bool}>
     */
    public static function terminalKindProvider(): iterable
    {
        yield 'used keeps its node' => [TerminalKind::Used, true];
        yield 'stored keeps its node' => [TerminalKind::Stored, true];
        yield 'by-ref keeps its node' => [TerminalKind::ByRef, true];
        yield 'unused-end keeps its node' => [TerminalKind::Unused, true];
        yield 'external has no node' => [TerminalKind::External, false];
        yield 'truncated has no node' => [TerminalKind::Truncated, false];
    }

    #[DataProvider('terminalKindProvider')]
    public function testKeepsTerminalNode(TerminalKind $kind, bool $expected): void
    {
        self::assertSame($expected, $kind->keepsTerminalNode());
    }
}
```

Create `tests/Chain/FindingTest.php`:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PHPUnit\Framework\TestCase;

final class FindingTest extends TestCase
{
    public function testStoredChainKeepsItsTerminalNodeOutOfTheForwardingHops(): void
    {
        $finding = $this->finding(TerminalKind::Stored, ['Demo\A::go', 'Demo\B::step', 'Demo\C::__construct']);

        self::assertTrue($finding->hasTerminalNode());
        self::assertSame(
            ['Demo\A::go', 'Demo\B::step'],
            array_map(static fn (Hop $hop): string => $hop->fqmn, $finding->forwardingHops()),
        );
    }

    public function testTruncatedChainHasNoTerminalNodeSoEveryEntryForwards(): void
    {
        $finding = $this->finding(TerminalKind::Truncated, ['Demo\A::go', 'Demo\B::step']);

        self::assertFalse($finding->hasTerminalNode());
        self::assertSame(
            ['Demo\A::go', 'Demo\B::step'],
            array_map(static fn (Hop $hop): string => $hop->fqmn, $finding->forwardingHops()),
        );
    }

    /**
     * @param list<string> $fqmns
     */
    private function finding(TerminalKind $kind, array $fqmns): Finding
    {
        $chain = array_map(
            static fn (string $fqmn): Hop => new Hop($fqmn, 'Demo\Klass', '/tmp/Demo.php', 1, 2),
            $fqmns,
        );

        return new Finding('config', $fqmns[0], null, $kind, count($chain), $chain, 1, [], []);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Chain/TerminalKindTest.php tests/Chain/FindingTest.php
```

Expected: FAIL — `Call to undefined method PhpTramp\Chain\TerminalKind::keepsTerminalNode()` and `...Finding::hasTerminalNode()`.

- [ ] **Step 3: Add the two predicates**

In `src/Chain/TerminalKind.php`, inside the enum:

```php
    /**
     * Whether a chain ending this way keeps its terminal node in `Finding::$chain`.
     * `external`/`truncated` left analyzed code, so there is no node to show.
     * This is the executable form of the rule the class docblock states in prose;
     * reporters ask here instead of comparing chain length against the hop score,
     * which counts and no longer indexes.
     */
    public function keepsTerminalNode(): bool
    {
        return match ($this) {
            self::Used, self::Stored, self::ByRef, self::Unused => true,
            self::External, self::Truncated => false,
        };
    }
```

In `src/Chain/Finding.php`, after `withChain()`:

```php
    /** The terminal node, when the chain has one, is always the last entry. */
    public function hasTerminalNode(): bool
    {
        return $this->terminalKind->keepsTerminalNode();
    }

    /**
     * The chain minus its terminal node — every node that forwards the value on.
     *
     * @return list<Hop>
     */
    public function forwardingHops(): array
    {
        return $this->hasTerminalNode() ? array_slice($this->chain, 0, -1) : $this->chain;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
vendor/bin/phpunit tests/Chain/TerminalKindTest.php tests/Chain/FindingTest.php
```

Expected: PASS (8 tests).

- [ ] **Step 5: Switch the three chain-rendering reporters over**

`TextReporter::hopRows()` — replace the first two lines of the method body and the loop condition:

```php
    private function hopRows(Finding $finding): array
    {
        $terminalIndex = $finding->hasTerminalNode() ? count($finding->chain) - 1 : null;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $index === $terminalIndex;
            $rows[] = [
                'label' => $this->label($index, $isTerminal),
                'method' => $hop->fqmn . '($' . $finding->param . ')',
                'location' => $this->location($hop),
                'annotation' => $this->annotation($hop, $finding->terminalKind, $isTerminal),
            ];
        }

        return $rows;
    }
```

`PrettyReporter::hopRows()` — the identical two-line change; keep its own row shape (`fqmn`, `param` are separate fields there):

```php
        $terminalIndex = $finding->hasTerminalNode() ? count($finding->chain) - 1 : null;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $index === $terminalIndex;
```

`JsonReporter::chainDocument()`:

```php
    private function chainDocument(Finding $finding): array
    {
        $terminalIndex = $finding->hasTerminalNode() ? count($finding->chain) - 1 : null;

        $chain = [];
        foreach ($finding->chain as $index => $hop) {
            $chain[] = $this->hopDocument($hop, $index, $index === $terminalIndex);
        }

        return $chain;
    }
```

- [ ] **Step 6: Switch the two location-list reporters over**

`GithubReporter::nonTerminalHops()` — replace the body and update the docblock, which currently claims indices `0 .. hops-1`:

```php
    /**
     * @return array<int, Hop> the forwarding-node entries keyed by their chain
     *                         index; the terminal node, when the chain has one,
     *                         is never included
     */
    private function nonTerminalHops(Finding $finding): array
    {
        return $finding->forwardingHops();
    }
```

`SarifReporter::relatedLocations()` — replace the `for` loop with a `foreach` that skips the origin, and fix the docblock's `1 .. hops-1` claim:

```php
    /**
     * @return list<array<string, mixed>> one entry per forwarding node after the
     *                                     origin; the terminal node is never
     *                                     included
     */
    private function relatedLocations(Finding $finding): array
    {
        $locations = [];
        foreach ($finding->forwardingHops() as $index => $hop) {
            if ($index === 0) {
                continue;
            }
            $locations[] = [
                'physicalLocation' => [
                    'artifactLocation' => ['uri' => $this->paths->relativize($hop->file)],
                    'region' => ['startLine' => $hop->forwardLine ?? $hop->line],
                ],
                'message' => [
                    'text' => 'hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin,
                ],
            ];
        }

        return $locations;
    }
```

- [ ] **Step 7: Verify the refactor changed no output**

```bash
composer check
```

Expected: PASS, all green. Every reporter test and every fixture asserts byte-for-byte output; if any of them moved, the refactor is wrong — do not update an expectation to match.

- [ ] **Step 8: Commit**

```bash
git add src/Chain/TerminalKind.php src/Chain/Finding.php src/Report/ tests/Chain/TerminalKindTest.php tests/Chain/FindingTest.php
git commit -m "refactor(#19): derive the terminal node from TerminalKind, not the hop count

Finding::\$hops doubled as the hop score and as the index of the terminal node
in \$chain; five reporters read it as an index. Issue #19 makes the score stop
counting parent:: delegation, which would silently break all five. Ask the
terminal kind whether a terminal node exists instead — the rule its docblock
already stated in prose."
```

---

## Task 2: `parent::` delegation counts as neither hop nor class

**Files:**
- Modify: `src/Chain/Hop.php`
- Modify: `src/Chain/ChainTraversal.php:105-124` (`walk`), `:199-229` (`record`, `distinctClasses`)
- Test: `tests/Chain/ChainBuilderTest.php`
- Test: `tests/fixtures/parent-delegation-hierarchy/`, `tests/fixtures/parent-delegation-mixed/`

**Interfaces:**
- Consumes: nothing from Task 1 (independent), but Task 1 must land first or the reporters break.
- Produces: `Hop::$viaParent` (`readonly bool`, last constructor parameter, defaults to `false`). Task 3 renders it.

- [ ] **Step 1: Write the failing unit tests**

Append to `tests/Chain/ChainBuilderTest.php`:

```php
    public function testParentDelegationScoresNeitherAHopNorAClass(): void
    {
        $code = '<?php namespace Demo; '
            . 'class Base { private ?\Throwable $p = null; '
            . 'public function __construct(?\Throwable $previous = null) { $this->p = $previous; } } '
            . 'class Sub extends Base { '
            . 'public function __construct(?\Throwable $previous = null) { parent::__construct($previous); } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);

        $finding = $findings[0];
        self::assertSame('Demo\Sub::__construct', $finding->origin);
        self::assertSame('Demo\Base::__construct', $finding->terminal);
        self::assertSame(TerminalKind::Stored, $finding->terminalKind);
        self::assertSame(0, $finding->hops);
        self::assertSame(1, $finding->classes);
        self::assertTrue($finding->chain[0]->viaParent);
        self::assertFalse($finding->chain[1]->viaParent);
    }

    public function testCollaboratorHopAfterParentDelegationStillScores(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Base { public function __construct(Cfg $config) { (new Mailer())->send($config); } } '
            . 'class Sub extends Base { public function __construct(Cfg $config) { parent::__construct($config); } } '
            . 'class Mailer { private ?Cfg $c = null; '
            . 'public function send(Cfg $config): void { $this->c = $config; } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);

        $finding = $findings[0];
        self::assertSame('Demo\Sub::__construct', $finding->origin);
        self::assertSame('Demo\Mailer::send', $finding->terminal);
        self::assertSame(1, $finding->hops);
        self::assertSame(2, $finding->classes);
        self::assertCount(3, $finding->chain);
    }

    public function testSelfDelegationIsAnOrdinaryHop(): void
    {
        $code = '<?php namespace Demo; class Cfg {} '
            . 'class Service { '
            . 'public function handle(Cfg $config): void { self::store($config); } '
            . 'private static function store(Cfg $config): void { (new Sink())->keep($config); } } '
            . 'class Sink { private ?Cfg $c = null; '
            . 'public function keep(Cfg $config): void { $this->c = $config; } }';

        $findings = $this->build($code);
        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->hops);
        self::assertSame(2, $findings[0]->classes);
    }
```

The third test pins the boundary: `self::` is the *same class*, not a base — it stays an ordinary hop. Only `parent::` is discounted.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Chain/ChainBuilderTest.php --filter 'ParentDelegation|CollaboratorHopAfter|SelfDelegation'
```

Expected: the first two FAIL — `viaParent` is undefined on `Hop`, and (once that is added) `hops` is `1`/`2` and `classes` `2`/`3` instead of `0`/`1` and `1`/`2`. The third PASSES already; it is a regression guard.

- [ ] **Step 3: Add `Hop::$viaParent`**

In `src/Chain/Hop.php`, append a constructor parameter after `$param` and carry it through `withChanged()`:

```php
    public function __construct(
        public readonly string $fqmn,
        public readonly ?string $class,
        public readonly string $file,
        public readonly int $line,
        /** Line of the forwarding call site; null on the terminal node. */
        public readonly ?int $forwardLine,
        /** Whether this hop intersects the diff in diff-aware mode. */
        public readonly bool $changed = false,
        /** This hop's own parameter name; used for per-hop suppression lookup. */
        public readonly string $param = '',
        /**
         * Whether this node forwards on through a `parent::` call — delegation
         * to the same object's base, not a hand-off to a collaborator, so it
         * scores neither a hop nor a class (frozen rule 4).
         */
        public readonly bool $viaParent = false,
    ) {
    }
```

```php
    public function withChanged(bool $changed): self
    {
        return new self(
            $this->fqmn,
            $this->class,
            $this->file,
            $this->line,
            $this->forwardLine,
            $changed,
            $this->param,
            $this->viaParent,
        );
    }
```

Also extend the class docblock's closing sentence to mention the new field, matching the existing style.

- [ ] **Step 4: Mark and discount in `ChainTraversal`**

Add `use PhpTramp\Index\CalleeKind;` to the import block. In `walk()`, pass the new flag:

```php
            $hop = new Hop(
                $method->fqmn,
                $method->class,
                $method->file,
                $method->line,
                $forward->line,
                false,
                $param->name,
                $this->isParentDelegation($forward),
            );
```

Add the predicate next to `walk()`:

```php
    /**
     * A `parent::` call hands the value to the same object's base class, not to
     * a collaborator. Detected syntactically: php-parser's NameResolver leaves
     * `parent` unresolved as a special class name, which is also why
     * CallResolver matches the literal string.
     */
    private function isParentDelegation(ForwardSite $forward): bool
    {
        return $forward->callee->kind === CalleeKind::StaticCall
            && $forward->callee->receiverHint === 'parent';
    }
```

In `record()`, replace `count($chain->hops)` with the scored count:

```php
        $this->findings[] = new Finding(
            $chain->originParam,
            $chain->hops[0]->fqmn,
            $terminal->fqmn,
            $terminal->kind,
            $this->scoredHops($chain->hops),
            $fullChain,
            $this->distinctClasses($fullChain),
            $terminal->notes,
            $chain->trace,
        );
```

Add `scoredHops()` and amend `distinctClasses()`:

```php
    /**
     * The hop score: forwarding nodes that handed the value to a collaborator.
     * `parent::` delegators stay in the rendered chain but never score — they
     * are the same object as the node they delegate into.
     *
     * @param list<Hop> $hops
     */
    private function scoredHops(array $hops): int
    {
        $scored = 0;
        foreach ($hops as $hop) {
            if (! $hop->viaParent) {
                $scored++;
            }
        }

        return $scored;
    }

    /**
     * @param list<Hop> $chain
     */
    private function distinctClasses(array $chain): int
    {
        $classes = [];
        foreach ($chain as $hop) {
            if ($hop->class !== null && ! $hop->viaParent) {
                $classes[$hop->class] = true;
            }
        }

        return count($classes);
    }
```

Graph traversal, origin selection, cycle detection and the baseline fingerprint are all deliberately untouched — only scoring changes.

- [ ] **Step 5: Run the unit tests to verify they pass**

```bash
vendor/bin/phpunit tests/Chain/ChainBuilderTest.php
```

Expected: PASS, including the pre-existing `testReadmeThreeHopChain` (3 hops / 4 classes — no `parent::` anywhere, so it must not move).

- [ ] **Step 6: Add the two fixtures**

Create `tests/fixtures/parent-delegation-hierarchy/src/Demo.php`:

```php
<?php

namespace Demo;

class BaseException extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

class MiddleException extends BaseException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct($detail, $previous);
    }
}

class SpecificException extends MiddleException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('specific failure', $previous);
    }
}
```

Create `tests/fixtures/parent-delegation-hierarchy/expected-findings.json`:

```json
{
    "limit": 1,
    "warnLimit": null,
    "minClasses": 0,
    "findings": []
}
```

No `phptramp-args.json`, so `FixtureTest` runs the default `--limit 1 --warn-limit 0` — the tightest setting the harness offers. Every chain here is pure `parent::` delegation, so every one scores 0 hops and is unreportable at any positive limit. This is the shape from the issue: one finding per custom exception, gone.

Create `tests/fixtures/parent-delegation-mixed/src/Demo.php` — copy verbatim, the expectation below pins its line numbers:

```php
<?php

namespace Demo;

class Cfg
{
}

class BaseHandler
{
    public function __construct(Cfg $config)
    {
        (new Mailer())->send($config);
    }
}

class SpecificHandler extends BaseHandler
{
    public function __construct(Cfg $config)
    {
        parent::__construct($config);
    }
}

class Mailer
{
    private ?Cfg $config = null;

    public function send(Cfg $config): void
    {
        $this->config = $config;
    }
}
```

Create `tests/fixtures/parent-delegation-mixed/expected-findings.json`:

```json
{
    "limit": 1,
    "warnLimit": null,
    "minClasses": 0,
    "findings": [
        {
            "param": "config",
            "severity": "error",
            "origin": "Demo\\SpecificHandler::__construct",
            "terminal": "Demo\\Mailer::send",
            "terminalKind": "stored",
            "hops": 1,
            "classes": 2,
            "chain": [
                {"method": "Demo\\SpecificHandler::__construct", "role": "origin", "file": "src/Demo.php", "line": 19, "forwardLine": 21},
                {"method": "Demo\\BaseHandler::__construct", "role": "hop", "file": "src/Demo.php", "line": 11, "forwardLine": 13},
                {"method": "Demo\\Mailer::send", "role": "terminal", "file": "src/Demo.php", "line": 29, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

The `viaParent` key is absent here on purpose — Task 3 adds it to the JSON output and updates this file. If the `line`/`forwardLine` values come out different, the source file above was not copied verbatim; fix the source, not the expectation.

- [ ] **Step 7: Run the fixture suite**

```bash
vendor/bin/phpunit tests/FixtureTest.php
```

Expected: PASS, including the two new cases and all 21 existing ones (none of them uses `parent::`, so none may move).

- [ ] **Step 8: Run the full gate and commit**

```bash
composer check
```

```bash
git add src/Chain/Hop.php src/Chain/ChainTraversal.php tests/Chain/ChainBuilderTest.php tests/fixtures/parent-delegation-hierarchy tests/fixtures/parent-delegation-mixed
git commit -m "feat(#19): parent:: delegation counts as neither a hop nor a class

A subclass forwarding to parent::__construct() is inheritance wiring, not a
value tramping across collaborators: subclass and base are the same object.
Every custom exception in a codebase produced a finding. The node stays in the
rendered chain, it just stops scoring. self::/static:: are unaffected — same
class, ordinary hop. Amends frozen rule 4."
```

---

## Task 3: Render the delegating node

**Files:**
- Modify: `src/Report/TextReporter.php:125-135` (`annotation`)
- Modify: `src/Report/PrettyReporter.php:197-207` (`annotation`), `:232-245` (`hopLine`)
- Modify: `src/Report/JsonReporter.php:94-110` (`hopDocument`)
- Modify: `tests/fixtures/parent-delegation-mixed/expected-findings.json`
- Test: `tests/Report/TextReporterTest.php`, `tests/Report/PrettyReporterTest.php`, `tests/Report/JsonReporterTest.php`

**Interfaces:**
- Consumes: `Hop::$viaParent` from Task 2.
- Produces: no new API. Text/pretty gain a `(parent)` annotation; JSON gains a `"viaParent": true` key present only when true.

- [ ] **Step 1: Write the failing reporter tests**

All three reporter test files pin their reporter's **entire** output with `assertSame` against a nowdoc — follow that, do not weaken the new tests to `assertStringContainsString`.

Add to `tests/Report/TextReporterTest.php`:

```php
    public function testParentDelegatingHopIsAnnotated(): void
    {
        $chain = [
            new Hop('Demo\Sub::__construct', 'Demo\Sub', 'src/Demo.php', 19, 21, false, 'config', true),
            new Hop('Demo\Base::__construct', 'Demo\Base', 'src/Demo.php', 11, 13, false, 'config'),
            new Hop('Demo\Mailer::send', 'Demo\Mailer', 'src/Demo.php', 29, null, false, 'config'),
        ];
        $finding = new Finding(
            'config',
            'Demo\Sub::__construct',
            'Demo\Mailer::send',
            TerminalKind::Stored,
            1,
            $chain,
            2,
            [],
            [],
        );

        $expected = <<<'TXT'
            FINDING  $config: 1 pass-through hop across 2 classes
              origin    Demo\Sub::__construct($config)    src/Demo.php:19→21  (parent)
              hop 2     Demo\Base::__construct($config)   src/Demo.php:11→13
              terminal  Demo\Mailer::send($config)        src/Demo.php:29  (stored)

            1 finding (limit: 1 hop).

            TXT;

        $reporter = new TextReporter(new Thresholds(1, null), new Paths('/nonexistent-root'));

        self::assertSame($expected, $reporter->render([$finding]));
    }
```

The chain renders three nodes but scores one hop — that gap is exactly what `(parent)` explains, so pinning the whole block is the point of the test. If it fails on column padding alone, the arithmetic in this nowdoc is wrong, not the reporter: `methodWidth` is the longest method entry (`Demo\Base::__construct($config)`, 31 chars) and every method field is padded to it before the two-space gap. Fix the nowdoc.

Add to `tests/Report/PrettyReporterTest.php` the equivalent, styled: `(parent)` goes through `Styler::terminalKind()`, so it carries the same escape sequence that file already pins for `(stored)`. Copy that sequence from the existing assertions rather than writing escapes by hand.

Add to `tests/Report/JsonReporterTest.php` a test on the same three-node chain, pinning the full document and asserting that the first chain entry carries `"viaParent": true` while the other two entries have no `viaParent` key at all.

- [ ] **Step 2: Run the tests to verify they fail**

```bash
vendor/bin/phpunit tests/Report/
```

Expected: the three new tests FAIL — no `(parent)` in the text/pretty output, no `viaParent` key in the JSON.

- [ ] **Step 3: Annotate in `TextReporter`**

```php
    private function annotation(Hop $hop, TerminalKind $terminalKind, bool $isTerminal): string
    {
        if ($isTerminal) {
            return '(' . $terminalKind->value . ')';
        }

        $parts = [];
        if ($hop->viaParent) {
            $parts[] = '(parent)';
        }
        if ($hop->changed) {
            $parts[] = '*YOURS*';
        }

        return implode(' ', $parts);
    }
```

- [ ] **Step 4: Annotate in `PrettyReporter` and drop the styling sniff**

`PrettyReporter::annotation()` currently returns an unstyled string that `hopLine()` then styles by sniffing a leading `(`. A hop can now carry both `(parent)` and `*YOURS*`, which that sniff would style wrongly, so styling moves into `annotation()`:

```php
    private function annotation(Hop $hop, TerminalKind $terminalKind, bool $isTerminal): string
    {
        if ($isTerminal) {
            return $this->styler->terminalKind('(' . $terminalKind->value . ')');
        }

        $parts = [];
        if ($hop->viaParent) {
            $parts[] = $this->styler->terminalKind('(parent)');
        }
        if ($hop->changed) {
            $parts[] = $this->styler->annotation('*YOURS*');
        }

        return implode(' ', $parts);
    }
```

and in `hopLine()` replace the sniffing branch with a plain append:

```php
        if ($row['annotation'] !== '') {
            $rest .= self::COLUMN_GAP . $row['annotation'];
        }
```

The annotation is not measured for column widths, so pre-styling it changes no alignment.

- [ ] **Step 5: Emit `viaParent` in `JsonReporter`**

```php
    private function hopDocument(Hop $hop, int $index, bool $isTerminal): array
    {
        $document = [
            'method' => $hop->fqmn,
            'role' => $this->role($index, $isTerminal),
            'file' => $this->paths->relativize($hop->file),
            'line' => $hop->line,
            'forwardLine' => $hop->forwardLine,
        ];
        if ($this->changedOnly) {
            $document['changed'] = $hop->changed;
        }
        // Emitted only when set: a key on every entry of every chain would be
        // noise for the common case, and `changed` already sets that precedent.
        if ($hop->viaParent) {
            $document['viaParent'] = true;
        }

        return $document;
    }
```

- [ ] **Step 6: Update the mixed fixture's expectation**

In `tests/fixtures/parent-delegation-mixed/expected-findings.json`, add `"viaParent": true` to the first chain entry only:

```json
                {"method": "Demo\\SpecificHandler::__construct", "role": "origin", "file": "src/Demo.php", "line": 19, "forwardLine": 21, "viaParent": true},
```

- [ ] **Step 7: Run the full gate**

```bash
composer check
```

Expected: PASS. No existing reporter expectation may move — every fixture and reporter test today has `viaParent: false` on every hop.

- [ ] **Step 8: Commit**

```bash
git add src/Report/ tests/Report/ tests/fixtures/parent-delegation-mixed
git commit -m "feat(#19): mark parent-delegating hops in text, pretty and json output

The node stays in the chain and stops scoring, so the reader needs to see why
the hop count is lower than the chain is long. Pretty's annotation styling
moves into annotation() — a hop can now carry both (parent) and *YOURS*, which
the leading-paren sniff in hopLine() would have styled wrongly."
```

---

## Task 4: `--exclude-terminal` / `excludeTerminals`

**Files:**
- Create: `src/Chain/TerminalKindFilter.php`
- Create: `tests/Chain/TerminalKindFilterTest.php`
- Create: `tests/fixtures/exclude-terminal-stored/`
- Modify: `src/Console/Options.php`, `src/Console/ArgvParser.php`, `src/Config/ConfigLoader.php`, `src/Console/Application.php`
- Modify: `docs/superpowers/specs/2026-08-14-issue-19-construction-delegation-design.md` (validation section)
- Test: `tests/Console/ArgvParserTest.php`, `tests/Console/OptionsTest.php`, `tests/Config/ConfigLoaderTest.php`

**Interfaces:**
- Consumes: `TerminalKind` (unchanged), `Finding::$terminalKind`.
- Produces: `TerminalKindFilter::fromNames(list<string> $names): self` (throws `InvalidArgsException`), `TerminalKindFilter::filter(list<Finding> $findings): list<Finding>`, `Options::$excludeTerminals` (`list<string>`, default `[]`).

- [ ] **Step 1: Write the failing filter test**

Create `tests/Chain/TerminalKindFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Chain;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Chain\TerminalKindFilter;
use PhpTramp\Console\InvalidArgsException;
use PHPUnit\Framework\TestCase;

final class TerminalKindFilterTest extends TestCase
{
    public function testExcludedKindIsDroppedAndEveryOtherKindSurvives(): void
    {
        $findings = [$this->finding(TerminalKind::Stored), $this->finding(TerminalKind::Used)];

        $kept = TerminalKindFilter::fromNames(['stored'])->filter($findings);

        self::assertCount(1, $kept);
        self::assertSame(TerminalKind::Used, $kept[0]->terminalKind);
    }

    public function testEmptyExclusionListKeepsEverything(): void
    {
        $findings = [$this->finding(TerminalKind::Stored), $this->finding(TerminalKind::Used)];

        self::assertSame($findings, TerminalKindFilter::fromNames([])->filter($findings));
    }

    public function testUnknownTerminalKindIsRejected(): void
    {
        $this->expectException(InvalidArgsException::class);
        $this->expectExceptionMessage('unknown terminal kind: constructed');

        TerminalKindFilter::fromNames(['constructed']);
    }

    private function finding(TerminalKind $kind): Finding
    {
        $chain = [new Hop('Demo\A::go', 'Demo\A', '/repo/src/Demo.php', 1, 2)];

        return new Finding('config', 'Demo\A::go', null, $kind, 1, $chain, 1, [], []);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
vendor/bin/phpunit tests/Chain/TerminalKindFilterTest.php
```

Expected: FAIL — `Class "PhpTramp\Chain\TerminalKindFilter" not found`.

- [ ] **Step 3: Write the filter**

Create `src/Chain/TerminalKindFilter.php`:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Chain;

use PhpTramp\Console\InvalidArgsException;

/**
 * Drops findings whose chain ends in a terminal kind the user excluded
 * (`--exclude-terminal` / the `excludeTerminals` config key). Default is to
 * exclude nothing.
 *
 * A team that tightens its thresholds may not want chains that end by handing
 * the value to a constructor or value object — the parameter has arrived at
 * its home, which is the refactoring phptramp itself recommends. That is a
 * project-level policy, not a per-site one, so it is one config line rather
 * than a #[TrampIgnore] on every factory.
 *
 * The name -> enum mapping lives here alone; Application resolves it before
 * building the index so a typo fails fast rather than after the expensive work.
 */
final class TerminalKindFilter
{
    /**
     * @param list<TerminalKind> $excluded
     */
    private function __construct(private readonly array $excluded)
    {
    }

    /**
     * @param list<string> $names TerminalKind backing values
     *
     * @throws InvalidArgsException on a name that is not a terminal kind
     */
    public static function fromNames(array $names): self
    {
        $excluded = [];
        foreach ($names as $name) {
            $kind = TerminalKind::tryFrom($name);
            if ($kind === null) {
                throw new InvalidArgsException(
                    "unknown terminal kind: {$name} (expected " . implode('|', self::kindNames()) . ')',
                );
            }
            $excluded[] = $kind;
        }

        return new self($excluded);
    }

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings): array
    {
        if ($this->excluded === []) {
            return $findings;
        }

        $kept = [];
        foreach ($findings as $finding) {
            if (! in_array($finding->terminalKind, $this->excluded, true)) {
                $kept[] = $finding;
            }
        }

        return $kept;
    }

    /**
     * @return list<string>
     */
    private static function kindNames(): array
    {
        return array_map(static fn (TerminalKind $kind): string => $kind->value, TerminalKind::cases());
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

```bash
vendor/bin/phpunit tests/Chain/TerminalKindFilterTest.php
```

Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing CLI and config tests**

Add to `tests/Console/ArgvParserTest.php`:

```php
    public function testExcludeTerminalIsRepeatable(): void
    {
        $options = (new ArgvParser())->parse(
            ['--folder', 'src', '--exclude-terminal', 'stored', '--exclude-terminal=unused-end'],
        );

        self::assertSame(['stored', 'unused-end'], $options->excludeTerminals);
    }

    public function testExcludeTerminalDefaultsToEmpty(): void
    {
        self::assertSame([], (new ArgvParser())->parse(['--folder', 'src'])->excludeTerminals);
    }
```

Add to `tests/Config/ConfigLoaderTest.php`, using that file's `writeConfig()` helper (it writes into the per-test temp directory that `setUp()` creates):

```php
    public function testReadsExcludeTerminals(): void
    {
        $this->writeConfig('phptramp.json', '{"excludeTerminals": ["stored"]}');

        $options = (new ConfigLoader())->load($this->directory);

        self::assertSame(['stored'], $options->excludeTerminals);
    }

    public function testExcludeTerminalsMustBeAListOfStrings(): void
    {
        $this->writeConfig('phptramp.json', '{"excludeTerminals": "stored"}');

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('config key "excludeTerminals" must be a list of strings');

        (new ConfigLoader())->load($this->directory);
    }
```

`tests/Console/OptionsTest.php` today has a single `testConstructorDefaults` and no coverage of `withFormat()` at all. Add the default assertion to it:

```php
        self::assertSame([], $options->excludeTerminals);
```

and add the missing `withFormat()` test, which this change makes worth having — `withFormat()` re-states all 22 constructor arguments by hand, so a forgotten one is silent data loss:

```php
    public function testWithFormatChangesOnlyTheFormat(): void
    {
        $options = new Options(
            folders: ['src'],
            exclude: ['vendor'],
            excludeTerminals: ['stored'],
        );

        $downgraded = $options->withFormat('text');

        self::assertSame('text', $downgraded->format);
        self::assertEquals($options, $downgraded->withFormat($options->format));
    }
```

- [ ] **Step 6: Run them to verify they fail**

```bash
vendor/bin/phpunit tests/Console/ tests/Config/
```

Expected: FAIL — `Options` has no `$excludeTerminals`, and `--exclude-terminal` is an unknown option.

- [ ] **Step 7: Thread the option through**

`src/Console/Options.php` — add the property after `$exclude`, and add the matching named argument to `withFormat()`:

```php
        public readonly array $exclude = [],
        /** @param list<string> $excludeTerminals TerminalKind backing values */
        public readonly array $excludeTerminals = [],
        public readonly bool $noCache = false,
```

Add `@param list<string> $excludeTerminals` to the constructor docblock alongside the existing `$exclude` entry, and `excludeTerminals: $this->excludeTerminals,` to `withFormat()`.

`src/Console/ArgvParser.php` — add `'--exclude-terminal'` to `VALUE_FLAGS`, a `private array $excludeTerminals = [];` field (with a `/** @var list<string> */` annotation, matching `$exclude`), `$this->excludeTerminals = $defaults->excludeTerminals;` in `reset()`, `excludeTerminals: $this->excludeTerminals,` in the `parse()` return, and the match arm:

```php
            '--exclude-terminal' => $this->excludeTerminals[] = $value,
```

No validation here — `TerminalKindFilter::fromNames()` owns it, and `Application` calls that before the index build.

`src/Config/ConfigLoader.php` — add `$excludeTerminals = $defaults->excludeTerminals;` to the local seeds, the match arm

```php
                'excludeTerminals' => $excludeTerminals = $this->requireStringList('excludeTerminals', $value),
```

and `excludeTerminals: $excludeTerminals,` to the returned `Options`.

- [ ] **Step 8: Wire the filter into `Application`**

Add `use PhpTramp\Chain\TerminalKindFilter;` to the imports. In `analyze()`, construct the filter alongside `Thresholds` — before `buildIndex()`, so a bad name fails fast — and apply it to the suppression result:

```php
            $thresholds = new Thresholds($options->limit, $options->warnLimit, $options->minClasses);
            $terminalFilter = TerminalKindFilter::fromNames($options->excludeTerminals);
            $baselineFilter = new BaselineFilter();
            $baseline = $this->consumeBaseline($options, $baselineFilter);
            $index = $this->buildIndex($options);
            $reporter = (new ReporterFactory($this->workingDirectory()))->create(
                $this->downgradedOptions($options),
                ColorPolicy::from(
                    $options->colorMode,
                    stream_isatty($this->stdout),
                    $this->noColorSet(),
                ),
            );
            $suppression = (new SuppressionFilter($index->suppressions()))->filter(
                $this->changedOnlyFindings($this->findChains($index), $options),
            );
            $findings = $terminalFilter->filter($suppression->kept);
```

The order is deliberate: filtering **after** suppression means an excluded chain's `#[TrampIgnore]` still fires, so suppressions do not become spuriously stale. A *baselined* chain that becomes excluded does go stale — correct, and only visible under `--fail-on-stale`.

Add the flag to `helpText()` under Reporting, keeping the column alignment of the surrounding lines:

```
              --exclude-terminal <kind> Do not report chains ending in <kind> (repeatable; used|stored|&-terminated|unused-end|external|truncated)
```

- [ ] **Step 9: Add the end-to-end fixture**

Create `tests/fixtures/exclude-terminal-stored/src/Demo.php` — copy verbatim, the expectation pins its line numbers:

```php
<?php

namespace Demo;

class Cfg
{
}

class StoredController
{
    public function handle(Cfg $config): void
    {
        (new Mailer())->deliver($config);
    }
}

class Mailer
{
    private ?Cfg $config = null;

    public function deliver(Cfg $config): void
    {
        $this->config = $config;
    }
}

class UsedController
{
    public function handle(Cfg $config): void
    {
        (new Logger())->write($config);
    }
}

class Logger
{
    public function write(Cfg $config): void
    {
        $config->flush();
    }
}
```

Create `tests/fixtures/exclude-terminal-stored/phptramp-args.json`:

```json
["--limit", "1", "--warn-limit", "0", "--exclude-terminal", "stored"]
```

Create `tests/fixtures/exclude-terminal-stored/expected-findings.json`:

```json
{
    "limit": 1,
    "warnLimit": null,
    "minClasses": 0,
    "findings": [
        {
            "param": "config",
            "severity": "error",
            "origin": "Demo\\UsedController::handle",
            "terminal": "Demo\\Logger::write",
            "terminalKind": "used",
            "hops": 1,
            "classes": 2,
            "chain": [
                {"method": "Demo\\UsedController::handle", "role": "origin", "file": "src/Demo.php", "line": 29, "forwardLine": 31},
                {"method": "Demo\\Logger::write", "role": "terminal", "file": "src/Demo.php", "line": 37, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

Both chains are 1 hop across 2 classes and both fire at `--limit 1`; only the `stored` one is excluded. That is what makes this a filter test and not a threshold test.

- [ ] **Step 10: Amend the spec to match the single-validation decision**

In `docs/superpowers/specs/2026-08-14-issue-19-construction-delegation-design.md`, replace the paragraph beginning "Values are validated against `TerminalKind`'s backing values at both entry points" with:

```markdown
Values are validated in one place — `TerminalKindFilter::fromNames()`, which
`Application::analyze()` calls before `buildIndex()`, so a typo still fails
fast (exit 2) without the name→enum mapping living in three files. Any kind is
accepted, including `used`; excluding it is pointless but harmless, and a
whitelist of "sensible" kinds would be a second thing to maintain.
```

- [ ] **Step 11: Run the full gate**

```bash
composer check
```

Expected: PASS. If PHPMD reports `TooManyFields` on `ArgvParser` (it sits at 14 fields today; this adds the 15th), do not raise the threshold — fold `$exclude` and `$excludeTerminals` into a small `ExclusionFlags` value object next to the existing `BoolFlags` and `DiffAwareFlags`, which is the pattern that class already uses for exactly this pressure.

- [ ] **Step 12: Commit**

```bash
git add src/Chain/TerminalKindFilter.php src/Console/ src/Config/ tests/ docs/superpowers/specs
git commit -m "feat(#19): --exclude-terminal filters findings by terminal kind

A team that tightens thresholds may not want chains ending in a constructor or
value object — the parameter has arrived at its home. That is project policy,
so it is one config line rather than a #[TrampIgnore] on every factory. A
denylist, defaulting to empty: adding a TerminalKind later cannot silently
change anyone's results, which an allowlist default could not promise.

Filtering runs after suppression so an excluded chain's #[TrampIgnore] still
fires and is not reported stale."
```

---

## Task 5: Documentation

**Files:**
- Modify: `docs/plan.md` (frozen rule 4; post-v0.1 appendix)
- Modify: `README.md`

**Interfaces:**
- Consumes: everything from Tasks 2 and 4.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Amend frozen rule 4 in `docs/plan.md`**

Rule 4 currently reads "**Threshold metric:** number of pure-forward methods in the chain (origin included if it purely forwards; terminal never counted)." Append to that first sentence, before the `Report when hops ≥ --limit` sentence:

```markdown
   A node that forwards on through `parent::` is excluded from both the hop count
   and the class count: it delegates to the same object's base, so it is
   inheritance wiring rather than a value crossing a collaborator boundary
   (issue #19). `self::`/`static::` are unaffected — same class, ordinary hop.
```

Also extend the `--min-classes` sentence with a pointer to `--exclude-terminal`:

```markdown
   `--exclude-terminal <kind>` (repeatable, default: none) drops findings whose
   chain ends in a given `TerminalKind`; it filters, it does not re-score.
```

- [ ] **Step 2: Record the rejected option in the `docs/plan.md` appendix**

Add to the "Appendix: design rationale" list, so a future implementer does not re-propose it:

```markdown
- **`stored` terminals stay findings by default:** issue #19 proposed that a
  chain ending by handing the value to a constructor or value object should not
  be a finding. Rejected as a *default*: the README's own headline finding is a
  `stored` terminal, as are 6 of the 16 findings across `tests/fixtures/`. The
  issue's noisy example (2 hops across 2 classes) and the README's legitimate
  one (3 hops across 4 classes) are both `stored`, so terminal kind is not what
  separates them — hop count is. Exposed as opt-in `--exclude-terminal` instead.
```

- [ ] **Step 3: Document both features in `README.md`**

Add `--exclude-terminal <kind>` to the options table under Reporting, matching the surrounding rows' wording, and add `excludeTerminals` to the `phptramp.json` config-key table.

Add a short subsection after the "What it does" section explaining that construction and delegation are not tramp data — a subclass forwarding to `parent::__construct()` is the same object, so it scores neither a hop nor a class and is marked `(parent)` in the chain; and that `--exclude-terminal stored` is available for teams that also do not want chains ending in a constructor or value object. Include a rendered example:

```text
FINDING  $config: 1 pass-through hop across 2 classes
  origin    Demo\SpecificHandler::__construct($config)  src/Demo.php:19→21  (parent)
  hop 2     Demo\BaseHandler::__construct($config)      src/Demo.php:11→13
  terminal  Demo\Mailer::send($config)                  src/Demo.php:29     (stored)
```

Note in that subsection that the chain shows three nodes but scores one hop, and that the `(parent)` marker is why.

- [ ] **Step 4: Verify the rendered example is real, not invented**

```bash
vendor/bin/phptramp --folder tests/fixtures/parent-delegation-mixed/src --no-config --no-cache --limit 1 --warn-limit 0 --format text
```

Expected: output matching the README block above modulo the path prefix. If it differs, fix the README to match the tool — never the reverse.

- [ ] **Step 5: Run the full gate and commit**

```bash
composer check
```

```bash
git add docs/plan.md README.md
git commit -m "docs(#19): frozen rule 4, README and the rejected stored-by-default option"
```

---

## Task 6: Mutation gate and pull request

**Files:** none modified unless escaped mutants demand tests.

- [ ] **Step 1: Run the diff-scoped mutation job CI will run**

```bash
git fetch origin main && composer infection:diff
```

Expected: MSI ≥ 80 over the files this branch changes.

- [ ] **Step 2: Kill any escaped mutants**

Inspect `infection.log` (or re-run with `--show-mutations=max`). An escaped mutant here is a real gap — this is the classifier/scoring code whose whole product is semantic correctness. Likely candidates given this change: the `! $hop->viaParent` guards in `scoredHops()` and `distinctClasses()`, and the `$this->excluded === []` short-circuit in `TerminalKindFilter::filter()`. Add a test that would fail if the line were wrong; never tune the threshold.

Commit any added tests separately: `test(#19): cover <the mutant that escaped>`.

- [ ] **Step 3: Open the pull request**

```bash
git push -u origin feature/19-construction-delegation
```

PR into `develop` (never `main`), body containing `Closes #19` so the merge auto-closes the issue. Summarize: `parent::` delegation no longer scores; `--exclude-terminal` added; `stored`-by-default rejected with rationale. Verify the issue actually closed after merge rather than closing it by hand.

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| 1. Parent delegation stops scoring | Task 2 (all four worked cases from the spec's table are covered: pure 2-node, 3-deep hierarchy fixture, parent-then-collaborator, collaborator-then-parent) |
| 2. Cut the `hops`-as-index coupling | Task 1 (all five reporters) |
| 3. `--exclude-terminal` | Task 4 (filter, CLI, config, `Application` ordering) |
| 4. Rendering | Task 3 (text, pretty, json; the four unchanged formats need no work) |
| 5. Testing | Tasks 1–4 (3 fixtures, 3 new unit-test files, additions to 6 existing test files) |
| 6. Documentation | Task 5 |

The spec's "validated at both entry points" is deliberately not implemented; Task 4 Step 10 amends the spec, so the two documents end consistent.

**Type consistency**

`viaParent` is spelled identically in `Hop`, `ChainTraversal`, all three reporters, both fixtures and the JSON key. `keepsTerminalNode()` / `hasTerminalNode()` / `forwardingHops()` are each defined once in Task 1 and only consumed afterwards. `TerminalKindFilter::fromNames()` is defined in Task 4 Step 3 and called in Step 8 with the same signature.

**Known ordering constraint**

Task 1 must land before Task 2 — Task 2 breaks all five reporters otherwise. Task 3 needs `Hop::$viaParent` from Task 2. Task 4 is independent of 1–3 and could run in parallel, but its fixture and `composer check` run against the whole suite, so sequential is simpler.
