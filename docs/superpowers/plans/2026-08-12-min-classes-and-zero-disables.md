# `--min-classes` filter + `0`-disables tier semantics + new defaults

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `--min-classes <n>` filter that suppresses chains traversing fewer than `n` distinct classes, make `--limit 0` and `--warn-limit 0` mean "tier off," raise the code-level default `--limit` from 3 to 6 and `--warn-limit` from null to 4, and update all docs and fixtures.

**Architecture:** `Thresholds` — the single severity-decision point — gains a `minClasses` parameter and a relaxed guard. `severityOf()` returns `null` (not reported) when `minClasses > 0 && finding.classes < minClasses`, and the error branch gains a `limit > 0` guard so `limit: 0` disables it. `warnLimit: 0` is normalized to `null` inside `Thresholds` so `0` and `null` are truly indistinguishable. `Options` + `ArgvParser` gain `--min-classes` (default `0` = off) and the new default thresholds. `JsonReporter` emits `"minClasses"` in the top-level document. `TextReporter` shows `min-classes` in the footer when `> 0`. `ConfigLoader` gains a `minClasses` key. No changes to `Finding`, `Hop`, `Chain`, `Severity` (still two-valued), or the diff/baseline/suppression paths — the filter composes at severity time.

**Tech Stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency).

## Global Constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit, PHPMD-clean for every touched `src/` file, Clean Code house style, strict TDD, one task per commit.
- **GitHub issue for this feature: #13.** Branch `feature/13-min-classes-and-zero-disables` off `develop` (already created, carries this plan). Commits `type(#13): …`. PR into `develop` with `Closes #13`; diff-scoped Infection gate (minMsi 80) applies.
- **Frozen-semantics revision:** changing the default `--limit` from 3 to 6 and `--warn-limit` from null to 4 revises `docs/plan.md`'s "Default `--limit` is `3`" bullet. This is flagged in the commit message and documented in `docs/plan.md`.
- **Dogfood config applies the new defaults:** `phptramp.dist.json` moves to `limit: 6, warnLimit: 4` along with the code-level defaults. The repo's `src/` currently has max 2-hop chains (93 one-hop, 8 two-hop), so the dogfood CI gate will report nothing under the new thresholds — effectively a no-op gate. This is accepted as the cost of the new defaults; the gate becomes meaningful again when `src/` grows chains of 4+ hops.
- **`0` means off for both tiers.** `--limit 0` disables the error tier; `--warn-limit 0` disables the warning tier (normalized to `null` inside `Thresholds`).
- **Guard relaxation:** `warnLimit >= limit` throws only when both are `> 0`. `0` on either side bypasses the check.
- **`minClasses` default `0` = filter disabled.** Below-`minClasses` chains get severity `null` (dropped entirely), gating both tiers.
- **No new `src/` files** — the feature reuses `Thresholds`, `Options`, `ArgvParser`, `ConfigLoader`, and the existing reporters.

## Existing interfaces this plan consumes (verbatim)

- `Thresholds::__construct(int $limit, ?int $warnLimit)` at `src/Report/Thresholds.php:22` — gains `int $minClasses = 0` as a third param. `severityOf(Finding): ?Severity` at line 34 — the single severity-decision point used by every reporter and `Application::hasError()`.
- `Options::__construct` at `src/Console/Options.php:22` — readonly value object, `@SuppressWarnings(PHPMD)` on the class for the wide constructor.
- `ArgvParser::parse(array $args, Options $defaults = new Options()): Options` at `src/Console/ArgvParser.php:68` — `VALUE_FLAGS` const (line 21), `applyValueFlag` match (line 160), `toInt` (line 235, uses `ctype_digit` — accepts `0`, rejects negatives).
- `ConfigLoader::toOptions()` at `src/Config/ConfigLoader.php:81` — `match ($key)` at line 95 with strict unknown-key errors; `requireInt` at line 156.
- `ReporterFactory::create()` at `src/Report/ReporterFactory.php:17` — constructs `Thresholds($options->limit, $options->warnLimit)` at line 18.
- `Application::analyze()` at `src/Console/Application.php:129` — constructs `Thresholds($options->limit, $options->warnLimit)` at line 132.
- `JsonReporter::render()` at `src/Report/JsonReporter.php:30` — builds `['limit' => …, 'warnLimit' => …, 'findings' => …]` at lines 32-36.
- `TextReporter::limitClause()` at `src/Report/TextReporter.php:230` — builds the footer clause from `$this->thresholds->limit` and `warnLimit`.
- `Finding::$classes` at `src/Chain/Finding.php:27` — `public readonly int $classes` (distinct declaring classes across chain incl. terminal).
- `Application::helpText()` at `src/Console/Application.php:365` — heredoc with `--limit` and `--warn-limit` lines.
- `FixtureTest::extraCliArgs()` at `tests/FixtureTest.php:165` — returns `['--limit', '1']` by default when no `phptramp-args.json` exists.

---

## Task 1: `Thresholds` — `minClasses` filter + `0`-disables + relaxed guard

**Files:**
- Modify: `src/Report/Thresholds.php`
- Test: `tests/Report/ThresholdsTest.php`

**Interfaces:**
- Consumes: `Finding` (has `->hops` and `->classes`), `Severity` enum, `InvalidArgsException`
- Produces: `Thresholds::__construct(int $limit, ?int $warnLimit, int $minClasses = 0)` with normalized `warnLimit` (`0 → null`), relaxed guard (only when both `> 0`), and `severityOf()` that checks `minClasses` and `limit > 0`

- [ ] **Step 1: Write the failing tests** — add these to `tests/Report/ThresholdsTest.php`:

