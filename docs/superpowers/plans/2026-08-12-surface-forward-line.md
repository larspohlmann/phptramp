# Surface the forwarding call-site line per hop — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make duplicate findings (one method forwarding a param to the same callee via multiple call sites) distinguishable in every reporter by surfacing `Hop::forwardLine`.

**Architecture:** No finding-emission or fingerprint change. `ChainTraversal` still emits one Finding per root-to-terminal path (Option B, not dedup). The four reporters that currently render only `$hop->line` (declaration) gain `forwardLine`: text/pretty append `→N` to the location string; github/sarif shift their `line=`/`startLine` to `forwardLine ?? $hop->line` for annotated hops. JSON already emits `forwardLine`; Checkstyle is origin-only and unchanged. Fingerprint unchanged (refactor-stable by design).

**Tech Stack:** PHP 8.2+, nikic/php-parser ^5, PHPUnit, PHP_CodeSniffer, PHPStan, PHPMD, Infection.

## Global Constraints

- PHP ≥ 8.2, `declare(strict_types=1)` in every file.
- Clean Code: `final class`, `readonly` promoted properties, guard clauses, few parameters, names reveal intent.
- `composer check` MUST be green before every commit (cs + stan + md + test).
- Conventional commits with issue number: `feat(#17): …`, `test(#17): …`, `docs(#17): …`.
- Fixture-first TDD: write the failing test, confirm it fails for the expected reason, implement, go green.
- Branch: `feature/17-surface-forward-line` (already created). PR merges into `develop`. `Closes #17` in the PR body.
- Semantics frozen in `docs/plan.md` — the §2.3 amendment is a clarification, not a semantics change; flag in commit message.

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `tests/fixtures/same-callee-two-callsites/src/Demo.php` | Fixture input: method forwards `$p` to same callee twice | Create |
| `tests/fixtures/same-callee-two-callsites/expected-findings.json` | Pins two-findings emission, shared fingerprint, distinct forwardLine | Create |
| `tests/fixtures/same-callee-two-callsites/phptramp-args.json` | CLI args for folder mode | Create |
| `src/Report/TextReporter.php` | `location()` appends `→forwardLine` | Modify |
| `src/Report/PrettyReporter.php` | `location()` appends `→forwardLine` | Modify |
| `src/Report/GithubReporter.php` | `line=` uses `forwardLine ?? $hop->line` | Modify |
| `src/Report/SarifReporter.php` | `relatedLocations` `startLine` uses `forwardLine ?? $hop->line`; origin `locations[0]` keeps declaration | Modify |
| `tests/Report/TextReporterTest.php` | Update expected strings with `→N` | Modify |
| `tests/Report/PrettyReporterTest.php` | Update expected strings with `→N` | Modify |
| `tests/Report/GithubReporterTest.php` | Update expected `line=` values | Modify |
| `tests/Report/SarifReporterTest.php` | Update expected `startLine` values | Modify |
| `README.md` | Update example blocks + explain `→N` | Modify |
| `docs/plan.md` | Amend §2.3 branching rule | Modify |

---

### Task 1: Fixture — same-callee two-callsite emission

This fixture documents and pins the emission rule: a method that forwards `$p` to the same static callee via two distinct call sites produces **two findings** (not deduped), sharing origin/param/terminal but differing in the divergent hop's `forwardLine`. It is a documentation fixture — under Option B it passes immediately (JSON already emits `forwardLine`); its job is to guard against a future accidental dedup (Option A) regression.

**Files:**
- Create: `tests/fixtures/same-callee-two-callsites/src/Demo.php`
- Create: `tests/fixtures/same-callee-two-callsites/expected-findings.json`
- Create: `tests/fixtures/same-callee-two-callsites/phptramp-args.json`

**Interfaces:**
- Consumes: `tests/FixtureTest.php` harness (auto-discovers any `tests/fixtures/*/expected-findings.json` in folder mode with `phptramp-args.json`).
- Produces: a green fixture asserting two findings with `forwardLine` 16 and 20 on the divergent hop.

- [ ] **Step 1: Write the fixture input**

`tests/fixtures/same-callee-two-callsites/src/Demo.php`:

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
        (new Middle())->relay($config);
    }
}

class Middle
{
    public function relay(Cfg $config): void
    {
        (new Divergent())->forwardTwice($config);
    }
}