```php
public function testMinClassesGatesBothTiersWhenClassesBelowMinimum(): void
{
    $thresholds = new Thresholds(3, 2, minClasses: 5);

    self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(5, 4)));
}

public function testMinClassesBoundaryReportsAtExactlyMinClasses(): void
{
    $thresholds = new Thresholds(3, 2, minClasses: 4);

    self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHopsAndClasses(3, 4)));
}

public function testMinClassesZeroIsOffAndReportsNormally(): void
{
    $thresholds = new Thresholds(3, null, minClasses: 0);

    self::assertSame(Severity::Error, $thresholds->severityOf($this->findingWithHopsAndClasses(3, 1)));
}

public function testLimitZeroDisablesErrorTierButWarnTierStillFires(): void
{
    $thresholds = new Thresholds(0, 4);

    self::assertSame(Severity::Warning, $thresholds->severityOf($this->findingWithHopsAndClasses(5, 1)));
}

public function testLimitZeroDisablesErrorTierAndExitCodeStaysZero(): void
{
    $thresholds = new Thresholds(0, null);

    self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(99, 1)));
}

public function testWarnLimitZeroIsNormalizedToNullAndDisablesWarnTier(): void
{
    $thresholds = new Thresholds(6, 0);

    self::assertNull($thresholds->warnLimit);
    self::assertNull($thresholds->severityOf($this->findingWithHopsAndClasses(4, 1)));
}

public function testGuardAllowsLimitZeroWithPositiveWarnLimit(): void
{
    // Would throw under the old guard (warnLimit >= limit when both > 0);
    // with limit=0 the guard is bypassed entirely.
    new Thresholds(0, 4);

    $this->expectNotToPerformAssertions();
}

public function testGuardAllowsWarnLimitZeroWithPositiveLimit(): void
{
    new Thresholds(6, 0);

    $this->expectNotToPerformAssertions();
}

public function testGuardStillThrowsWhenBothPositiveAndWarnLimitAtLimit(): void
{
    $this->expectException(InvalidArgsException::class);

    new Thresholds(6, 6);
}

public function testGuardStillThrowsWhenBothPositiveAndWarnLimitAboveLimit(): void
{
    $this->expectException(InvalidArgsException::class);

    new Thresholds(3, 5);
}

private function findingWithHopsAndClasses(int $hops, int $classes): Finding
{
    return new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, $hops, [], $classes, [], []);
}
```

Also update the existing guard tests to use values where both are `> 0` (they already do — `Thresholds(3, 3)` and `Thresholds(2, 3)` — so they stay as-is).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ThresholdsTest`
Expected: FAIL — `minClasses` param doesn't exist, `0`-disables not implemented.

- [ ] **Step 3: Implement** — replace `src/Report/Thresholds.php` with:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Console\InvalidArgsException;

/**
 * The three thresholds a report is judged against: fail at `limit`, warn at
 * `warnLimit`, suppress below `minClasses` distinct classes. Severity is
 * computed here, at reporting time, so `Finding` itself stays a pure fact
 * about a chain.
 *
 * `0` disables a tier: `limit: 0` turns off the error tier, `warnLimit: 0`
 * is normalized to `null` (off). The warn-limit-below-limit guard fires only
 * when both are positive.
 */
final class Thresholds
{
    public readonly ?int $warnLimit;

    /**
     * @throws InvalidArgsException when both limits are positive and
     *                               warnLimit >= limit (a warn bar that can
     *                               never fire below the fail bar is a config
     *                               error)
     */
    public function __construct(
        public readonly int $limit,
        ?int $warnLimit,
        public readonly int $minClasses = 0,
    ) {
        $this->warnLimit = $warnLimit !== null && $warnLimit > 0 ? $warnLimit : null;

        if ($this->warnLimit !== null && $this->limit > 0 && $this->warnLimit >= $this->limit) {
            throw new InvalidArgsException(
                "warn-limit ({$warnLimit}) must be lower than limit ({$this->limit}).",
            );
        }
    }

    /** Null = below every threshold: not reported at all. */
    public function severityOf(Finding $finding): ?Severity
    {
        if ($this->minClasses > 0 && $finding->classes < $this->minClasses) {
            return null;
        }

        if ($this->limit > 0 && $finding->hops >= $this->limit) {
            return Severity::Error;
        }

        if ($this->warnLimit !== null && $finding->hops >= $this->warnLimit) {
            return Severity::Warning;
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ThresholdsTest`
Expected: PASS — all old and new tests green.

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green — no other tests are affected (the `minClasses` param has a default, `Thresholds` callers still work).

- [ ] **Step 6: Commit**

```bash
git add src/Report/Thresholds.php tests/Report/ThresholdsTest.php
git commit -m "feat(#13): min-classes filter and 0-disables semantics in Thresholds

Add minClasses param (default 0 = off): severityOf returns null when
finding.classes < minClasses. limit: 0 disables the error tier
(limit > 0 && hops >= limit). warnLimit: 0 is normalized to null
(off). The warn-limit-below-limit guard fires only when both are
positive, so --limit 0 --warn-limit 4 is allowed.

Revises docs/plan.md frozen semantics: default --limit 3 -> 6,
--warn-limit null -> 4 (applied in a later task on Options/ArgvParser)."
```

---

## Task 2: `Options` + `ArgvParser` — `--min-classes` flag (no default changes yet)

**Files:**
- Modify: `src/Console/Options.php`, `src/Console/ArgvParser.php`
- Test: `tests/Console/ArgvParserTest.php`

**Interfaces:**
- Consumes: `Options` (readonly value object)
- Produces: `Options::$minClasses` (int, default `0`), `ArgvParser` parses `--min-classes <n>`

- [ ] **Step 1: Write the failing tests** — add to `tests/Console/ArgvParserTest.php`:

```php
public function testMinClassesParsed(): void
{
    self::assertSame(4, $this->parse('--min-classes', '4')->minClasses);
}

public function testMinClassesEqualsSyntaxWorks(): void
{
    self::assertSame(3, $this->parse('--min-classes=3')->minClasses);
}

public function testMinClassesDefaultsToZero(): void
{
    self::assertSame(0, $this->parse()->minClasses);
}

public function testNonNumericMinClassesThrows(): void
{
    $this->expectException(InvalidArgsException::class);
    $this->parse('--min-classes', 'x');
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ArgvParserTest`
Expected: FAIL — `minClasses` property doesn't exist, `--min-classes` is an unknown option.