class Divergent
{
    public function forwardTwice(Cfg $config): void
    {
        Terminal::first($config);
        Terminal::second($config);
    }
}

class Terminal
{
    public static function first(Cfg $config): void
    {
        $config->go();
    }

    public static function second(Cfg $config): void
    {
        $config->go();
    }
}
```

- [ ] **Step 2: Write the CLI args file**

`tests/fixtures/same-callee-two-callsites/phptramp-args.json`:

```json
["--limit", "1", "--warn-limit", "0"]
```

- [ ] **Step 3: Run the fixture to capture actual output**

Run: `composer test -- --filter=testFindingsMatchExpectation --no-coverage tests/FixtureTest.php`

The test fails with "expected vs actual" mismatch (no `expected-findings.json` yet → the harness reads it and fails). Inspect the actual JSON the harness produces by temporarily dumping it. Easiest: run the pipeline directly to capture the actual findings JSON:

```bash
bin/phptramp --folder tests/fixtures/same-callee-two-callsites/src --no-config --no-cache --format json --limit 1 --warn-limit 0
```

Copy the `findings` array from the output. It contains two findings, both `Demo\Origin::run → Demo\Terminal::first` and `Demo\Origin::run → Demo\Terminal::second`, each with `hops: 3`, identical except `chain[2]` (the `Divergent::forwardTwice` hop) has `forwardLine` 38 for one and 39 for the other (the two `Terminal::…` call lines). Confirm the two `forwardLine` values match the two call-site lines in `forwardTwice()`.

- [ ] **Step 4: Write `expected-findings.json` from the captured output**

`tests/fixtures/same-callee-two-callsites/expected-findings.json` — paste the captured document, then normalize the `file` values to case-relative (`src/Demo.php`) to match the harness's `normalizeFindingsDocument` expectation (folder mode strips the `tests/fixtures/same-callee-two-callsites/` prefix). The final shape:

```json
{
    "limit": 1,
    "warnLimit": 0,
    "minClasses": 0,
    "findings": [
        {
            "param": "config",
            "severity": "error",
            "origin": "Demo\\Origin::run",
            "terminal": "Demo\\Terminal::first",
            "terminalKind": "used",
            "hops": 3,
            "classes": 4,
            "chain": [
                {"method": "Demo\\Origin::run", "role": "origin", "file": "src/Demo.php", "line": 11, "forwardLine": 13},
                {"method": "Demo\\Middle::relay", "role": "hop", "file": "src/Demo.php", "line": 19, "forwardLine": 21},
                {"method": "Demo\\Divergent::forwardTwice", "role": "hop", "file": "src/Demo.php", "line": 27, "forwardLine": 29},
                {"method": "Demo\\Terminal::first", "role": "terminal", "file": "src/Demo.php", "line": 33, "forwardLine": null}
            ],
            "notes": []
        },
        {
            "param": "config",
            "severity": "error",
            "origin": "Demo\\Origin::run",
            "terminal": "Demo\\Terminal::second",
            "terminalKind": "used",
            "hops": 3,
            "classes": 4,
            "chain": [
                {"method": "Demo\\Origin::run", "role": "origin", "file": "src/Demo.php", "line": 11, "forwardLine": 13},
                {"method": "Demo\\Middle::relay", "role": "hop", "file": "src/Demo.php", "line": 19, "forwardLine": 21},
                {"method": "Demo\\Divergent::forwardTwice", "role": "hop", "file": "src/Demo.php", "line": 27, "forwardLine": 30},
                {"method": "Demo\\Terminal::second", "role": "terminal", "file": "src/Demo.php", "line": 38, "forwardLine": null}
            ],
            "notes": []
        }
    ]
}
```

**IMPORTANT:** the `line`/`forwardLine` values above are *predicted from the source layout*. Re-confirm each value against the actual `bin/phptramp … --format json` output from Step 3 before saving — if the source lines shifted during editing, update the JSON to match the actual output. The harness compares with `assertEquals` (not `assertSame`), so int-vs-string is tolerated, but wrong numbers fail.

- [ ] **Step 5: Run the fixture test to verify it passes**

Run: `composer test -- --filter=testFindingsMatchExpectation --no-coverage tests/FixtureTest.php`

Expected: PASS (Option B already emits two findings; JSON already carries `forwardLine`).

- [ ] **Step 6: Run `composer check`**

Run: `composer check`

Expected: green. (The fixture file is under `tests/fixtures/` and excluded from PSR-12 per CLAUDE.md; no src/ file changed.)

- [ ] **Step 7: Commit**

```bash
git add tests/fixtures/same-callee-two-callsites/
git commit -m "test(#17): fixture for same-callee two-callsite emission

A method that forwards a param to the same callee via two distinct
call sites yields two findings (Option B, not deduped). They share
origin/param/terminal and differ only in the divergent hop's
forwardLine, which every reporter surfaces. The fingerprint is shared
by design (refactor-stable). Pins the emission rule against a future
accidental dedup regression."
```

---

### Task 2: TextReporter — append `→forwardLine` to hop location

**Files:**
- Modify: `src/Report/TextReporter.php` (the `location()` private method, lines 149-152)
- Test: `tests/Report/TextReporterTest.php`

**Interfaces:**
- Consumes: `PhpTramp\Chain\Hop` (already imported) with `?int $forwardLine`.
- Produces: location strings of the form `file:line→forwardLine` (non-terminal) or `file:line` (terminal / null forwardLine).

- [ ] **Step 1: Update the driving test — `testRendersStoredChainWithAlignedColumns`**

In `tests/Report/TextReporterTest.php`, change the `$expected` heredoc of `testRendersStoredChainWithAlignedColumns` (lines 37-46) to append `→forwardLine` on the three non-terminal hops:

```php
        $expected = <<<'TXT'
            FINDING  $config: 3 pass-through hops across 4 classes
              origin    Demo\Controller::handle($config)   src/Demo.php:12→14
              hop 2     Demo\ServiceA::process($config)    src/Demo.php:18→20
              hop 3     Demo\ServiceB::run($config)        src/Demo.php:24→26
              terminal  Demo\Mailer::__construct($config)  src/Demo.php:32  (stored)

            1 finding (limit: 3 hops).

            TXT;
```

- [ ] **Step 2: Update the remaining driving tests in the same file**

Apply the `→N` suffix to every non-terminal hop's location in these tests (terminal hops keep plain `file:line`):

- `testMarksChangedNonTerminalHopWithYoursAnnotation` (lines 73-82): origin `:12→14`, hop 2 `:18→20`, hop 3 `:24→26`, terminal `:32` unchanged.
- `testRendersTruncatedChainWithNoteLineAndNoTerminal` (lines 107-115): origin `:5→7`, hop 2 `:9→11` (no terminal in this chain — both hops are non-terminal).
- `testRendersSingularFormsForOneHopOneClassOneFinding` (lines 130-137): origin `:5→7`, terminal `:9` unchanged.
- `testExplainAppendsTraceLinesUnderHeader` (lines 153-162): origin `:5→7`, terminal `:9` unchanged.
- `testRendersSingleWarningFindingAndFiltersBelowWarnLimit` (lines 214-222): origin `:5→7`, hop 2 `:9→11`, terminal `:13` unchanged.
- `testRelativizesAbsoluteFilePathUnderWorkingDirectory` (lines 240-241): the single hop is `new Hop(..., 5, null)` with `hops=1` — chain[0] is both origin and terminal (count(chain) == hops), so `forwardLine` is null → no `→N`. The assertion `self::assertStringContainsString('src/Demo.php:5', $output)` still holds. **No change.**
- `testFooterShowsMinClassesWhenSet` (lines 252-258): origin `:5→7`, terminal `:9` unchanged.
- `testRendersMixedErrorAndWarningFindingsWithCombinedSummary` (lines 305-319): update every non-terminal hop — error finding origin `:5→7`, hop 2 `:9→11`, hop 3 `:15→17`, terminal `:13` unchanged; warning finding origin `:5→7`, hop 2 `:9→11`, terminal `:13` unchanged.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `composer test -- --filter=TextReporterTest --no-coverage`

Expected: FAIL — the reporter still renders `src/Demo.php:12` (no `→14`), so the first assertion mismatches.

- [ ] **Step 4: Implement `TextReporter::location()`**

In `src/Report/TextReporter.php`, replace the `location()` method (lines 149-152):

```php
    private function location(Hop $hop): string
    {
        return $this->paths->relativize($hop->file) . ':' . $hop->line;
    }