- [ ] **Step 3: Implement** — three edits:

**Edit `src/Console/Options.php`** — add `minClasses` to the constructor (after `warnLimit`):

```php
public readonly ?int $warnLimit = null,
public readonly int $minClasses = 0,
public readonly string $format = 'text',
```

**Edit `src/Console/ArgvParser.php`** — add `--min-classes` to `VALUE_FLAGS` (after `--warn-limit`):

```php
'--warn-limit',
'--min-classes',
'--format',
```

Add a `private int $minClasses = 0;` field (after `$warnLimit`):

```php
private ?int $warnLimit = null;
private int $minClasses = 0;
private string $format = 'text';
```

In `reset()`, add (after `$this->warnLimit = $defaults->warnLimit;`):

```php
$this->minClasses = $defaults->minClasses;
```

In `applyValueFlag()`, add to the match (after `--warn-limit`):

```php
'--warn-limit' => $this->warnLimit = $this->toInt($name, $value),
'--min-classes' => $this->minClasses = $this->toInt($name, $value),
```

In `parse()`, add to the `Options` constructor call (after `warnLimit:`):

```php
warnLimit: $this->warnLimit,
minClasses: $this->minClasses,
format: $this->format,
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ArgvParserTest`
Expected: PASS

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green — `minClasses` defaults to `0`, no behavior change anywhere.

- [ ] **Step 6: Commit**

```bash
git add src/Console/Options.php src/Console/ArgvParser.php tests/Console/ArgvParserTest.php
git commit -m "feat(#13): add --min-classes CLI flag

Parsed into Options.minClasses (int, default 0 = off). No threshold
or default changes yet — just the flag plumbing."
```

---

## Task 3: Wire `minClasses` through to `Thresholds` + emit in JSON + update all fixture expected files

**Files:**
- Modify: `src/Report/ReporterFactory.php`, `src/Console/Application.php`, `src/Report/JsonReporter.php`
- Modify: `tests/Report/JsonReporterTest.php`
- Modify: all 15 `tests/fixtures/*/expected-findings.json` files
- Test: `tests/Report/JsonReporterTest.php`, `tests/FixtureTest.php`

**Interfaces:**
- Consumes: `Options::$minClasses` (from Task 2), `Thresholds::__construct(int, ?int, int)` (from Task 1)
- Produces: `JsonReporter` JSON document gains `"minClasses": <int>` after `"warnLimit"`

- [ ] **Step 1: Write the failing test** — update `tests/Report/JsonReporterTest.php`. Every `expected` JSON document in this file needs `"minClasses": 0` inserted after `"warnLimit"`. For example, the first test's expected becomes:

```php
$expected = <<<'JSON'
    {
        "limit": 3,
        "warnLimit": 2,
        "minClasses": 0,
        "findings": [
            {
                "param": "config",
                "severity": "error",
                ...
            }
        ]
    }
    JSON;
```

Do this for ALL test methods in `JsonReporterTest.php` — insert `"minClasses": 0,` (or the appropriate value when constructing `Thresholds` with a non-zero `minClasses`) after every `"warnLimit": ...,` line. The `Thresholds` constructor calls in the test also need the third arg when non-zero: `new Thresholds(3, 2, 0)` — but since `0` is the default, existing `new Thresholds(3, 2)` calls still work; just add the JSON field.

Also add one new test proving `minClasses` appears in the JSON:

```php
public function testMinClassesIsEmittedWhenSet(): void
{
    $chain = [
        new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
        new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
    ];
    $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

    $expected = <<<'JSON'
        {
            "limit": 1,
            "warnLimit": null,
            "minClasses": 2,
            "findings": []
        }

        JSON;

    $reporter = new JsonReporter(new Thresholds(1, null, 2), $this->paths());

    self::assertSame($expected, $reporter->render([$finding]));
}
```