```

with:

```php
    private function location(Hop $hop): string
    {
        $location = $this->paths->relativize($hop->file) . ':' . $hop->line;
        if ($hop->forwardLine !== null) {
            $location .= '→' . $hop->forwardLine;
        }

        return $location;
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `composer test -- --filter=TextReporterTest --no-coverage`

Expected: PASS.

- [ ] **Step 6: Run `composer check`**

Run: `composer check`

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add src/Report/TextReporter.php tests/Report/TextReporterTest.php
git commit -m "feat(#17): surface forwarding call-site line in text reporter

Append '→N' (the forwarding call-site line) to each non-terminal
hop's location so findings that share origin/param/terminal but
differ in call site are distinguishable. Terminal hops keep the
plain 'file:line' form. Closes part of #17."
```

---

### Task 3: PrettyReporter — append `→forwardLine` to hop location

The PrettyReporter's `location()` is identical to TextReporter's and feeds its output through `$this->styler->location(...)`; the `→N` suffix becomes part of the styled string and inherits the location style. NullStyler (used in tests) passes the string through unchanged, so the byte-for-byte assertions gain `→N` exactly like TextReporter.

**Files:**
- Modify: `src/Report/PrettyReporter.php` (the `location()` private method, lines 222-225)
- Test: `tests/Report/PrettyReporterTest.php`

**Interfaces:**
- Consumes: `PhpTramp\Chain\Hop` with `?int $forwardLine`.
- Produces: same `file:line→forwardLine` form as TextReporter.

- [ ] **Step 1: Update the driving tests in `tests/Report/PrettyReporterTest.php`**

Apply the `→N` suffix to every non-terminal hop in every test that asserts byte-for-byte text. Read the file in full first (`read` tool) to find every expected heredoc. The affected tests (by method name) and their non-terminal hops:

- `testRendersSingleFindingWithFileHeaderAndDividerAndSummary`: origin `:12→14`, hop 2 `:18→20`, hop 3 `:24→26`, terminal `:32` unchanged.
- `testRendersWarningHeaderAtWarnLimit`: origin `:5→7`, terminal `:9` unchanged.
- `testMarksChangedNonTerminalHopWithYoursAnnotation`: origin `:12→14`, hop 2 `:18→20` (keeps `*YOURS*`), hop 3 `:24→26`, terminal `:32` unchanged.
- Every other test in the file that renders a chain — read through line 576 and apply `→N` to each non-terminal hop whose `Hop` constructor passes a non-null `forwardLine`. Terminal hops (the last `Hop` in each chain with `forwardLine: null`) keep plain `file:line`.

For each test, the `→N` value is the `forwardLine` argument already present in that test's `new Hop(...)` constructor call — copy it verbatim.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter=PrettyReporterTest --no-coverage`

Expected: FAIL (locations still lack `→N`).

- [ ] **Step 3: Implement `PrettyReporter::location()`**

In `src/Report/PrettyReporter.php`, replace `location()` (lines 222-225):

```php
    private function location(Hop $hop): string
    {
        return $this->paths->relativize($hop->file) . ':' . $hop->line;
    }
```

with:

```php
    private function location(Hop $hop): string
    {
        $location = $this->paths->relativize($hop->file) . ':' . $hop->line;
        if ($hop->forwardLine !== null) {
            $location .= '→' . $hop->forwardLine;
        }

        return $location;
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer test -- --filter=PrettyReporterTest --no-coverage`

Expected: PASS.

- [ ] **Step 5: Run `composer check`**

Run: `composer check`

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Report/PrettyReporter.php tests/Report/PrettyReporterTest.php
git commit -m "feat(#17): surface forwarding call-site line in pretty reporter

Same '→N' suffix as TextReporter; inherits the location style
through Styler::location(). Closes part of #17."
```

---

### Task 4: GithubReporter — anchor `line=` at the forwarding call site

Each `::notice` and the anchor `::error`/`::warning` currently set `line=` to `$hop->line` (declaration). Shift to `forwardLine ?? $hop->line` so the GitHub annotation lands on the forwarding call — the actionable line. The diff-aware changed-anchor logic (keyed on `$hop->changed`) is untouched; only the `line=` value changes.

**Files:**
- Modify: `src/Report/GithubReporter.php` (the `anchorAnnotation()` and `hopNotice()` methods)
- Test: `tests/Report/GithubReporterTest.php`

**Interfaces:**
- Consumes: `PhpTramp\Chain\Hop` with `?int $forwardLine`.
- Produces: `line=` values pointing at the forwarding call site when available.

- [ ] **Step 1: Update the driving test — `testEmitsErrorAnnotationAtOriginAndNoticePerSubsequentHop`**

In `tests/Report/GithubReporterTest.php`, the expected string (lines 46-51) currently uses declaration lines. The chain is:

```php
new Hop('Demo\Controller::handle', ..., 12, 14),  // origin, forwardLine 14
new Hop('Demo\ServiceA::process', ..., 18, 20),    // hop 2, forwardLine 20
new Hop('Demo\ServiceB::run', ..., 24, 26),        // hop 3, forwardLine 26
new Hop('Demo\Mailer::__construct', ..., 32, null), // terminal (not annotated)
```

Update the expected to use `line=14`, `line=20`, `line=26`:

```php
        $expected = "::error file=src/Demo.php,line=14,title=phptramp%3A%3A\$config%3A 3 pass-through hops "
            . "across 4 classes (terminal%3A Demo\\Mailer%3A%3A__construct [stored])\n"
            . "::notice file=src/Demo.php,line=20,title=phptramp%3A%3Ahop 2 of \$config chain from "
            . "Demo\\Controller%3A%3Ahandle\n"
            . "::notice file=src/Demo.php,line=26,title=phptramp%3A%3Ahop 3 of \$config chain from "
            . "Demo\\Controller%3A%3Ahandle\n";
```

- [ ] **Step 2: Update `testAnchorsAtFirstChangedNonTerminalHopWithSuffixAndOriginAsNotice`**

The changed hop is hop 2 (`Demo\ServiceA::process`, declaration 18, forwardLine 20, `changed=true`). The anchor `::error` lands on the changed hop (now `line=20`); the origin becomes a `::notice` (now `line=14`); hop 3 stays a `::notice` (now `line=26`). Update lines 78-84:

```php
        $expected = "::error file=src/Demo.php,line=20,title=phptramp%3A%3A\$config%3A 3 pass-through hops "
            . "across 4 classes (terminal%3A Demo\\Mailer%3A%3A__construct [stored]) (hop 2 of the chain%2C "
            . "changed by this diff)\n"
            . "::notice file=src/Demo.php,line=14,title=phptramp%3A%3Ahop 1 of \$config chain from "
            . "Demo\\Controller%3A%3Ahandle\n"
            . "::notice file=src/Demo.php,line=26,title=phptramp%3A%3Ahop 3 of \$config chain from "
            . "Demo\\Controller%3A%3Ahandle\n";
```

- [ ] **Step 3: Update `testEmitsWarningAnnotationAndOmitsNoticeWhenNoSubsequentHops`**

Chain: `new Hop('Demo\A::go', ..., 5, 7)` (origin, forwardLine 7), `new Hop('Demo\A::sink', ..., 9, null)` (terminal, hops=1 so only origin is non-terminal). The anchor `::warning` shifts from `line=5` to `line=7` (forwardLine). Update lines 99-100:

```php
        $expected = "::warning file=src/A.php,line=7,title=phptramp%3A%3A\$p%3A 1 pass-through hop across 1 class "
            . "(terminal%3A Demo\\A%3A%3Asink [used])\n";
```

- [ ] **Step 4: Update the remaining tests in the file**

- `testEscapesPercentCarriageReturnAndNewlineInAnnotationData`: chain is `new Hop("Demo\\A::go\r\nInjected", ..., 5, null)` — `forwardLine` is null, so `line=` stays `5`. **No change.**
- `testEscapesCommaInAnnotationPropertyValue`: chain is `new Hop(..., 5, null)` — `forwardLine` null, `line=5` unchanged. **No change.**
- `testStillReportsQualifyingFindingAfterAnEarlierBelowThresholdFindingIsSkipped`: the `aboveFinding` chain is the 3-hop chain (origin 12→14, hop 2 18→20, hop 3 24→26). Update its expected `line=` values to `14`, `20`, `26` (lines 207-212). The `belowFinding` is below threshold and never rendered, so no change to it.
- `testOmitsBelowThresholdFindingEntirely` and `testEmptyRunRendersEmptyString`: no rendered hops; **no change.**

- [ ] **Step 5: Run the tests to verify they fail**

Run: `composer test -- --filter=GithubReporterTest --no-coverage`

Expected: FAIL (`line=12` vs expected `line=14`, etc.).

- [ ] **Step 6: Implement the `line=` shift in `GithubReporter`**

In `src/Report/GithubReporter.php`, edit `anchorAnnotation()` (lines 74-83). Replace the `line=` segment:

```php
            . ',line=' . $anchor->line
```

with:

```php
            . ',line=' . ($anchor->forwardLine ?? $anchor->line)
```

Then edit `hopNotice()` (lines 94-101). Replace:

```php
            . ',line=' . $hop->line
```

with:

```php
            . ',line=' . ($hop->forwardLine ?? $hop->line)
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `composer test -- --filter=GithubReporterTest --no-coverage`

Expected: PASS.

- [ ] **Step 8: Run `composer check`**

Run: `composer check`

Expected: green.

- [ ] **Step 9: Commit**

```bash
git add src/Report/GithubReporter.php tests/Report/GithubReporterTest.php
git commit -m "feat(#17): anchor github annotations at the forwarding call site

line= now uses forwardLine ?? hop->line so the annotation lands on
the forwarding call (the actionable line) rather than the method
declaration. The diff-aware changed-anchor selection is untouched.
Closes part of #17."
```

---

### Task 5: SarifReporter — `relatedLocations` startLine at the forwarding call site

`locations[0]` (the origin) keeps the declaration line — it is the "where this chain starts" primary anchor and its declaration line is the natural entry point. `relatedLocations` (hops 1 .. hops-1) shift `region.startLine` to `forwardLine ?? $hop->line` so each related location points at the forwarding call.

**Files:**
- Modify: `src/Report/SarifReporter.php` (the `relatedLocations()` and `location()` methods)
- Test: `tests/Report/SarifReporterTest.php`

**Interfaces:**
- Consumes: `PhpTramp\Chain\Hop` with `?int $forwardLine`.
- Produces: SARIF `region.startLine` at the forwarding call for relatedLocations; declaration for the origin location.

- [ ] **Step 1: Update the driving test — `testRendersErrorFindingWithLocationAndRelatedLocationPerSubsequentHop`**

In `tests/Report/SarifReporterTest.php`, the chain (lines 32-35) is the 3-hop chain: origin (12, 14), hop 2 (18, 20), hop 3 (24, 26), terminal (32, null). `locations[0]` (origin) keeps `startLine: 12`. The two `relatedLocations` (hop 2 and hop 3) shift from 18→20 and 24→26. Update the heredoc (lines 88-114): change the first relatedLocation's `"startLine": 18` to `"startLine": 20`, and the second's `"startLine": 24` to `"startLine": 26`. The origin's `"startLine": 12` (line 83) is unchanged.

- [ ] **Step 2: Update other Sarif tests**

- `testRendersWarningFindingAndOmitsRelatedLocationsWhenNoSubsequentHops`: chain origin (5, 7), terminal (9, null), hops=1 → no `relatedLocations`. `locations[0]` is the origin; `startLine` keeps declaration `5`. **No change.**
- `testStillIncludesQualifyingFindingAfterAnEarlierBelowThresholdFindingIsSkipped`: uses `json_decode` + structural assertions on `message.text`, not byte-for-byte `startLine`. **No change.**
- `testIncludesBothResultsWhenMultipleFindingsQualify`: same — structural assertions only. **No change.**
- `testOmitsBelowThresholdFindingEntirely`, `testEmptyRunRendersValidDocumentWithEmptyResults`: no rendered hops. **No change.**

- [ ] **Step 3: Run the tests to verify they fail**

Run: `composer test -- --filter=SarifReporterTest --no-coverage`

Expected: FAIL (`startLine: 18` vs expected `20`).

- [ ] **Step 4: Implement the `startLine` shift in `SarifReporter`**

In `src/Report/SarifReporter.php`, the `location()` method (lines 128-136) builds the `physicalLocation` for both the origin and the related locations. To keep the origin on its declaration line while shifting related locations, split the logic: `relatedLocations()` (lines 110-123) builds its own location with `forwardLine ?? $hop->line`, and `location()` stays as the declaration-only version for the origin.

Replace `relatedLocations()` (lines 110-123):

```php
    private function relatedLocations(Finding $finding): array
    {
        $locations = [];
        for ($index = 1; $index < $finding->hops; $index++) {
            $hop = $finding->chain[$index];
            $location = $this->location($hop);
            $location['message'] = [
                'text' => 'hop ' . ($index + 1) . ' of $' . $finding->param . ' chain from ' . $finding->origin,
            ];
            $locations[] = $location;
        }

        return $locations;
    }
```

with a version that uses `forwardLine ?? $hop->line` for the region:

```php
    private function relatedLocations(Finding $finding): array
    {
        $locations = [];
        for ($index = 1; $index < $finding->hops; $index++) {
            $hop = $finding->chain[$index];
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

Leave `location()` (the origin's, lines 128-136) unchanged — it still uses `$hop->line` (declaration).

- [ ] **Step 5: Run the tests to verify they pass**

Run: `composer test -- --filter=SarifReporterTest --no-coverage`

Expected: PASS.

- [ ] **Step 6: Run `composer check`**

Run: `composer check`

Expected: green.

- [ ] **Step 7: Commit**

```bash
git add src/Report/SarifReporter.php tests/Report/SarifReporterTest.php
git commit -m "feat(#17): point SARIF relatedLocations at the forwarding call site

relatedLocations now use forwardLine ?? hop->line for region.startLine
so each hop's related location points at the forwarding call. The
origin locations[0] keeps the declaration line as the primary anchor.
Closes part of #17."
```

---

### Task 6: README + docs/plan.md update

**Files:**
- Modify: `README.md` (the two example blocks around lines 24-27 and 50-53, plus a one-line explanation)
- Modify: `docs/plan.md` (§2.3 "Branching", around lines 670-676)

**Interfaces:**
- Consumes: the `→N` format shipped in Tasks 2-3.
- Produces: documented examples and the clarified branching rule.

- [ ] **Step 1: Update the README canonical example (lines 20-32)**

Replace the block:

```text
$ vendor/bin/phptramp --folder src --limit 3

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)     src/Http/Controller.php:21
  hop 2     App\Service\ServiceA::process($config)   src/Service/ServiceA.php:14
  hop 3     App\Service\ServiceB::run($config)       src/Service/ServiceB.php:9
  terminal  App\Mail\Mailer::__construct($config)    src/Mail/Mailer.php:12  (stored)
```

with:

```text
$ vendor/bin/phptramp --folder src --limit 3

FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)     src/Http/Controller.php:21→23
  hop 2     App\Service\ServiceA::process($config)   src/Service/ServiceA.php:14→16
  hop 3     App\Service\ServiceB::run($config)       src/Service/ServiceB.php:9→11
  terminal  App\Mail\Mailer::__construct($config)    src/Mail/Mailer.php:12  (stored)
```

(The `→N` values are illustrative — the forwarding call-site line within each method. The terminal hop has no forwarding call, so it keeps the plain `file:line` form.)

- [ ] **Step 2: Update the README diff-aware example (lines 46-56)**

Replace:

```text
FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)    src/Http/Controller.php:21
  hop 2     App\Service\ServiceA::process($config)  src/Service/ServiceA.php:14  *YOURS*
  hop 3     App\Service\ServiceB::run($config)      src/Service/ServiceB.php:9
  terminal  App\Mail\Mailer::__construct($config)   src/Mail/Mailer.php:12  (stored)
```

with:

```text
FINDING  $config: 3 pass-through hops across 4 classes
  origin    App\Http\Controller::handle($config)    src/Http/Controller.php:21→23
  hop 2     App\Service\ServiceA::process($config)  src/Service/ServiceA.php:14→16  *YOURS*
  hop 3     App\Service\ServiceB::run($config)      src/Service/ServiceB.php:9→11
  terminal  App\Mail\Mailer::__construct($config)   src/Mail/Mailer.php:12  (stored)