(The finding has `classes: 1 < minClasses: 2`, so `severityOf` returns `null` and it's filtered out — `findings` is empty.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter JsonReporterTest`
Expected: FAIL — JSON output doesn't include `"minClasses"`.

- [ ] **Step 3: Implement** — three edits:

**Edit `src/Report/ReporterFactory.php` line 18** — pass `minClasses`:

```php
$thresholds = new Thresholds($options->limit, $options->warnLimit, $options->minClasses);
```

**Edit `src/Console/Application.php` line 132** — pass `minClasses`:

```php
$thresholds = new Thresholds($options->limit, $options->warnLimit, $options->minClasses);
```

**Edit `src/Report/JsonReporter.php` lines 32-36** — add `minClasses` to the document:

```php
$document = [
    'limit' => $this->thresholds->limit,
    'warnLimit' => $this->thresholds->warnLimit,
    'minClasses' => $this->thresholds->minClasses,
    'findings' => $this->findingDocuments($findings),
];
```

- [ ] **Step 4: Run the unit tests to verify they pass**

Run: `vendor/bin/phpunit --filter JsonReporterTest`
Expected: PASS

- [ ] **Step 5: Update all 15 fixture `expected-findings.json` files**

Every `expected-findings.json` needs `"minClasses": 0,` inserted after the `"warnLimit"` line. The `warnLimit` value stays as-is for each fixture (the defaults haven't changed yet — Task 6 does that). For example:

```json
{
    "limit": 1,
    "warnLimit": null,
    "minClasses": 0,
    "findings": [
        ...
    ]
}
```

The 15 files to update:
1. `tests/fixtures/baseline-filters-known/expected-findings.json`
2. `tests/fixtures/baseline-stale-entry/expected-findings.json`
3. `tests/fixtures/changed-hop-reported/expected-findings.json`
4. `tests/fixtures/changed-only-filters-rest/expected-findings.json`
5. `tests/fixtures/changed-terminal-not-enough/expected-findings.json`
6. `tests/fixtures/classifier-smoke/expected-findings.json`
7. `tests/fixtures/config-driven/expected-findings.json`
8. `tests/fixtures/interface-multi-impl/expected-findings.json`
9. `tests/fixtures/interface-single-impl/expected-findings.json`
10. `tests/fixtures/recursion/expected-findings.json`
11. `tests/fixtures/static-function-mix/expected-findings.json`
12. `tests/fixtures/suppressed-chain/expected-findings.json`
13. `tests/fixtures/use-breaks-chain/expected-findings.json`
14. `tests/fixtures/variadic-decorator/expected-findings.json`
15. `tests/fixtures/warn-vs-error/expected-findings.json`

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green — all fixture tests pass with the new `"minClasses": 0` field.

- [ ] **Step 7: Commit**

```bash
git add src/Report/ReporterFactory.php src/Console/Application.php src/Report/JsonReporter.php tests/Report/JsonReporterTest.php tests/fixtures/*/expected-findings.json
git commit -m "feat(#13): emit minClasses in JSON and wire through to Thresholds

ReporterFactory and Application pass Options.minClasses to the
Thresholds constructor. JsonReporter adds \"minClasses\" to the
top-level JSON document after \"warnLimit\". All 15 fixture
expected-findings.json files updated with \"minClasses\": 0."
```

---

## Task 4: `TextReporter` — min-classes clause in footer

**Files:**
- Modify: `src/Report/TextReporter.php`
- Test: `tests/Report/TextReporterTest.php`

**Interfaces:**
- Consumes: `Thresholds::$minClasses`
- Produces: `limitClause()` appends `, min-classes: <n> classes` when `minClasses > 0`

- [ ] **Step 1: Write the failing tests** — add to `tests/Report/TextReporterTest.php`:

```php
public function testFooterShowsMinClassesWhenSet(): void
{
    $chain = [
        new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
        new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
    ];
    $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

    $expected = <<<'TXT'
        FINDING  $p: 1 pass-through hop across 1 class
          origin    Demo\A::go($p)    src/A.php:5
          terminal  Demo\A::sink($p)  src/A.php:9  (used)

        1 finding (limit: 1 hop, min-classes: 1 class).

        TXT;

    $reporter = new TextReporter(new Thresholds(1, null, 1), new Paths('/nonexistent-root'));

    self::assertSame($expected, $reporter->render([$finding]));
}

public function testFooterOmitsMinClassesWhenZero(): void
{
    $chain = [
        new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
        new Hop('Demo\A::sink', 'Demo\A', 'src/A.php', 9, null),
    ];
    $finding = new Finding('p', 'Demo\A::go', 'Demo\A::sink', TerminalKind::Used, 1, $chain, 1, [], []);

    $reporter = new TextReporter(new Thresholds(1, null, 0), new Paths('/nonexistent-root'));

    self::assertStringNotContainsString('min-classes', $reporter->render([$finding]));
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter TextReporterTest`
Expected: FAIL — `min-classes` not in the footer.

- [ ] **Step 3: Implement** — edit `src/Report/TextReporter.php` `limitClause()` method (line 230):

```php
private function limitClause(): string
{
    $clause = 'limit: ' . $this->thresholds->limit . ' ' . $this->pluralizer->of($this->thresholds->limit, 'hop');
    if ($this->thresholds->warnLimit !== null) {
        $clause .= ', warn-limit: ' . $this->thresholds->warnLimit
            . ' ' . $this->pluralizer->of($this->thresholds->warnLimit, 'hop');
    }
    if ($this->thresholds->minClasses > 0) {
        $clause .= ', min-classes: ' . $this->thresholds->minClasses
            . ' ' . $this->pluralizer->of($this->thresholds->minClasses, 'class', 'classes');
    }

    return $clause;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter TextReporterTest`
Expected: PASS

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green

- [ ] **Step 6: Commit**

```bash
git add src/Report/TextReporter.php tests/Report/TextReporterTest.php
git commit -m "feat(#13): show min-classes in text footer when set

limitClause() appends ', min-classes: <n> classes' only when
minClasses > 0, mirroring how warn-limit is shown only when set."
```

---

## Task 5: `ConfigLoader` — `minClasses` config key

**Files:**
- Modify: `src/Config/ConfigLoader.php`
- Test: `tests/Config/ConfigLoaderTest.php`

**Interfaces:**
- Consumes: `Options::$minClasses` (from Task 2)
- Produces: `minClasses` JSON key in config files maps to `Options.minClasses`

- [ ] **Step 1: Write the failing tests** — add to `tests/Config/ConfigLoaderTest.php`:

```php
public function testMinClassesIsMapped(): void
{
    $this->writeConfig('phptramp.json', '{"minClasses": 5}');

    $options = (new ConfigLoader())->load($this->directory);

    self::assertSame(5, $options->minClasses);
}

public function testWrongTypeForMinClassesThrows(): void
{
    $this->writeConfig('phptramp.json', '{"minClasses": "three"}');

    $this->expectException(ConfigException::class);

    (new ConfigLoader())->load($this->directory);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ConfigLoaderTest`
Expected: FAIL — `unknown config key: minClasses`

- [ ] **Step 3: Implement** — edit `src/Config/ConfigLoader.php`:

In `toOptions()` (around line 89-92), add `$minClasses` local:

```php
$limit = $defaults->limit;
$warnLimit = $defaults->warnLimit;
$minClasses = $defaults->minClasses;
$format = $defaults->format;
```

In the `match ($key)` block (around line 95-104), add after `warnLimit`:

```php
'warnLimit' => $warnLimit = $this->requireInt('warnLimit', $value),
'minClasses' => $minClasses = $this->requireInt('minClasses', $value),
'format' => $format = $this->requireString('format', $value),
```

In the `return new Options(...)` call (around line 107-116), add `minClasses`:

```php
return new Options(
    folders: $folders,
    files: $files,
    limit: $limit,
    warnLimit: $warnLimit,
    minClasses: $minClasses,
    format: $format,
    baseline: $baseline,
    exclude: $exclude,
    cacheDir: $cacheDir,
);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ConfigLoaderTest`
Expected: PASS

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green

- [ ] **Step 6: Commit**

```bash
git add src/Config/ConfigLoader.php tests/Config/ConfigLoaderTest.php
git commit -m "feat(#13): minClasses config key

Strict unknown-key error now accepts \"minClasses\" (int). Mapped
into Options.minClasses like the other threshold keys."
```

---

## Task 6: Change defaults — `limit: 6`, `warnLimit: 4` — and update all affected tests

This is the largest task. The defaults change ripples through every test that relies on `--no-config` or a no-config temp directory without explicitly setting both `--limit` and `--warn-limit`.

**Files:**
- Modify: `src/Console/Options.php` (default `limit: 6`, `warnLimit: 4`)
- Modify: `src/Console/ArgvParser.php` (private field defaults `6` and `4`)
- Modify: `tests/Console/ArgvParserTest.php` (`testDefaults` assertions)
- Modify: `tests/Console/ApplicationTest.php` (add explicit `--limit`/`--warn-limit 0` to tests that lack them)
- Modify: `tests/FixtureTest.php` (default `extraCliArgs`)
- Modify: 5 fixture `phptramp-args.json` files (add `"--warn-limit", "0"`)
- Modify: `tests/fixtures/config-driven/phptramp.json` (add `"warnLimit": 0`)
- Modify: `phptramp.dist.json` (apply new defaults: `limit: 6, warnLimit: 4`)
- Modify: `.github/workflows/ci.yml` (update the dogfood comment)

**Interfaces:**
- Consumes: all of the above
- Produces: `Options.limit` defaults to `6`, `Options.warnLimit` defaults to `4`

- [ ] **Step 1: Update `ArgvParserTest::testDefaults`**

In `tests/Console/ArgvParserTest.php`, change the `testDefaults` method:

```php
public function testDefaults(): void
{
    $o = $this->parse();
    self::assertSame([], $o->folders);
    self::assertSame([], $o->files);
    self::assertSame(6, $o->limit);
    self::assertSame(4, $o->warnLimit);
    self::assertSame(0, $o->minClasses);
    self::assertSame('text', $o->format);
    self::assertFalse($o->explain);
    self::assertFalse($o->changedOnly);
    self::assertSame('origin/main', $o->gitBase);
    self::assertNull($o->diff);
    self::assertNull($o->baseline);
    self::assertFalse($o->dumpIndex);
    self::assertFalse($o->failOnStale);
}
```

- [ ] **Step 2: Implement the default changes**

**Edit `src/Console/Options.php`** — change the constructor defaults:

```php
public readonly int $limit = 6,
public readonly ?int $warnLimit = 4,
public readonly int $minClasses = 0,
```

**Edit `src/Console/ArgvParser.php`** — change the private field defaults (around line 53-54):

```php
private int $limit = 6;
private ?int $warnLimit = 4;
```

- [ ] **Step 3: Run `ArgvParserTest` to verify**

Run: `vendor/bin/phpunit --filter ArgvParserTest`
Expected: PASS — `testDefaults` now expects `6` and `4`.

- [ ] **Step 4: Update `FixtureTest` default args**

In `tests/FixtureTest.php` `extraCliArgs()` (line 165-169), change the default from `['--limit', '1']` to `['--limit', '1', '--warn-limit', '0']`:

```php
private function extraCliArgs(string $caseDir): array
{
    $argsFile = $caseDir . '/phptramp-args.json';
    if (! is_file($argsFile)) {
        return ['--limit', '1', '--warn-limit', '0'];
    }
    // ... rest unchanged
}
```

This prevents the guard from throwing (`warnLimit: 4 >= limit: 1` would throw; `warnLimit: 0` is normalized to `null`, bypassing the guard).

- [ ] **Step 5: Update 5 fixture `phptramp-args.json` files that use `--limit 1` without `--warn-limit`**

Add `"--warn-limit", "0"` to each:

1. `tests/fixtures/baseline-filters-known/phptramp-args.json`:
   ```json
   ["--limit", "1", "--warn-limit", "0", "--baseline", "tests/fixtures/baseline-filters-known/baseline.json"]
   ```

2. `tests/fixtures/baseline-stale-entry/phptramp-args.json`:
   ```json
   ["--limit", "1", "--warn-limit", "0", "--baseline", "tests/fixtures/baseline-stale-entry/baseline.json"]
   ```

3. `tests/fixtures/changed-hop-reported/phptramp-args.json`:
   ```json
   ["--limit", "1", "--warn-limit", "0", "--diff", "tests/fixtures/changed-hop-reported/changes.diff"]
   ```

4. `tests/fixtures/changed-only-filters-rest/phptramp-args.json`:
   ```json
   ["--limit", "1", "--warn-limit", "0", "--diff", "tests/fixtures/changed-only-filters-rest/changes.diff"]
   ```

5. `tests/fixtures/changed-terminal-not-enough/phptramp-args.json`:
   ```json
   ["--limit", "1", "--warn-limit", "0", "--diff", "tests/fixtures/changed-terminal-not-enough/changes.diff"]
   ```

The `warn-vs-error` fixture already sets `--warn-limit 2` with `--limit 3` — no change needed.

- [ ] **Step 6: Update `config-driven/phptramp.json`**

Add `"warnLimit": 0` to prevent the default `warnLimit: 4` from conflicting with `limit: 1`:

```json
{
    "paths": ["src"],
    "exclude": ["src/Excl*.php"],
    "limit": 1,
    "warnLimit": 0
}
```

The expected `config-driven/expected-findings.json` already has `"warnLimit": null` — since `Thresholds` normalizes `0 → null`, the JSON output stays `null`. No change to the expected file.

- [ ] **Step 7: Update `ApplicationTest` — add explicit `--limit` and `--warn-limit 0` to tests that rely on defaults**

Many `ApplicationTest` tests use `--no-config` without explicit `--limit`/`--warn-limit`, or use `--limit` without `--warn-limit`. With the new defaults (`limit: 6, warnLimit: 4`), these tests break because:
- Tests expecting a 3-hop chain to be an error need `--limit 3` (default is now 6).
- Tests using `--limit 4` need `--warn-limit 0` (default `warnLimit: 4` would trigger the guard: `4 >= 4`).
- Tests using `--no-config` without `--limit` get `limit: 6` (a 3-hop chain is no longer an error).

For each affected test, add the minimal explicit flags to preserve its original intent. The pattern:

- Tests with `--no-config` and no `--limit` that expect a 3-hop finding: add `'--limit', '3', '--warn-limit', '0'`.
- Tests with `--no-config` and `'--limit', '4'` that expect no finding: add `'--warn-limit', '0'`.
- Tests with `--no-config` and `'--limit', '3'` already set: add `'--warn-limit', '0'` if `--warn-limit` is absent.
- Tests using `runInFolder`/`runAppInFolder` without `--no-config` (temp dir, no config file): same treatment — they get `new Options()` defaults via `ConfigLoader`.

Go through every `--no-config` and `runInFolder`/`runAppInFolder` call in `tests/Console/ApplicationTest.php` and add the flags. Key tests to update (line numbers from current file):

- Line 143: `['phptramp', '--folder', $folder, '--no-config']` → add `'--limit', '3', '--warn-limit', '0'`
- Line 151: `['phptramp', '--folder', $folder, '--no-config', '--limit', '4']` → add `'--warn-limit', '0'`
- Line 159: `['phptramp', '--folder', $folder, '--no-config', '--format', 'xml']` → add `'--limit', '3', '--warn-limit', '0'` (though this test expects exit 2 for bad format, the `Thresholds` constructor runs before format checking and would throw first)
- Line 168: `['phptramp', '--folder', $folder, '--no-config', '--format', 'summary']` → add `'--limit', '3', '--warn-limit', '0'`
- Line 176: `['phptramp', '--folder', $folder, '--no-config', '--format', 'json']` → add `'--limit', '3', '--warn-limit', '0'`
- Line 261: `['phptramp', '--folder', $directory, '--no-config']` → add `'--limit', '3', '--warn-limit', '0'` (1-hop chain, limit 3 → no finding → exit 0)
- Line 369: `['phptramp', '--folder', $folder, '--no-config', '--generate-baseline', $baselineFile, ...]` → add `'--limit', '3', '--warn-limit', '0'`
- Line 389: `['phptramp', '--folder', $folder, '--no-config', ...]` → add `'--limit', '3', '--warn-limit', '0'`
- Line 404: same pattern
- Line 421: same pattern
- Line 440: same pattern
- Line 447: same pattern
- Line 460: same pattern
- Line 469: same pattern
- Line 485: same pattern
- Line 499: same pattern (but this tests a missing baseline — may not need limit)
- Line 523: same pattern
- Line 551: same pattern
- Line 569: same pattern
- Line 581: already has `'--limit', '3'` → add `'--warn-limit', '0'`
- Line 603: already has `'--limit', '3'` → add `'--warn-limit', '0'`
- Line 663: `['phptramp', '--folder', $folder, '--no-config', '--no-cache', ...]` → add `'--limit', '3', '--warn-limit', '0'`
- Line 983: same pattern

For `runInFolder`/`runAppInFolder` calls (no `--no-config`, but temp dir has no config file):
- Lines 275, 288, 302, 315, 328, 341, 355: These use `--changed-only --diff ...` without `--limit`. Add `'--limit', '3', '--warn-limit', '0'` to each.

**Important:** Run `composer check` after each batch of edits to catch any missed tests. The error message from a missed test will name the failing assertion, making it easy to find.

- [ ] **Step 8: Run `composer check`**

Run: `composer check`
Expected: green — all tests pass with explicit flags. No test relies on the old defaults anymore.

**Note:** `phptramp.dist.json` IS modified in this task — it moves to `limit: 6, warnLimit: 4` along with the code-level defaults. The repo's `src/` currently has max 2-hop chains, so the dogfood CI gate will report nothing under the new thresholds; this is accepted as the cost of the new defaults.

- [ ] **Step 8a: Update `phptramp.dist.json`**

```json
{
    "paths": ["src"],
    "limit": 6,
    "warnLimit": 4
}
```

- [ ] **Step 8b: Update the dogfood CI comment** in `.github/workflows/ci.yml` line 41:

```yaml
      # Dogfood: phptramp gates itself via its own config (limit 6, warn-limit 4).
```

- [ ] **Step 9: Commit**

```bash
git add src/Console/Options.php src/Console/ArgvParser.php tests/Console/ArgvParserTest.php tests/Console/ApplicationTest.php tests/FixtureTest.php tests/fixtures/*/phptramp-args.json tests/fixtures/config-driven/phptramp.json phptramp.dist.json .github/workflows/ci.yml
git commit -m "feat(#13): raise default limit to 6 and warn-limit to 4

FROZEN-SEMANTICS REVISION: docs/plan.md 'Default --limit is 3' is
now 6; --warn-limit default changes from null to 4. Applied to both
the code-level defaults and the repo's own phptramp.dist.json so the
dogfood config matches what consumers get out of the box. The repo's
src/ currently has max 2-hop chains, so the dogfood gate reports
nothing under the new thresholds — accepted as the cost of the new
defaults.

Every test that relied on the old defaults now sets --limit and
--warn-limit explicitly. FixtureTest default args add --warn-limit 0
to keep the guard from throwing on --limit 1. Five fixture
phptramp-args.json files and config-driven/phptramp.json add
warnLimit:0 for the same reason."
```

---

## Task 7: Help text + README + `docs/plan.md` frozen-semantics revision

**Files:**
- Modify: `src/Console/Application.php` (help text)
- Modify: `README.md`
- Modify: `docs/plan.md`

- [ ] **Step 1: Update help text** in `src/Console/Application.php` `helpText()` (line 365):

In the "Reporting:" section, add the `--min-classes` line and update the `--limit`/`--warn-limit` descriptions:

```text
Reporting:
  --limit <n>               Fail on chains with >= n pass-through hops (default: 6)
  --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops (default: 4; 0 = off)
  --min-classes <n>         Only report chains traversing >= n distinct classes (default: 0 = off)
  --format <fmt>            text|json|github|checkstyle|sarif|summary (default: text)
  --explain                 Show why chains ended (call resolution trace)
```

Also update the "Status" block at the bottom of the help text to mention `--min-classes`.

- [ ] **Step 2: Update `README.md`**

In the CLI block (around line 96-113), add `--min-classes` and update defaults:

```text
  --limit <n>               Fail on chains with >= n pass-through hops (default: 6)
  --warn-limit <n>          Warn (do not fail CI) on chains with >= n hops (default: 4; 0 = off)
  --min-classes <n>         Only report chains traversing >= n distinct classes (default: 0 = off)
```

In the Configuration section's JSON example (around line 124-132), add `minClasses`:

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

In the config key table (around line 134-142), add a row:

```markdown
| `minClasses` | integer | `--min-classes` |
```

In the `--warn-limit` section (around line 212-218), add a note about `0` = off and mention `--min-classes` as a complementary filter.

- [ ] **Step 3: Update `docs/plan.md`**

In the "Frozen core semantics" section (around line 88-90), revise item 4:

```markdown
4. **Threshold metric:** number of pure-forward methods in the chain (origin included if
   it purely forwards; terminal never counted). Report when hops ≥ `--limit` (default 6
   since v0.1.x; was 3 in the initial design review, raised after real-world use showed
   3-hop chains are too common to gate by default). `--warn-limit` (default 4) adds a
   warning tier below the limit. `--min-classes <n>` (default 0 = off) suppresses chains
   traversing fewer than `n` distinct classes. `0` on either threshold disables that
   tier. Classes traversed is supplementary output, never the primary threshold, but can
   gate via `--min-classes`.
```

- [ ] **Step 4: Run `composer check`**

Run: `composer check`
Expected: green — docs changes don't affect tests (help text is not unit-tested byte-for-byte, but verify the help text renders).

Also run: `phptramp --help` (via `bin/phptramp --help`) and eyeball the output.

- [ ] **Step 5: Commit**

```bash
git add src/Console/Application.php README.md docs/plan.md
git commit -m "docs(#13): document --min-classes and new defaults

Help text, README CLI block, config table, and docs/plan.md
frozen-semantics section updated. Default --limit 3 -> 6,
--warn-limit null -> 4, --min-classes 0 = off. The 0-disables
semantics and the relaxed guard are documented in the --warn-limit
section."
```

---

## Task 8: New fixtures for `--min-classes` and `0`-disables behavior

**Files:**
- Create: `tests/fixtures/min-classes-gated/{src/Demo.php, expected-findings.json, phptramp-args.json}`
- Create: `tests/fixtures/min-classes-boundary/{src/Demo.php, expected-findings.json, phptramp-args.json}`
- Create: `tests/fixtures/limit-zero-disables-error/{src/Demo.php, expected-findings.json, phptramp-args.json}`
- Create: `tests/fixtures/warn-limit-zero-disables-warn/{src/Demo.php, expected-findings.json, phptramp-args.json}`
- Create: `tests/fixtures/warn-limit-below-limit-allowed/{src/Demo.php, expected-findings.json, phptramp-args.json}`

- [ ] **Step 1: Create `min-classes-gated` fixture**

`tests/fixtures/min-classes-gated/src/Demo.php` — a 3-hop / 4-class chain (the README example):

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

`tests/fixtures/min-classes-gated/phptramp-args.json`:

```json
["--limit", "3", "--warn-limit", "0", "--min-classes", "5"]
```

`tests/fixtures/min-classes-gated/expected-findings.json` — chain has 4 classes < 5, so it's suppressed:

```json
{
    "limit": 3,
    "warnLimit": null,
    "minClasses": 5,
    "findings": []
}
```

- [ ] **Step 2: Create `min-classes-boundary` fixture**

Same `src/Demo.php` as above (4 classes).

`tests/fixtures/min-classes-boundary/phptramp-args.json`:

```json
["--limit", "3", "--warn-limit", "0", "--min-classes", "4"]
```

`tests/fixtures/min-classes-boundary/expected-findings.json` — 4 classes >= 4, so it's reported:

```json
{
    "limit": 3,
    "warnLimit": null,
    "minClasses": 4,
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
                {"method": "Demo\\Controller::handle", "role": "origin", "file": "src/Demo.php", "line": 11, "forwardLine": 13},
                {"method": "Demo\\ServiceA::process", "role": "hop", "file": "src/Demo.php", "line": 19, "forwardLine": 21},
                {"method": "Demo\\ServiceB::run", "role": "hop", "file": "src/Demo.php", "line": 27, "forwardLine": 29},
                {"method": "Demo\\Mailer::__construct", "role": "terminal", "file": "src/Demo.php", "line": 37, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

- [ ] **Step 3: Create `limit-zero-disables-error` fixture**

`tests/fixtures/limit-zero-disables-error/src/Demo.php` — a 5-hop / 6-class chain (extend the README pattern):

```php
<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Hop1())->step($config);
    }
}