```

- [ ] **Step 3: Add the `→N` explanation to the README**

Immediately after the diff-aware example's closing ` ```text ` block (around line 56), or right after the line "The origin is hop 1…" (lines 30-32), add a short paragraph:

> The `→N` after a hop's `file:line` is the **forwarding call-site line** — the line inside that method where the parameter is forwarded on. Terminal hops have no forwarding call, so they show only `file:line`. When a method forwards the same parameter to the same callee via multiple call sites, each produces its own finding distinguished by this `→N` value.

- [ ] **Step 4: Amend `docs/plan.md` §2.3 (Branching)**

Find the "Branching" bullet (around line 672, the one starting "Branching: every outgoing edge is explored"). Append a sentence covering the same-terminal case:

> A param forwarded to the same callee via multiple call sites within one method yields one Finding per call site; they share origin, param, and terminal and differ only in the divergent hop's `forwardLine`, which every reporter surfaces. They share a fingerprint (the fingerprint excludes lines by design).

- [ ] **Step 5: Run `composer check`**

Run: `composer check`

Expected: green (docs only).

- [ ] **Step 6: Commit**

```bash
git add README.md docs/plan.md
git commit -m "docs(#17): document forwarding call-site line in output and plan

README examples now show the '→N' forwarding call-site suffix, with
a short explanation. docs/plan.md §2.3 (Branching) is amended to
cover the same-callee-multiple-call-site case explicitly: N findings,
shared fingerprint, distinguished by forwardLine. Clarification, not
a semantics change."
```

---

### Task 7: Mutation testing + manual verification gate

**Files:** none (verification only).

- [ ] **Step 1: Run `composer infection:diff`**

```bash
git fetch origin develop
composer infection:diff
```

Inspect `infection.log` (or `--show-mutations=max`) for escaped mutants in the touched files: `src/Report/TextReporter.php`, `src/Report/PrettyReporter.php`, `src/Report/GithubReporter.php`, `src/Report/SarifReporter.php`. An escaped mutant on the `forwardLine ?? $hop->line` selection or the `→` formatting is a real test gap — add a test that would fail if that line were wrong (e.g. a hop with `forwardLine: null` asserting the plain `file:line` form is still produced, pinning the `?? $hop->line` fallback). Re-run until no escaped mutants on the touched code.

- [ ] **Step 2: Manual reproduction against the original issue**

```bash
bin/phptramp --folder /Users/lars/Documents/work/eigenes/simple-feed-reader/backend/src --min-classes=2 --limit=6 --warn-limit=5
```

Confirm the two `JsonLdLayer::article()` findings are now distinguishable: the divergent hop (`hop 4 App\Service\Scraper\Layer\JsonLdLayer::article($baseUrl)`) shows `…:164→166` in one finding and `…:164→180` in the other. The two warnings (5-hop) likewise differ at the `article` hop's `→N`.