class Hop1
{
    public function step(Cfg $config): void
    {
        (new Hop2())->step($config);
    }
}

class Hop2
{
    public function step(Cfg $config): void
    {
        (new Hop3())->step($config);
    }
}

class Hop3
{
    public function step(Cfg $config): void
    {
        (new Hop4())->step($config);
    }
}

class Hop4
{
    public function step(Cfg $config): void
    {
        (new Terminal())->consume($config);
    }
}

class Terminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
```

`tests/fixtures/limit-zero-disables-error/phptramp-args.json`:

```json
["--limit", "0", "--warn-limit", "4"]
```

`tests/fixtures/limit-zero-disables-error/expected-findings.json` — 5 hops >= warn-limit 4 → warning; error tier off (limit 0):

```json
{
    "limit": 0,
    "warnLimit": 4,
    "minClasses": 0,
    "findings": [
        {
            "param": "config",
            "severity": "warning",
            "origin": "Demo\\Origin::run",
            "terminal": "Demo\\Terminal::consume",
            "terminalKind": "used",
            "hops": 5,
            "classes": 6,
            "chain": [
                {"method": "Demo\\Origin::run", "role": "origin", "file": "src/Demo.php", "line": 11, "forwardLine": 13},
                {"method": "Demo\\Hop1::step", "role": "hop", "file": "src/Demo.php", "line": 19, "forwardLine": 21},
                {"method": "Demo\\Hop2::step", "role": "hop", "file": "src/Demo.php", "line": 27, "forwardLine": 29},
                {"method": "Demo\\Hop3::step", "role": "hop", "file": "src/Demo.php", "line": 35, "forwardLine": 37},
                {"method": "Demo\\Hop4::step", "role": "hop", "file": "src/Demo.php", "line": 43, "forwardLine": 45},
                {"method": "Demo\\Terminal::consume", "role": "terminal", "file": "src/Demo.php", "line": 51, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

- [ ] **Step 4: Create `warn-limit-zero-disables-warn` fixture**

`tests/fixtures/warn-limit-zero-disables-warn/src/Demo.php` — a 4-hop / 5-class chain (would warn under `warnLimit: 4` but is silent with `--warn-limit 0`):

```php
<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Hop1())->step($config);
    }
}

class Hop1
{
    public function step(Cfg $config): void
    {
        (new Hop2())->step($config);
    }
}

class Hop2
{
    public function step(Cfg $config): void
    {
        (new Hop3())->step($config);
    }
}

class Hop3
{
    public function step(Cfg $config): void
    {
        (new Terminal())->consume($config);
    }
}

class Terminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
```

`tests/fixtures/warn-limit-zero-disables-warn/phptramp-args.json`:

```json
["--limit", "6", "--warn-limit", "0"]
```

`tests/fixtures/warn-limit-zero-disables-warn/expected-findings.json` — 4 hops >= warn-limit 4 (if it were on), but warn tier is off (`0` → `null`); 4 hops < limit 6 → no error either → nothing reported:

```json
{
    "limit": 6,
    "warnLimit": null,
    "minClasses": 0,
    "findings": []
}
```

- [ ] **Step 5: Create `warn-limit-below-limit-allowed` fixture**

This fixture tests the relaxed guard — `--limit 0 --warn-limit 0` is allowed (no `InvalidArgsException`). The test harness runs the full pipeline; if the guard threw, the exit code would be `2` and the JSON would be empty/invalid. The fixture uses a simple 1-hop chain.

`tests/fixtures/warn-limit-below-limit-allowed/src/Demo.php`:

```php
<?php

namespace Demo;

class Cfg
{
}

class Origin
{
    public function run(Cfg $config): void
    {
        (new Terminal())->consume($config);
    }
}

class Terminal
{
    public function consume(Cfg $config): void
    {
        $config->go();
    }
}
```

`tests/fixtures/warn-limit-below-limit-allowed/phptramp-args.json`:

```json
["--limit", "0", "--warn-limit", "0"]
```

`tests/fixtures/warn-limit-below-limit-allowed/expected-findings.json` — both tiers off → no findings, exit 0:

```json
{
    "limit": 0,
    "warnLimit": null,
    "minClasses": 0,
    "findings": []
}
```

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green — all 5 new fixtures pass via the `FixtureTest` harness (they're auto-discovered by `findingsFixtureProvider`).

- [ ] **Step 7: Run Infection (diff-scoped)**

Run: `composer infection:diff`
Expected: minMsi ≥ 80 — the new `Thresholds` code paths (minClasses check, limit > 0 guard, warnLimit normalization) are covered by the new tests and fixtures.

- [ ] **Step 8: Commit**

```bash
git add tests/fixtures/min-classes-gated/ tests/fixtures/min-classes-boundary/ tests/fixtures/limit-zero-disables-error/ tests/fixtures/warn-limit-zero-disables-warn/ tests/fixtures/warn-limit-below-limit-allowed/
git commit -m "test(#13): fixtures for min-classes and 0-disables behavior

Five new fixtures:
- min-classes-gated: chain below min-classes is suppressed
- min-classes-boundary: chain at exactly min-classes is reported
- limit-zero-disables-error: --limit 0 with --warn-limit 4 fires
  warnings only (error tier off)
- warn-limit-zero-disables-warn: --warn-limit 0 disables warnings
- warn-limit-below-limit-allowed: --limit 0 --warn-limit 0 is
  accepted (relaxed guard)"
```

---

## Self-review checklist

After all 8 tasks:

- [ ] `composer check` green (cs + stan + md + test).
- [ ] `composer infection:diff` green (minMsi ≥ 80).
- [ ] Help text, README, and `docs/plan.md` agree with actual flags and defaults.
- [ ] `phptramp.dist.json` at `limit: 6, warnLimit: 4` (dogfood matches new defaults).
- [ ] No test relies on old defaults (`limit: 3, warnLimit: null`) — all set explicitly.
- [ ] Every `expected-findings.json` has `"minClasses"`.
- [ ] `--min-classes 0` (default) changes no existing behavior beyond the JSON field.