- [ ] **Step 3: Run the full `composer check` once more**

Run: `composer check`

Expected: green.

- [ ] **Step 4: Push the branch and open the PR**

```bash
git push -u origin feature/17-surface-forward-line
gh pr create --base develop --title "feat(#17): surface forwarding call-site line per hop" --body "Closes #17.

See docs/superpowers/specs/2026-08-12-surface-forward-line-design.md for the design and docs/superpowers/plans/2026-08-12-surface-forward-line.md for the implementation plan.

## Summary

Surface Hop::forwardLine in every reporter that previously hid it, so duplicate findings (one method forwarding a param to the same callee via multiple call sites) become distinguishable. No finding-emission or fingerprint change (Option B).

- text/pretty: append '→N' to each non-terminal hop's location.
- github: line= uses forwardLine ?? hop->line for all annotations.
- sarif: relatedLocations startLine uses forwardLine ?? hop->line; origin keeps declaration.
- json: already emitted forwardLine; unchanged.
- checkstyle: origin-only summary; unchanged.
- fingerprint: unchanged (refactor-stable by design).
"
```

---

## Self-Review

**1. Spec coverage:**
- TextReporter `→N`: Task 2. ✓
- PrettyReporter `→N`: Task 3. ✓
- GithubReporter `line=` shift: Task 4. ✓
- SarifReporter `relatedLocations` shift, origin keeps declaration: Task 5. ✓
- CheckstyleReporter unchanged: confirmed (no task touches it). ✓
- JsonReporter unchanged: confirmed (no task touches it). ✓
- Fingerprint unchanged: confirmed (no task touches `src/Baseline/Fingerprint.php`). ✓
- New fixture `same-callee-two-callsites`: Task 1. ✓
- README example + explanation: Task 6. ✓
- docs/plan.md §2.3 amendment: Task 6. ✓
- `composer check` green: every task. ✓
- `composer infection:diff`: Task 7. ✓

**2. Placeholder scan:** Step 4 of Task 1 warns to re-confirm line numbers against actual output — that's a verification step, not a placeholder. No TBD/TODO. All code blocks contain real code.

**3. Type consistency:** `Hop::forwardLine` is `?int` everywhere. The `?? $hop->line` fallback is used consistently in Github (Tasks 4) and Sarif (Task 5). The `→' . $hop->forwardLine` string concat in Text/Pretty (Tasks 2-3) is guarded by `if ($hop->forwardLine !== null)`. Method names match: `location()`, `anchorAnnotation()`, `hopNotice()`, `relatedLocations()`.
