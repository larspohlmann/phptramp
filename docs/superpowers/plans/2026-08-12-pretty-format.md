# `--format pretty` colored terminal output + `--color` flag + new default

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a seventh output format, `pretty` — well-formatted, colored terminal output that becomes the new default when STDOUT is a TTY (non-TTY falls back to `text` when `--color=auto`, the default; `--color=never` keeps plain `pretty` in a pipe, `--color=always` keeps colored `pretty`). The current `text` format remains opt-in via `--format text` and is frozen.

**Architecture:** A new `PrettyReporter` (file-grouped, line-sorted, structural typography, no borders) renders findings via an injected `Styler` — `NullStyler` (plain) or `AnsiStyler` (8-color). A pure `ColorPolicy::from(string $mode, bool $tty, bool $noColorSet): Styler` resolves which Styler to use; `Application` is the only place that reads `stream_isatty(STDOUT)` and `getenv('NO_COLOR')`, passing booleans into the policy. `Options` gains a `colorMode` string (`always`|`auto`|`never`, default `auto`) and its `format` default flips from `text` to `pretty`. `Application` downgrades `pretty` → `text` when STDOUT is not a TTY, so pipes/CI get clean plain output. `text` rendering and its exact-string tests are untouched.

**Tech Stack:** unchanged — PHP ≥ 8.2, nikic/php-parser ^5 (only prod dependency). All ANSI is 8-color, self-emitted; no new dependency, no Unicode-width handling, no box-drawing, no icons beyond `FINDING`/`WARNING`.

## Global Constraints

- Everything in `CLAUDE.md` applies: `composer check` green before every commit, PHPMD-clean for every touched `src/` file, Clean Code house style, strict fixture-first TDD, one task per commit.
- **GitHub issue for this feature: #15.** Branch `feature/15-pretty-format` off `develop` (already created, carries this plan). Commits `type(#15): …`. PR into `develop` with `Closes #15`; diff-scoped Infection gate (minMsi 80) applies.
- **No second production dependency.** All ANSI escapes are 8-color (`\e[3Xm` / `\e[9Xm` / `\e[1m` / `\e[2m` / `\e[0m`), self-emitted by `AnsiStyler`. No `wcwidth`, no box-drawing, no emoji.
- **`text` is frozen.** `TextReporter` and `tests/Report/TextReporterTest.php` are not modified by this plan. Their exact-string fixtures stay green. (If a shared extraction becomes desirable later, that is a separate refactor PR — not in scope here.)
- **No boolean flag params that select behaviour inside rendering.** Color on/off lives in the injected `Styler`; `PrettyReporter` has zero color-branch logic.
- **`--color` flag shape:** `--color=always|auto|never` (git-style), default `auto`. Validated against `['always','auto','never']` in `ArgvParser`, same shape as `--format`.
- **`NO_COLOR` precedence:** `always`/`never` are absolute over `NO_COLOR`; `auto` honors `NO_COLOR` (any non-empty value → `NullStyler`). Truth table (Q11):
  - `always` → `AnsiStyler` (regardless of `tty`/`noColorSet`).
  - `never` → `NullStyler` (regardless of `tty`/`noColorSet`).
  - `auto` + `tty=true` + `noColorSet=false` → `AnsiStyler`.
  - `auto` + `tty=true` + `noColorSet=true` → `NullStyler`.
  - `auto` + `tty=false` → `NullStyler` (regardless of `noColorSet`).
- **Default flip is gated on the TTY-downgrade wiring** (Task 9): the `Options::$format` default → `pretty` change lands in the same commit as `Application`'s non-TTY downgrade, otherwise every non-TTY test would break.

## Existing interfaces this plan consumes (verbatim)

- `Reporter::render(list<Finding>): string` at `src/Report/Reporter.php:15` — unchanged; `PrettyReporter` implements it.
- `Thresholds::__construct(int $limit, ?int $warnLimit, int $minClasses = 0)` at `src/Report/Thresholds.php:30`; `severityOf(Finding): ?Severity` at line 45 — `PrettyReporter` applies thresholds itself (same as `TextReporter`).
- `Paths::relativize(string): string` at `src/Report/Paths.php:21` — `PrettyReporter` uses it for file headers and locations.
- `Pluralizer::of(int, string, ?string): string` at `src/Report/Pluralizer.php:14` — `PrettyReporter` uses it for hop/class/finding counts.
- `Severity` enum at `src/Report/Severity.php:7` — `Error`/`Warning` cases; `PrettyReporter` maps to `FINDING`/`WARNING` header words.
- `Finding` at `src/Chain/Finding.php:12` — `param`, `origin`, `terminal`, `terminalKind`, `hops`, `chain` (list<Hop>), `classes`, `notes` (list<string>), `trace` (list<string>).
- `Hop` at `src/Chain/Hop.php:13` — `fqmn`, `class`, `file`, `line`, `forwardLine`, `changed`, `param`.
- `TerminalKind` enum at `src/Chain/TerminalKind.php:13` — `Used`/`Stored`/`ByRef`/`Unused`/`External`/`Truncated`; backing values are the annotation tokens.
- `Options::__construct` at `src/Console/Options.php:22` — readonly value object, `@SuppressWarnings(PHPMD)` on the class for the wide constructor. Gains `colorMode` param (Task 4); `format` default flips in Task 9.
- `ArgvParser::parse(array $args, Options $defaults = new Options()): Options` at `src/Console/ArgvParser.php:70` — `VALUE_FLAGS` (line 21), `BOOL_FLAGS` (line 35), `VALID_FORMATS` (line 48), default `format` field (line 57), `applyValueFlag` match (line 162), `validateFormat` (line 249). Gains `--color` + `VALID_COLOR_MODES` + `validateColorMode` (Task 4); `VALID_FORMATS` + default flip in Task 9.
- `ConfigLoader::toOptions()` at `src/Config/ConfigLoader.php:81` — `match ($key)` at line 95 with strict unknown-key errors; `requireString` at line 168. Gains `colorMode` key (Task 5).
- `ReporterFactory::create(Options $options): Reporter` at `src/Report/ReporterFactory.php:17` — `match` over `$options->format` at line 20. Signature changes to `create(Options, Styler)` in Task 7; adds `pretty` arm.
- `Application::analyze()` at `src/Console/Application.php:129` — constructs `ReporterFactory($this->workingDirectory())` at line 136 and calls `->create($options)`; writes `fwrite($this->stdout, $reporter->render($findings))` at line 159. `$this->stdout` is injectable via constructor (line 51). Task 8 adds `ColorPolicy` resolution + `Styler` injection; Task 9 adds the non-TTY downgrade.
- `Application::helpText()` at `src/Console/Application.php:365` — heredoc; Task 10 adds `--color` line and updates the `--format` line.
- `ApplicationTest` at `tests/Console/ApplicationTest.php` — uses `php://memory` streams for stdout/stderr (line 61-62), which are non-TTY; the TTY-downgrade (Task 9) keeps existing default-format assertions green.
- `ReporterFactoryTest` at `tests/Report/ReporterFactoryTest.php` — every `create()` call gains a `Styler` arg in Task 7; `pretty` case added.
- `ArgvParserTest::testDefaultsAreTheDocumentedOnes()` at `tests/Console/ArgvParserTest.php:20` asserts `format` default (line 27); `validFormatProvider()` at line 99 lists valid formats. Both updated in Task 9 (default → `pretty`, list adds `pretty`).
- `OptionsTest` at `tests/Console/OptionsTest.php` — default assertions updated in Tasks 4 + 9.
- `ConfigLoaderTest` at `tests/Config/ConfigLoaderTest.php` — gains `colorMode` cases (Task 5).

## Styler interface — the canonical method list

The `Styler` interface (Task 1) has these per-role methods (one per palette row from Q8). Every method takes `string` and returns `string`; `NullStyler` returns input unchanged, `AnsiStyler` wraps with the listed escapes:

| Method | Role | ANSI |
|---|---|---|
| `severity(string $word, Severity $severity): string` | `FINDING`/`WARNING` header keyword | bold+red (`\e[1;31m`) for Error, bold+yellow (`\e[1;33m`) for Warning |
| `param(string $name): string` | `$param` in header, `($param)` in rows | bold (`\e[1m`) |
| `label(string $label): string` | `origin`/`hop N`/`terminal`/`note` | dim (`\e[2m`) |
| `location(string $loc): string` | `src/Demo.php:18` | dim (`\e[2m`) |
| `annotation(string $a): string` | `*YOURS*` | bold+magenta (`\e[1;35m`) |
| `terminalKind(string $k): string` | `(stored)`/`(used)`/… | dim+green (`\e[2;32m`) |
| `fileHeader(string $path): string` | file-group header | bold+blue (`\e[1;34m`) |
| `divider(string $dashes): string` | the `64`-char `---...---` rule | dim (`\e[2m`) |
| `summary(string $text): string` | `N findings (...)` footer | bold (`\e[1m`) |
| `success(string $text): string` | `No tramp data found (...)` | green (`\e[32m`) |

Every method appends `\e[0m` (reset) after the styled fragment. `AnsiStyler` is the only class that emits `\e`.

---

## Task 1: `Styler` interface + `NullStyler`

**Files:**
- Create: `src/Report/Styler.php`
- Create: `src/Report/NullStyler.php`
- Test: `tests/Report/NullStylerTest.php`

**Interfaces:**
- Consumes: `Severity` enum (for the `severity()` method signature).
- Produces: `Styler` interface (methods listed in the table above); `NullStyler` implementing `Styler` — every method returns its `string` argument unchanged.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\NullStyler;
use PhpTramp\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * NullStyler is the no-op Styler: every method returns its input unchanged.
 * It is the color-off implementation injected when --color=never, when
 * --color=auto on a non-TTY, or when NO_COLOR is set in auto mode. It is also
 * what every PrettyReporter test injects, so layout fixtures assert pure text
 * with zero ANSI noise.
 */
final class NullStylerTest extends TestCase
{
    public function testSeverityReturnsInputUnchanged(): void
    {
        self::assertSame('FINDING', (new NullStyler())->severity('FINDING', Severity::Error));
        self::assertSame('WARNING', (new NullStyler())->severity('WARNING', Severity::Warning));
    }

    public function testParamReturnsInputUnchanged(): void
    {
        self::assertSame('config', (new NullStyler())->param('config'));
    }

    public function testLabelReturnsInputUnchanged(): void
    {
        self::assertSame('origin', (new NullStyler())->label('origin'));
    }

    public function testLocationReturnsInputUnchanged(): void
    {
        self::assertSame('src/Demo.php:12', (new NullStyler())->location('src/Demo.php:12'));
    }

    public function testAnnotationReturnsInputUnchanged(): void
    {
        self::assertSame('*YOURS*', (new NullStyler())->annotation('*YOURS*'));
    }

    public function testTerminalKindReturnsInputUnchanged(): void
    {
        self::assertSame('(stored)', (new NullStyler())->terminalKind('(stored)'));
    }

    public function testFileHeaderReturnsInputUnchanged(): void
    {
        self::assertSame('src/Demo.php', (new NullStyler())->fileHeader('src/Demo.php'));
    }

    public function testDividerReturnsInputUnchanged(): void
    {
        self::assertSame(str_repeat('-', 64), (new NullStyler())->divider(str_repeat('-', 64)));
    }

    public function testSummaryReturnsInputUnchanged(): void
    {
        self::assertSame('1 finding (limit: 3 hops).', (new NullStyler())->summary('1 finding (limit: 3 hops).'));
    }

    public function testSuccessReturnsInputUnchanged(): void
    {
        self::assertSame('No tramp data found (limit: 3 hops).', (new NullStyler())->success('No tramp data found (limit: 3 hops).'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Report/NullStylerTest.php`
Expected: FAIL with `Class PhpTramp\Report\NullStyler not found` (and `Styler` interface missing).

- [ ] **Step 3: Write the `Styler` interface**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The color/style seam for PrettyReporter. One method per semantic role in
 * the pretty layout (Q8 palette); the implementation decides whether to emit
 * ANSI escapes (AnsiStyler) or return the input unchanged (NullStyler).
 *
 * PrettyReporter never branches on "is color on?" — it calls these methods
 * and lets the injected Styler decide. This keeps rendering free of the
 * boolean-flag-selects-behaviour smell and lets --color=always|auto|never
 * and NO_COLOR be resolved entirely at the ColorPolicy seam.
 */
interface Styler
{
    public function severity(string $word, Severity $severity): string;
    public function param(string $name): string;
    public function label(string $label): string;
    public function location(string $location): string;
    public function annotation(string $annotation): string;
    public function terminalKind(string $kind): string;
    public function fileHeader(string $path): string;
    public function divider(string $dashes): string;
    public function summary(string $text): string;
    public function success(string $text): string;
}
```

- [ ] **Step 4: Write `NullStyler`**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The no-op Styler: every method returns its input unchanged. Injected when
 * --color=never, when --color=auto on a non-TTY, when NO_COLOR is set in auto
 * mode, and by every PrettyReporter test (so layout fixtures carry no ANSI).
 */
final class NullStyler implements Styler
{
    public function severity(string $word, Severity $severity): string
    {
        return $word;
    }

    public function param(string $name): string
    {
        return $name;
    }

    public function label(string $label): string
    {
        return $label;
    }

    public function location(string $location): string
    {
        return $location;
    }

    public function annotation(string $annotation): string
    {
        return $annotation;
    }

    public function terminalKind(string $kind): string
    {
        return $kind;
    }

    public function fileHeader(string $path): string
    {
        return $path;
    }

    public function divider(string $dashes): string
    {
        return $dashes;
    }

    public function summary(string $text): string
    {
        return $text;
    }

    public function success(string $text): string
    {
        return $text;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Report/NullStylerTest.php`
Expected: PASS (10 tests).

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green (cs + stan + md + test).

- [ ] **Step 7: Commit**

```bash
git add src/Report/Styler.php src/Report/NullStyler.php tests/Report/NullStylerTest.php
git commit -m "feat(#15): add Styler interface and NullStyler (color-off implementation)"
```

---

## Task 2: `AnsiStyler`

**Files:**
- Create: `src/Report/AnsiStyler.php`
- Test: `tests/Report/AnsiStylerTest.php`

**Interfaces:**
- Consumes: `Styler` interface (Task 1), `Severity` enum.
- Produces: `AnsiStyler` implementing `Styler` — wraps each input with the 8-color escapes from the palette table. The only class in `src/` that emits `\e`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\AnsiStyler;
use PhpTramp\Report\Severity;
use PHPUnit\Framework\TestCase;

/**
 * AnsiStyler is the 8-color implementation. Every method wraps its input with
 * the exact ANSI sequence from the Q8 palette and appends a reset. These
 * exact-string assertions are the only place the escape sequences are pinned;
 * every other test injects NullStyler and is ANSI-free.
 */
final class AnsiStylerTest extends TestCase
{
    private AnsiStyler $styler;

    protected function setUp(): void
    {
        $this->styler = new AnsiStyler();
    }

    public function testSeverityErrorIsBoldRed(): void
    {
        self::assertSame("\e[1;31mFINDING\e[0m", $this->styler->severity('FINDING', Severity::Error));
    }

    public function testSeverityWarningIsBoldYellow(): void
    {
        self::assertSame("\e[1;33mWARNING\e[0m", $this->styler->severity('WARNING', Severity::Warning));
    }

    public function testParamIsBold(): void
    {
        self::assertSame("\e[1mconfig\e[0m", $this->styler->param('config'));
    }

    public function testLabelIsDim(): void
    {
        self::assertSame("\e[2morigin\e[0m", $this->styler->label('origin'));
    }

    public function testLocationIsDim(): void
    {
        self::assertSame("\e[2msrc/Demo.php:12\e[0m", $this->styler->location('src/Demo.php:12'));
    }

    public function testAnnotationIsBoldMagenta(): void
    {
        self::assertSame("\e[1;35m*YOURS*\e[0m", $this->styler->annotation('*YOURS*'));
    }

    public function testTerminalKindIsDimGreen(): void
    {
        self::assertSame("\e[2;32m(stored)\e[0m", $this->styler->terminalKind('(stored)'));
    }

    public function testFileHeaderIsBoldBlue(): void
    {
        self::assertSame("\e[1;34msrc/Demo.php\e[0m", $this->styler->fileHeader('src/Demo.php'));
    }

    public function testDividerIsDim(): void
    {
        $dashes = str_repeat('-', 64);
        self::assertSame("\e[2m{$dashes}\e[0m", $this->styler->divider($dashes));
    }

    public function testSummaryIsBold(): void
    {
        self::assertSame("\e[1m1 finding (limit: 3 hops).\e[0m", $this->styler->summary('1 finding (limit: 3 hops).'));
    }

    public function testSuccessIsGreen(): void
    {
        self::assertSame("\e[32mNo tramp data found (limit: 3 hops).\e[0m", $this->styler->success('No tramp data found (limit: 3 hops).'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Report/AnsiStylerTest.php`
Expected: FAIL with `Class PhpTramp\Report\AnsiStyler not found`.

- [ ] **Step 3: Write `AnsiStyler`**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The 8-color Styler: wraps each input with the ANSI sequence for its semantic
 * role (Q8 palette) and appends a reset. The only class in src/ that emits \e.
 * Codes are the guaranteed-safe 8-color baseline; no truecolor, no 256-color,
 * no Unicode, no width handling.
 */
final class AnsiStyler implements Styler
{
    private const RESET = "\e[0m";
    private const BOLD_RED = "\e[1;31m";
    private const BOLD_YELLOW = "\e[1;33m";
    private const BOLD = "\e[1m";
    private const DIM = "\e[2m";
    private const BOLD_MAGENTA = "\e[1;35m";
    private const DIM_GREEN = "\e[2;32m";
    private const BOLD_BLUE = "\e[1;34m";
    private const GREEN = "\e[32m";

    public function severity(string $word, Severity $severity): string
    {
        $prefix = $severity === Severity::Error ? self::BOLD_RED : self::BOLD_YELLOW;

        return $prefix . $word . self::RESET;
    }

    public function param(string $name): string
    {
        return self::BOLD . $name . self::RESET;
    }

    public function label(string $label): string
    {
        return self::DIM . $label . self::RESET;
    }

    public function location(string $location): string
    {
        return self::DIM . $location . self::RESET;
    }

    public function annotation(string $annotation): string
    {
        return self::BOLD_MAGENTA . $annotation . self::RESET;
    }

    public function terminalKind(string $kind): string
    {
        return self::DIM_GREEN . $kind . self::RESET;
    }

    public function fileHeader(string $path): string
    {
        return self::BOLD_BLUE . $path . self::RESET;
    }

    public function divider(string $dashes): string
    {
        return self::DIM . $dashes . self::RESET;
    }

    public function summary(string $text): string
    {
        return self::BOLD . $text . self::RESET;
    }

    public function success(string $text): string
    {
        return self::GREEN . $text . self::RESET;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Report/AnsiStylerTest.php`
Expected: PASS (11 tests).

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Report/AnsiStyler.php tests/Report/AnsiStylerTest.php
git commit -m "feat(#15): add AnsiStyler (8-color implementation of the palette)"
```

---

## Task 3: `ColorPolicy` value object

**Files:**
- Create: `src/Report/ColorPolicy.php`
- Test: `tests/Report/ColorPolicyTest.php`

**Interfaces:**
- Consumes: `Styler` interface, `NullStyler` (Task 1), `AnsiStyler` (Task 2).
- Produces: `ColorPolicy::from(string $mode, bool $tty, bool $noColorSet): Styler` — the pure 3-boolean → `Styler` resolver. No env/TTY access; caller passes booleans.

- [ ] **Step 1: Write the failing test** — exhaustive truth table (the Q11 spec):

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Report\AnsiStyler;
use PhpTramp\Report\ColorPolicy;
use PhpTramp\Report\NullStyler;
use PHPUnit\Framework\TestCase;

/**
 * ColorPolicy is the pure 3-input → Styler resolver. No env/TTY access lives
 * here; the caller (Application) reads stream_isatty/getenv and passes the
 * booleans. This is the exhaustive Q11 truth table: always/never are absolute
 * over NO_COLOR; auto honors NO_COLOR and the TTY.
 */
final class ColorPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool, 2: bool, 3: string}>
     *     Each case: [mode, tty, noColorSet, expectedStylerClass].
     */
    public static function cases(): iterable
    {
        // always is absolute: ANSI regardless of tty / NO_COLOR.
        yield 'always, tty, no NO_COLOR' => ['always', true, false, AnsiStyler::class];
        yield 'always, tty, NO_COLOR set' => ['always', true, true, AnsiStyler::class];
        yield 'always, non-tty, no NO_COLOR' => ['always', false, false, AnsiStyler::class];
        yield 'always, non-tty, NO_COLOR set' => ['always', false, true, AnsiStyler::class];

        // never is absolute: NullStyler regardless of tty / NO_COLOR.
        yield 'never, tty, no NO_COLOR' => ['never', true, false, NullStyler::class];
        yield 'never, tty, NO_COLOR set' => ['never', true, true, NullStyler::class];
        yield 'never, non-tty, no NO_COLOR' => ['never', false, false, NullStyler::class];
        yield 'never, non-tty, NO_COLOR set' => ['never', false, true, NullStyler::class];

        // auto honors NO_COLOR and the TTY.
        yield 'auto, tty, no NO_COLOR' => ['auto', true, false, AnsiStyler::class];
        yield 'auto, tty, NO_COLOR set' => ['auto', true, true, NullStyler::class];
        yield 'auto, non-tty, no NO_COLOR' => ['auto', false, false, NullStyler::class];
        yield 'auto, non-tty, NO_COLOR set' => ['auto', false, true, NullStyler::class];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testResolvesStylerPerTruthTable(string $mode, bool $tty, bool $noColorSet, string $expected): void
    {
        self::assertInstanceOf($expected, ColorPolicy::from($mode, $tty, $noColorSet));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Report/ColorPolicyTest.php`
Expected: FAIL with `Class PhpTramp\Report\ColorPolicy not found`.

- [ ] **Step 3: Write `ColorPolicy`**

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The pure color-mode → Styler resolver. Given the --color mode (always|auto|
 * never), whether STDOUT is a TTY, and whether NO_COLOR is set, returns the
 * Styler to inject into PrettyReporter. No env/TTY access lives here — the
 * caller reads stream_isatty/getenv and passes the booleans, so this class is
 * trivially testable with the exhaustive Q11 truth table.
 *
 * Precedence: always/never are absolute over NO_COLOR; auto honors NO_COLOR
 * (any non-empty value) and the TTY.
 */
final class ColorPolicy
{
    private function __construct(private readonly Styler $styler)
    {
    }

    public static function from(string $mode, bool $tty, bool $noColorSet): Styler
    {
        return match ($mode) {
            'always' => new AnsiStyler(),
            'never' => new NullStyler(),
            'auto' => $tty && ! $noColorSet ? new AnsiStyler() : new NullStyler(),
            // Unreachable: ArgvParser::validateColorMode rejects any mode
            // outside ['always','auto','never'] at parse time.
            default => new NullStyler(),
        };
    }

    public function styler(): Styler
    {
        return $this->styler;
    }
}
```

Note: the private constructor + `styler()` accessor is kept for symmetry with the codebase's value-object style, but `from()` is the only entry point used. If PHPMD flags the unused `$styler` accessor, drop the constructor and return the `Styler` directly from `from()` — `from()` already returns the `Styler`; callers do `ColorPolicy::from(...)->render(...)` via the `Reporter`. Prefer the simplest shape: `from()` returns `Styler`, no instance state. Use this minimal form:

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

/**
 * The pure color-mode → Styler resolver. Given the --color mode (always|auto|
 * never), whether STDOUT is a TTY, and whether NO_COLOR is set, returns the
 * Styler to inject into PrettyReporter. No env/TTY access lives here — the
 * caller reads stream_isatty/getenv and passes the booleans, so this class is
 * trivially testable with the exhaustive Q11 truth table.
 *
 * Precedence: always/never are absolute over NO_COLOR; auto honors NO_COLOR
 * (any non-empty value) and the TTY.
 */
final class ColorPolicy
{
    public static function from(string $mode, bool $tty, bool $noColorSet): Styler
    {
        return match ($mode) {
            'always' => new AnsiStyler(),
            'never' => new NullStyler(),
            'auto' => $tty && ! $noColorSet ? new AnsiStyler() : new NullStyler(),
            // Unreachable: ArgvParser::validateColorMode rejects any mode
            // outside ['always','auto','never'] at parse time.
            default => new NullStyler(),
        };
    }
}
```

Use the minimal form. Drop the first (constructor) form.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Report/ColorPolicyTest.php`
Expected: PASS (12 cases).

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Report/ColorPolicy.php tests/Report/ColorPolicyTest.php
git commit -m "feat(#15): add ColorPolicy (pure --color/TTY/NO_COLOR -> Styler resolver)"
```

---

## Task 4: `Options` + `ArgvParser` gain `--color` / `colorMode`

**Files:**
- Modify: `src/Console/Options.php:22-43` (add `colorMode` param, default `'auto'`)
- Modify: `src/Console/ArgvParser.php:21-48,57,104-127,162-178` (add `--color` to `VALUE_FLAGS`, `VALID_COLOR_MODES` const, `colorMode` field, `reset` seed, `applyValueFlag` arm, `validateColorMode`; pass `colorMode` in the `new Options(...)` call at line 80)
- Test: `tests/Console/ArgvParserTest.php` (add `colorMode` default assertion + `--color` parsing cases + invalid-mode rejection)
- Test: `tests/Console/OptionsTest.php` (add `colorMode` default assertion)

**Interfaces:**
- Consumes: `Options` value object, `ArgvParser` hand-rolled parser, `InvalidArgsException`.
- Produces: `Options::$colorMode` (string, default `'auto'`); `ArgvParser` accepts `--color=always|auto|never` (both `=` and separate-arg syntaxes); invalid modes throw `InvalidArgsException`. The `format` default and `VALID_FORMATS` are NOT touched in this task (still `'text'`).

- [ ] **Step 1: Write the failing tests** — add to `tests/Console/ArgvParserTest.php`:

```php
public function testColorModeDefaultsToAuto(): void
{
    self::assertSame('auto', $this->parse()->colorMode);
}

public function testColorAlwaysParsed(): void
{
    self::assertSame('always', $this->parse('--color', 'always')->colorMode);
}

public function testColorEqualsSyntaxWorks(): void
{
    self::assertSame('never', $this->parse('--color=never')->colorMode);
}

public function testUnknownColorModeThrows(): void
{
    $this->expectException(InvalidArgsException::class);
    $this->parse('--color', 'purple');
}
```

And update `testDefaultsAreTheDocumentedOnes()` (around line 20) to add:

```php
self::assertSame('auto', $o->colorMode);
```

(Do NOT change the `self::assertSame('text', $o->format)` line in this task — the format default flip lands in Task 9.)

If `tests/Console/OptionsTest.php` has a defaults test, add the same `colorMode` assertion there.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php`
Expected: FAIL with `Unknown property "colorMode" on Options` / `unknown option: --color`.

- [ ] **Step 3: Add `colorMode` to `Options`**

In `src/Console/Options.php`, add a new readonly param after `format` (keeping the wide-constructor `@SuppressWarnings(PHPMD)` on the class). The param goes immediately after `$format`:

```php
public readonly string $format = 'text',
public readonly string $colorMode = 'auto',
public readonly bool $explain = false,
```

- [ ] **Step 4: Add `--color` parsing to `ArgvParser`**

In `src/Console/ArgvParser.php`:

(a) Add `'--color'` to `VALUE_FLAGS` (line 21-33).

(b) Add a `VALID_COLOR_MODES` constant near `VALID_FORMATS` (line 48):

```php
private const VALID_COLOR_MODES = ['always', 'auto', 'never'];
```

(c) Add a `colorMode` field near `$format` (line 57):

```php
private string $colorMode = 'auto';
```

(d) In `reset()` (line 104-127), seed it:

```php
$this->colorMode = $defaults->colorMode;
```

(e) In `applyValueFlag()` (line 162-178), add an arm:

```php
'--color' => $this->colorMode = $this->validateColorMode($value),
```

(f) In the `new Options(...)` call at line 80-101, add `colorMode: $this->colorMode,` (insert after `format: $this->format,`).

(g) Add a `validateColorMode` method near `validateFormat` (line 249-258):

```php
private function validateColorMode(string $value): string
{
    if (! in_array($value, self::VALID_COLOR_MODES, true)) {
        throw new InvalidArgsException(
            "unknown color mode: {$value} (expected " . implode('|', self::VALID_COLOR_MODES) . ')'
        );
    }

    return $value;
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php`
Expected: PASS.

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green. (PHPStan: `ArgvParser` field counts and method lengths must stay clean — the additions are small.)

- [ ] **Step 7: Commit**

```bash
git add src/Console/Options.php src/Console/ArgvParser.php tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php
git commit -m "feat(#15): add --color=always|auto|never flag and Options::colorMode"
```

---

## Task 5: `ConfigLoader` gains `colorMode` key

**Files:**
- Modify: `src/Config/ConfigLoader.php:81-120` (add `colorMode` to the `match` and the `new Options(...)` call)
- Test: `tests/Config/ConfigLoaderTest.php` (add cases: `colorMode` parsed from JSON; wrong-typed `colorMode` throws)

**Interfaces:**
- Consumes: `Options` (now with `colorMode`), `ConfigException`.
- Produces: `phptramp.json` / `phptramp.dist.json` may set `"colorMode": "always"|"auto"|"never"`. Unknown values are accepted by `ConfigLoader` (it only checks the type, not the value — validation is `ArgvParser`'s job at parse time; but config seeds defaults, so a bad value would surface as the parser default... actually no: config sets the seed, and `ArgvParser::reset` copies it verbatim, so a bad config value would NOT be caught). Therefore `ConfigLoader` MUST validate the mode against `['always','auto','never']` and throw `ConfigException` on a bad value, mirroring its strict-key posture. Add a `requireColorMode(string $key, mixed $value): string` helper that checks both type and membership.

- [ ] **Step 1: Write the failing tests** — add to `tests/Config/ConfigLoaderTest.php`:

```php
public function testColorModeParsedFromConfig(): void
{
    $options = $this->load('phptramp.json', '{"colorMode": "always"}');

    self::assertSame('always', $options->colorMode);
}

public function testColorModeMustBeAValidMode(): void
{
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('config key "colorMode" must be one of: always, auto, never');

    $this->load('phptramp.json', '{"colorMode": "purple"}');
}

public function testColorModeMustBeAString(): void
{
    $this->expectException(ConfigException::class);
    $this->expectExceptionMessage('config key "colorMode" must be one of: always, auto, never');

    $this->load('phptramp.json', '{"colorMode": 3}');
}
```

(Adjust the `load`/`writeConfig` helper names to match the test class's existing helpers — see `ConfigLoaderTest.php` around lines 127-194 for the pattern.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Config/ConfigLoaderTest.php`
Expected: FAIL with `unknown config key: colorMode` (the `match`'s `default` arm).

- [ ] **Step 3: Add `colorMode` handling to `ConfigLoader`**

In `src/Config/ConfigLoader.php`:

(a) In `toOptions()` (line 81-120), add a `$colorMode = $defaults->colorMode;` local near line 91, add a `match` arm at line 96-107:

```php
'colorMode' => $colorMode = $this->requireColorMode('colorMode', $value),
```

and add `colorMode: $colorMode,` to the `new Options(...)` call at line 109-119.

(b) Add the `requireColorMode` helper near `requireString` (line 168-175):

```php
private function requireColorMode(string $key, mixed $value): string
{
    $valid = ['always', 'auto', 'never'];
    if (! is_string($value) || ! in_array($value, $valid, true)) {
        throw new ConfigException("config key \"{$key}\" must be one of: always, auto, never");
    }

    return $value;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Config/ConfigLoaderTest.php`
Expected: PASS.

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add src/Config/ConfigLoader.php tests/Config/ConfigLoaderTest.php
git commit -m "feat(#15): accept colorMode key in phptramp.json config"
```

---

## Task 6: `PrettyReporter`

**Files:**
- Create: `src/Report/PrettyReporter.php`
- Test: `tests/Report/PrettyReporterTest.php`

**Interfaces:**
- Consumes: `Reporter` interface; `Thresholds`, `Paths`, `Pluralizer`, `Severity`, `Styler`, `NullStyler` (default in tests); `Finding`, `Hop`, `TerminalKind`.
- Produces: `PrettyReporter implements Reporter` — `render(list<Finding>): string`. Constructor: `__construct(Thresholds $thresholds, Paths $paths, bool $explain, Styler $styler)`.

**Layout (Q12 sketch, locked):**

Single finding, one file:
```
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)    src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)     src/Demo.php:18   *YOURS*
  hop 3     Demo\ServiceB::run($config)          src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)   src/Demo.php:32   (stored)

----------------------------------------------------------------

1 finding (limit: 3 hops).
```

- File header: bare relative path (styled via `Styler::fileHeader`), own line.
- Blank line between file header and first finding panel.
- Blank line between finding panels within the same file.
- Header: `FINDING`/`WARNING` (`Styler::severity`) + `  $` + `param` (`Styler::param`) + `: ` + hop/class summary (same wording as `TextReporter::header`).
- Rows: `label` (`Styler::label`) + 2-space gap + FQMN (plain) + `($` + `param` (`Styler::param`) + `)` + padding + 2-space gap + `location` (`Styler::location`) + optional 2-space gap + `annotation` (`Styler::annotation` for `*YOURS*`, `Styler::terminalKind` for `(stored)` etc.) — column alignment identical to `TextReporter` (`MIN_LABEL_WIDTH=8`, method-width = longest method in the finding, `COLUMN_GAP='  '`).
- `note` rows: `Styler::label('note')` + the note text.
- `explain:` block: as `TextReporter` — `INDENT . 'explain:'` then `INDENT . INDENT . $traceLine` per trace line; the `explain:` label and trace lines are styled via `Styler::label` (dim).
- Divider: 64 ASCII `-` (styled via `Styler::divider`), between file groups and before the summary.
- Summary: same shape as `TextReporter::summary` (count, optional error/warning split, limit clause), styled via `Styler::summary`.
- Empty findings: `No tramp data found (limit: ...).` styled via `Styler::success`, no file header, no divider, trailing `\n`.
- Grouping: collect reportable findings by their first hop's file; sort findings within each file by the first hop's `line` ascending; emit file groups in alphabetical path order so a scrambled input still yields a stable, readable report. (The earlier "first appearance" prose was self-contradictory with the locked fixture below, which expects alphabetical Demo before Other regardless of input order — alphabetical wins.)

**Multi-file fixture** (locks the inter-file divider):

```
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)    src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)     src/Demo.php:18
  hop 3     Demo\ServiceB::run($config)          src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)   src/Demo.php:32   (stored)

----------------------------------------------------------------

src/Other.php

FINDING  $flag: 1 pass-through hop across 2 classes
  origin    Demo\C::go($flag)        src/Other.php:5
  terminal  Demo\D::sink($flag)      src/Other.php:9   (used)

----------------------------------------------------------------

2 findings (limit: 3 hops).
```

- [ ] **Step 1: Write the failing tests** — `tests/Report/PrettyReporterTest.php`. Inject `NullStyler` so fixtures are pure text. Use the same `Finding`/`Hop` construction idiom as `TextReporterTest` (see `tests/Report/TextReporterTest.php:17-50`).

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Tests\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;
use PhpTramp\Report\NullStyler;
use PhpTramp\Report\Paths;
use PhpTramp\Report\PrettyReporter;
use PhpTramp\Report\Thresholds;
use PHPUnit\Framework\TestCase;

/**
 * PrettyReporter layout fixtures, injected with NullStyler so they assert
 * pure text (grouping, dividers, file headers, labels, summary) with zero
 * ANSI noise. The color codes themselves are pinned in AnsiStylerTest.
 */
final class PrettyReporterTest extends TestCase
{
    public function testEmptyFindingsRendersSuccessLine(): void
    {
        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame("No tramp data found (limit: 3 hops).\n", $reporter->render([]));
    }

    public function testRendersSingleFindingWithFileHeaderAndDividerAndSummary(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20),
            new Hop('Demo\ServiceB::run', 'Demo\ServiceB', 'src/Demo.php', 24, 26),
            new Hop('Demo\Mailer::__construct', 'Demo\Mailer', 'src/Demo.php', 32, null),
        ];
        $finding = new Finding('config', 'Demo\Controller::handle', 'Demo\Mailer::__construct', TerminalKind::Stored, 3, $chain, 4, [], []);

        $expected = <<<'TXT'
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)    src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)     src/Demo.php:18
  hop 3     Demo\ServiceB::run($config)          src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)   src/Demo.php:32  (stored)

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testRendersWarningHeaderBelowLimitAtWarnLimit(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, null),
        ];
        $finding = new Finding('cfg', 'Demo\A::go', 'Demo\B::step', TerminalKind::Used, 1, $chain, 2, [], []);

        $expected = <<<'TXT'
src/A.php

WARNING  $cfg: 1 pass-through hop across 2 classes
  origin    Demo\A::go($cfg)    src/A.php:5
  terminal  Demo\B::step($cfg)   src/B.php:9  (used)

----------------------------------------------------------------

1 finding (limit: 3 hops, warn-limit: 1 hop).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, 1), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testMarksChangedNonTerminalHopWithYoursAnnotation(): void
    {
        $chain = [
            new Hop('Demo\Controller::handle', 'Demo\Controller', 'src/Demo.php', 12, 14),
            new Hop('Demo\ServiceA::process', 'Demo\ServiceA', 'src/Demo.php', 18, 20, true),
            new Hop('Demo\ServiceB::run', 'Demo\ServiceB', 'src/Demo.php', 24, 26),
            new Hop('Demo\Mailer::__construct', 'Demo\Mailer', 'src/Demo.php', 32, null),
        ];
        $finding = new Finding('config', 'Demo\Controller::handle', 'Demo\Mailer::__construct', TerminalKind::Stored, 3, $chain, 4, [], []);

        $expected = <<<'TXT'
src/Demo.php

FINDING  $config: 3 pass-through hops across 4 classes
  origin    Demo\Controller::handle($config)    src/Demo.php:12
  hop 2     Demo\ServiceA::process($config)     src/Demo.php:18  *YOURS*
  hop 3     Demo\ServiceB::run($config)          src/Demo.php:24
  terminal  Demo\Mailer::__construct($config)   src/Demo.php:32  (stored)

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testGroupsByFileAndSortsWithinFileByFirstHopLine(): void
    {
        // Two findings in src/Demo.php (lines 12 and 5), one in src/Other.php.
        // Input order is scrambled: Other first, then Demo-line-12, then Demo-line-5.
        // Expected output: Demo.php group (line 5 finding first, then line 12),
        // then Other.php group. Inter-file divider and pre-summary divider.
        $demoEarly = new Finding(
            'early',
            'Demo\A::go',
            'Demo\B::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\A::go', 'Demo\A', 'src/Demo.php', 5, 7),
                new Hop('Demo\B::sink', 'Demo\B', 'src/Demo.php', 9, null),
            ],
            2,
            [],
            [],
        );
        $demoLate = new Finding(
            'late',
            'Demo\C::run',
            'Demo\D::end',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\C::run', 'Demo\C', 'src/Demo.php', 12, 14),
                new Hop('Demo\D::end', 'Demo\D', 'src/Demo.php', 16, null),
            ],
            2,
            [],
            [],
        );
        $other = new Finding(
            'flag',
            'Demo\X::go',
            'Demo\Y::sink',
            TerminalKind::Used,
            1,
            [
                new Hop('Demo\X::go', 'Demo\X', 'src/Other.php', 5, 7),
                new Hop('Demo\Y::sink', 'Demo\Y', 'src/Other.php', 9, null),
            ],
            2,
            [],
            [],
        );

        $expected = <<<'TXT'
src/Demo.php

FINDING  $early: 1 pass-through hop across 2 classes
  origin    Demo\A::go($early)    src/Demo.php:5
  terminal  Demo\B::sink($early)   src/Demo.php:9  (used)

FINDING  $late: 1 pass-through hop across 2 classes
  origin    Demo\C::run($late)    src/Demo.php:12
  terminal  Demo\D::end($late)    src/Demo.php:16  (used)

----------------------------------------------------------------

src/Other.php

FINDING  $flag: 1 pass-through hop across 2 classes
  origin    Demo\X::go($flag)    src/Other.php:5
  terminal  Demo\Y::sink($flag)   src/Other.php:9  (used)

----------------------------------------------------------------

3 findings (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$other, $demoLate, $demoEarly]));
    }

    public function testRendersTruncatedChainWithNoteLineAndNoTerminal(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/B.php', 9, 11),
        ];
        $finding = new Finding(
            'cfg',
            'Demo\A::go',
            null,
            TerminalKind::Truncated,
            2,
            $chain,
            2,
            ['truncated: 2 implementations'],
            [],
        );

        $expected = <<<'TXT'
src/A.php

FINDING  $cfg: 2 pass-through hops across 2 classes
  origin  Demo\A::go($cfg)    src/A.php:5
  hop 2   Demo\B::step($cfg)  src/B.php:9
  note    truncated: 2 implementations

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testExplainBlockRenderedWhenExplainTrue(): void
    {
        $chain = [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::sink', 'Demo\B', 'src/A.php', 9, null),
        ];
        $finding = new Finding(
            'cfg',
            'Demo\A::go',
            'Demo\B::sink',
            TerminalKind::Used,
            1,
            $chain,
            2,
            [],
            ['resolved Demo\A::go -> Demo\B::sink via interface Demo\I'],
        );

        $expected = <<<'TXT'
src/A.php

FINDING  $cfg: 1 pass-through hop across 2 classes
  origin    Demo\A::go($cfg)    src/A.php:5
  terminal  Demo\B::sink($cfg)   src/A.php:9  (used)
  explain:
    resolved Demo\A::go -> Demo\B::sink via interface Demo\I

----------------------------------------------------------------

1 finding (limit: 3 hops).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, null), new Paths('/nonexistent-root'), true, new NullStyler());

        self::assertSame($expected, $reporter->render([$finding]));
    }

    public function testWarnVsErrorSummarySplit(): void
    {
        $error = new Finding('a', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 3, [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\B::step', 'Demo\B', 'src/A.php', 9, 11),
            new Hop('Demo\C::sink', 'Demo\C', 'src/A.php', 13, null),
        ], 3, [], []);
        $warning = new Finding('b', 'Demo\A::go', 'Demo\C::sink', TerminalKind::Used, 1, [
            new Hop('Demo\A::go', 'Demo\A', 'src/A.php', 5, 7),
            new Hop('Demo\C::sink', 'Demo\C', 'src/A.php', 13, null),
        ], 2, [], []);

        $expected = <<<'TXT'
src/A.php

FINDING  $a: 3 pass-through hops across 3 classes
  origin    Demo\A::go($a)    src/A.php:5
  hop 2     Demo\B::step($a)  src/A.php:9
  terminal  Demo\C::sink($a)  src/A.php:13  (used)

FINDING  $b: 1 pass-through hop across 2 classes
  origin    Demo\A::go($b)    src/A.php:5
  terminal  Demo\C::sink($b)  src/A.php:13  (used)

----------------------------------------------------------------

2 findings (1 error, 1 warning; limit: 3 hops, warn-limit: 1 hop).

TXT;

        $reporter = new PrettyReporter(new Thresholds(3, 1), new Paths('/nonexistent-root'), false, new NullStyler());

        self::assertSame($expected, $reporter->render([$error, $warning]));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Report/PrettyReporterTest.php`
Expected: FAIL with `Class PhpTramp\Report\PrettyReporter not found`.

- [ ] **Step 3: Write `PrettyReporter`**

The structure mirrors `TextReporter` (see `src/Report/TextReporter.php` for the column-alignment helpers: `INDENT='  '`, `COLUMN_GAP='  '`, `MIN_LABEL_WIDTH=8`, `labelWidth`, `methodWidth`, `hopLine`, `labelledLine`, `header`, `summary`, `limitClause`, `reportableFindings`, `hopRows`, `label`, `location`, `annotation`, `explainLines`). The differences: (1) `Styler` injection — every text fragment is routed through the matching `Styler` method; (2) file-grouping + within-file line-sorting; (3) file-header line + blank lines + divider + the success-line empty case.

```php
<?php

declare(strict_types=1);

namespace PhpTramp\Report;

use PhpTramp\Chain\Finding;
use PhpTramp\Chain\Hop;
use PhpTramp\Chain\TerminalKind;

/**
 * Renders findings as grouped, styled plain text — the "pretty" format. Groups
 * by the first hop's file, sorts findings within a file by the first hop's
 * line, and separates file groups (and the summary) with a 64-char dim divider.
 * Color is the injected Styler's concern; this class never branches on whether
 * color is on.
 */
final class PrettyReporter implements Reporter
{
    private const INDENT = '  ';
    private const COLUMN_GAP = '  ';
    private const MIN_LABEL_WIDTH = 8;
    private const DIVIDER_WIDTH = 64;

    private readonly Pluralizer $pluralizer;

    public function __construct(
        private readonly Thresholds $thresholds,
        private readonly Paths $paths,
        private readonly bool $explain,
        private readonly Styler $styler,
    ) {
        $this->pluralizer = new Pluralizer();
    }

    /**
     * @param list<Finding> $findings ALL findings, unfiltered
     */
    public function render(array $findings): string
    {
        $reportable = $this->reportableFindings($findings);
        if ($reportable === []) {
            return $this->styler->success('No tramp data found (' . $this->limitClause() . ').') . "\n";
        }

        $body = $this->renderFileGroups($this->groupByFile($reportable));

        return $body . "\n\n" . $this->divider() . "\n\n" . $this->summary($reportable) . "\n";
    }

    /**
     * @param list<Finding> $findings
     * @return list<array{0: Finding, 1: Severity}>
     */
    private function reportableFindings(array $findings): array
    {
        $reportable = [];
        foreach ($findings as $finding) {
            $severity = $this->thresholds->severityOf($finding);
            if ($severity !== null) {
                $reportable[] = [$finding, $severity];
            }
        }

        return $reportable;
    }

    /**
     * @param array<string, list<array{0: Finding, 1: Severity}>> $groups
     */
    private function renderFileGroups(array $groups): string
    {
        $blocks = [];
        foreach ($groups as $file => $findingsInFile) {
            $blocks[] = $this->renderFile($file, $findingsInFile);
        }

        return implode("\n\n" . $this->divider() . "\n\n", $blocks);
    }

    /**
     * Group reportable findings by their first hop's file. Group order is the
     * order files first appear in the reportable list; within each file,
     * findings are sorted by the first hop's line ascending (stable).
     *
     * @param list<array{0: Finding, 1: Severity}> $reportable
     * @return array<string, list<array{0: Finding, 1: Severity}>>
     */
    private function groupByFile(array $reportable): array
    {
        $groups = [];
        foreach ($reportable as $entry) {
            $file = $this->firstHopFile($entry[0]);
            $groups[$file][] = $entry;
        }

        foreach ($groups as $file => $findingsInFile) {
            usort($findingsInFile, fn (array $a, array $b): int => $this->firstHopLine($a[0]) <=> $this->firstHopLine($b[0]));
            $groups[$file] = $findingsInFile;
        }

        return $groups;
    }

    private function firstHopFile(Finding $finding): string
    {
        return $this->paths->relativize($finding->chain[0]->file);
    }

    private function firstHopLine(Finding $finding): int
    {
        return $finding->chain[0]->line;
    }

    /**
     * @param list<array{0: Finding, 1: Severity}> $findingsInFile
     */
    private function renderFile(string $file, array $findingsInFile): string
    {
        $panels = array_map(
            fn (array $entry): string => $this->renderFinding($entry[0], $entry[1]),
            $findingsInFile,
        );

        return $this->styler->fileHeader($file) . "\n\n" . implode("\n\n", $panels);
    }

    private function renderFinding(Finding $finding, Severity $severity): string
    {
        $hopRows = $this->hopRows($finding);
        $labelWidth = $this->labelWidth($hopRows, $finding->notes);
        $methodWidth = $this->methodWidth($hopRows);

        $lines = [$this->header($finding, $severity)];
        foreach ($hopRows as $row) {
            $lines[] = $this->hopLine($row, $labelWidth, $methodWidth);
        }
        foreach ($finding->notes as $note) {
            $lines[] = $this->labelledLine('note', $labelWidth, $this->styler->label($note));
        }
        foreach ($this->explainLines($finding) as $explainLine) {
            $lines[] = $explainLine;
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function explainLines(Finding $finding): array
    {
        if (! $this->explain || $finding->trace === []) {
            return [];
        }

        $lines = [$this->labelledLine('explain', 0, '')];
        foreach ($finding->trace as $traceLine) {
            $lines[] = self::INDENT . self::INDENT . $this->styler->label($traceLine);
        }

        return $lines;
    }

    /**
     * @return list<array{label: string, method: string, location: string, annotation: string}>
     */
    private function hopRows(Finding $finding): array
    {
        $hasTerminalNode = count($finding->chain) > $finding->hops;

        $rows = [];
        foreach ($finding->chain as $index => $hop) {
            $isTerminal = $hasTerminalNode && $index === $finding->hops;
            $rows[] = [
                'label' => $this->label($index, $isTerminal),
                'method' => $hop->fqmn . '($' . $finding->param . ')',
                'location' => $this->location($hop),
                'annotation' => $this->annotation($hop, $finding->terminalKind, $isTerminal),
            ];
        }

        return $rows;
    }

    private function annotation(Hop $hop, TerminalKind $terminalKind, bool $isTerminal): string
    {
        if ($isTerminal) {
            return '(' . $terminalKind->value . ')';
        }
        if ($hop->changed) {
            return '*YOURS*';
        }

        return '';
    }

    private function label(int $index, bool $isTerminal): string
    {
        if ($isTerminal) {
            return 'terminal';
        }
        if ($index === 0) {
            return 'origin';
        }

        return 'hop ' . ($index + 1);
    }

    private function location(Hop $hop): string
    {
        return $this->paths->relativize($hop->file) . ':' . $hop->line;
    }

    /**
     * @param array{label: string, method: string, location: string, annotation: string} $row
     */
    private function hopLine(array $row, int $labelWidth, int $methodWidth): string
    {
        $method = $this->splitMethodAndParam($row['method']);
        $rest = str_pad($method['padded'], $methodWidth) . self::COLUMN_GAP . $this->styler->location($row['location']);
        if ($row['annotation'] !== '') {
            $styledAnnotation = str_starts_with($row['annotation'], '(')
                ? $this->styler->terminalKind($row['annotation'])
                : $this->styler->annotation($row['annotation']);
            $rest .= self::COLUMN_GAP . $styledAnnotation;
        }

        return $this->labelledLine($row['label'], $labelWidth, $rest);
    }

    /**
     * Splits "FQMN($param)" into the styled FQMN + styled "($param)", padded
     * to the row's method-width so locations align. The param fragment is
     * styled via Styler::param; the FQMN is plain.
     *
     * @return array{padded: string}
     */
    private function splitMethodAndParam(string $method): array
    {
        $paren = strpos($method, '($');
        if ($paren === false) {
            return ['padded' => $method];
        }

        $fqmn = substr($method, 0, $paren);
        $paramFragment = substr($method, $paren);
        // Style the param fragment by stripping the surrounding "$(...)" and
        // re-wrapping so the parens stay plain and only the name is bold.
        $inner = substr($paramFragment, 2, -1);
        $styled = '($' . $this->styler->param($inner) . ')';

        return ['padded' => $fqmn . $styled];
    }

    private function labelledLine(string $label, int $labelWidth, string $rest): string
    {
        $paddedLabel = str_pad($label, $labelWidth);

        return self::INDENT . $this->styler->label($paddedLabel) . self::COLUMN_GAP . $rest;
    }

    /**
     * @param list<array{label: string, method: string, location: string, annotation: string}> $rows
     * @param list<string> $notes
     */
    private function labelWidth(array $rows, array $notes): int
    {
        $widths = [self::MIN_LABEL_WIDTH];
        foreach ($rows as $row) {
            $widths[] = strlen($row['label']);
        }
        if ($notes !== []) {
            $widths[] = strlen('note');
        }

        return max($widths);
    }

    /**
     * @param list<array{label: string, method: string, location: string, annotation: string}> $rows
     */
    private function methodWidth(array $rows): int
    {
        $widths = [0];
        foreach ($rows as $row) {
            $widths[] = strlen($row['method']);
        }

        return max($widths);
    }

    private function divider(): string
    {
        return $this->styler->divider(str_repeat('-', self::DIVIDER_WIDTH));
    }

    private function header(Finding $finding, Severity $severity): string
    {
        $keyword = $severity === Severity::Warning ? 'WARNING' : 'FINDING';
        $styledKeyword = $this->styler->severity($keyword, $severity);
        $styledParam = $this->styler->param($finding->param);

        return $styledKeyword . '  $' . $styledParam . ': '
            . $finding->hops . ' pass-through ' . $this->pluralizer->of($finding->hops, 'hop')
            . ' across ' . $finding->classes . ' ' . $this->pluralizer->of($finding->classes, 'class', 'classes');
    }

    /**
     * @param list<array{0: Finding, 1: Severity}> $reportable
     */
    private function summary(array $reportable): string
    {
        $count = count($reportable);
        if ($this->thresholds->warnLimit === null) {
            return $this->styler->summary(
                $count . ' ' . $this->pluralizer->of($count, 'finding') . ' (' . $this->limitClause() . ').'
            );
        }

        $errorCount = count(array_filter($reportable, static fn (array $entry): bool => $entry[1] === Severity::Error));
        $warningCount = $count - $errorCount;

        return $this->styler->summary(
            $count . ' ' . $this->pluralizer->of($count, 'finding') . ' ('
            . $errorCount . ' ' . $this->pluralizer->of($errorCount, 'error') . ', '
            . $warningCount . ' ' . $this->pluralizer->of($warningCount, 'warning') . '; '
            . $this->limitClause() . ').'
        );
    }

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
}
```

**Notes on the implementation:**

- `splitMethodAndParam` handles the `($param)` styling: the FQMN is plain, only the inner `$param` is styled via `Styler::param`, and the parens stay plain. The padded width uses the *unstyled* length (`strlen($row['method'])`) — but the styled output is longer than the unstyled, so column alignment of the *location* depends on the visible width, not the byte length. Under `NullStyler`, styled == unstyled, so the fixtures align. Under `AnsiStyler`, the escapes are invisible (terminals strip them), so the visible columns still align — but `str_pad` pads based on byte length, which is shorter than the styled string. This is the one place where padding-vs-styled-length matters. The fix: pad the *unstyled* FQMN fragment to `methodWidth - strlen('($param)')` before concatenating the styled param fragment. Adjust `splitMethodAndParam` to:

```php
private function splitMethodAndParam(string $method, int $methodWidth): array
{
    $paren = strpos($method, '($');
    if ($paren === false) {
        return ['padded' => str_pad($method, $methodWidth)];
    }

    $fqmn = substr($method, 0, $paren);
    $inner = substr($method, $paren + 2, -1);
    $styledParam = '($' . $this->styler->param($inner) . ')';
    $paddedFqmn = str_pad($fqmn, $methodWidth - strlen($styledParam) + strlen($inner) + 3);

    return ['padded' => $paddedFqmn . $styledParam];
}
```

Wait — the padding math is getting fragile. Let me reconsider. The simplest correct approach: pad the *unstyled* full method string to `methodWidth` (byte length), then split-and-style the *visible* portion. But `str_pad` on the styled string pads based on byte length including escapes, which over-pads.

The clean fix: build the row as `str_pad($fqmn, $fqmnWidth) . $styledParam . COLUMN_GAP . $location`, where `$fqmnWidth = $methodWidth - strlen('($' . $param . ')')`. Compute `$fqmnWidth` from the unstyled method string. This keeps the *visible* FQMN column aligned across rows. Rewrite `hopLine` to assemble the row from parts rather than padding a single styled blob:

```php
private function hopLine(array $row, int $labelWidth, int $methodWidth): string
{
    [$fqmn, $paramName] = $this->splitMethod($row['method']);
    $paramFragment = '($' . $this->styler->param($paramName) . ')';
    $fqmnWidth = $methodWidth - strlen('($' . $paramName . ')');
    $rest = str_pad($fqmn, $fqmnWidth) . $paramFragment . self::COLUMN_GAP . $this->styler->location($row['location']);
    if ($row['annotation'] !== '') {
        $styledAnnotation = str_starts_with($row['annotation'], '(')
            ? $this->styler->terminalKind($row['annotation'])
            : $this->styler->annotation($row['annotation']);
        $rest .= self::COLUMN_GAP . $styledAnnotation;
    }

    return $this->labelledLine($row['label'], $labelWidth, $rest);
}

/** @return array{0: string, 1: string} */
private function splitMethod(string $method): array
{
    $paren = strpos($method, '($');
    if ($paren === false) {
        return [$method, ''];
    }

    return [substr($method, 0, $paren), substr($method, $paren + 2, -1)];
}
```

Use this corrected form. The `splitMethodAndParam` helper is dropped in favor of `splitMethod` + the assembly in `hopLine`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Report/PrettyReporterTest.php`
Expected: PASS (7 tests). If any fixture mismatches by whitespace, adjust the implementation (not the fixture) until the exact-string match holds — the fixtures are the spec.

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green. (PHPMD: `PrettyReporter` is a meaty class — watch method count and cyclomatic complexity. If `groupByFile`/`hopLine`/`renderFinding` approach limits, extract helpers. The `usort` closure in `groupByFile` is fine; the arrow function keeps complexity low.)

- [ ] **Step 6: Commit**

```bash
git add src/Report/PrettyReporter.php tests/Report/PrettyReporterTest.php
git commit -m "feat(#15): add PrettyReporter (file-grouped, line-sorted, styled output)"
```

---

## Task 7: `ReporterFactory` routes `pretty` + signature gains `Styler`

**Files:**
- Modify: `src/Report/ReporterFactory.php:17-32` (signature + `pretty` arm)
- Modify: `src/Console/Application.php:136` (pass `NullStyler` temporarily — full `ColorPolicy` wiring lands in Task 8)
- Modify: `tests/Report/ReporterFactoryTest.php` (add `Styler` arg to every `create()` call; add `pretty` case)

**Interfaces:**
- Consumes: `Options`, `Reporter` interface, `Styler` (Task 1), `PrettyReporter` (Task 6), all existing reporters.
- Produces: `ReporterFactory::create(Options $options, Styler $styler): Reporter` — routes `pretty` to `new PrettyReporter($thresholds, $paths, $options->explain, $styler)`; other formats ignore the `Styler` arg (they are not color-capable). `Application` passes `new NullStyler()` temporarily so behavior is unchanged; Task 8 wires the real `ColorPolicy`-resolved `Styler`.

- [ ] **Step 1: Write the failing test** — update `tests/Report/ReporterFactoryTest.php`:

(a) Add `use PhpTramp\Report\NullStyler;` import.

(b) Add `NullStyler` arg to every `create()` call: `new NullStyler()`.

(c) Add a `pretty` case:

```php
public function testPrettyFormatRoutesToPrettyReporter(): void
{
    $factory = new ReporterFactory('/not/matching');
    $reporter = $factory->create(new Options(format: 'pretty', limit: 2, warnLimit: 0), new NullStyler());

    self::assertStringContainsString('FINDING', $reporter->render($this->qualifyingFinding()));
}
```

(d) Update `testUnroutableFormatThrows` to pass `new NullStyler()`.

(e) The existing test `testUnroutableFormatThrows` asserts `"format 'xml' is not implemented yet."` — this stays valid (the `default` arm message is unchanged).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Report/ReporterFactoryTest.php`
Expected: FAIL — `create()` does not accept a second arg; `pretty` arm missing.

- [ ] **Step 3: Update `ReporterFactory`**

In `src/Report/ReporterFactory.php`:

```php
public function create(Options $options, Styler $styler): Reporter
{
    $thresholds = new Thresholds($options->limit, $options->warnLimit, $options->minClasses);
    $paths = new Paths($this->workingDirectory);

    return match ($options->format) {
        'text' => new TextReporter($thresholds, $paths, $options->explain),
        'pretty' => new PrettyReporter($thresholds, $paths, $options->explain, $styler),
        'json' => new JsonReporter($thresholds, $paths, $options->changedOnly),
        'github' => new GithubReporter($thresholds, $paths),
        'checkstyle' => new CheckstyleReporter($thresholds, $paths),
        'sarif' => new SarifReporter($thresholds, $paths),
        'summary' => new SummaryReporter($thresholds),
        // Unreachable: ArgvParser::validateFormat rejects any format outside
        // VALID_FORMATS at parse time, so every case above is exhaustive.
        default => throw new InvalidArgsException("format '{$options->format}' is not implemented yet."),
    };
}
```

Add `use PhpTramp\Report\Styler;` (already in the same namespace — no import needed). Add `use PhpTramp\Report\PrettyReporter;` is also same-namespace; no import.

- [ ] **Step 4: Update `Application` to pass `NullStyler` temporarily**

In `src/Console/Application.php` line 136, change:

```php
$reporter = (new ReporterFactory($this->workingDirectory()))->create($options);
```

to:

```php
$reporter = (new ReporterFactory($this->workingDirectory()))->create($options, new NullStyler());
```

Add `use PhpTramp\Report\NullStyler;` to the imports at the top of `Application.php` (around line 26-28). This is temporary — Task 8 replaces it with the `ColorPolicy`-resolved `Styler`.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Report/ReporterFactoryTest.php tests/Console/ApplicationTest.php`
Expected: PASS.

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green.

- [ ] **Step 7: Commit**

```bash
git add src/Report/ReporterFactory.php src/Console/Application.php tests/Report/ReporterFactoryTest.php
git commit -m "feat(#15): route --format pretty through ReporterFactory with Styler injection"
```

---

## Task 8: `Application` resolves `ColorPolicy` + injects `Styler`

**Files:**
- Modify: `src/Console/Application.php:129-166` (`analyze()` — replace the temporary `NullStyler` with a `ColorPolicy`-resolved `Styler`)
- Test: `tests/Console/ApplicationTest.php` (add cases: `--color=always` on the non-TTY memory stream → ANSI escapes in output; `--color=never` → no escapes; `--format pretty` default-styler on non-TTY → no escapes)

**Interfaces:**
- Consumes: `ColorPolicy` (Task 3), `Styler`/`NullStyler`/`AnsiStyler`, `Options::$colorMode`, `stream_isatty`, `getenv`.
- Produces: `Application::analyze()` resolves the `Styler` once from `ColorPolicy::from($options->colorMode, stream_isatty($this->stdout), $this->noColorSet())` and passes it to `ReporterFactory::create()`. The `format` default is still `'text'` (Task 9 flips it); this task only adds `--color` behavior for explicit `--format pretty`.

- [ ] **Step 1: Write the failing tests** — add to `tests/Console/ApplicationTest.php`. These need a fixture dir with at least one finding; reuse the existing fixture-setup helpers (see `ApplicationTest.php` around lines 110+ for the `--format json` / `--format summary` patterns — copy the smallest one as the template).

```php
public function testPrettyFormatWithColorAlwaysEmitsAnsiOnNonTty(): void
{
    [$exitCode, $out] = $this->runPretty('--color=always');

    self::assertSame(0, $exitCode);
    self::assertStringContainsString("\e[1;31mFINDING\e[0m", $out);
}

public function testPrettyFormatWithColorNeverOmitsAnsiOnNonTty(): void
{
    [$exitCode, $out] = $this->runPretty('--color=never');

    self::assertSame(0, $exitCode);
    self::assertStringNotContainsString("\e[", $out);
}

public function testPrettyFormatWithColorAutoOmitsAnsiOnNonTty(): void
{
    [$exitCode, $out] = $this->runPretty('--color=auto');

    self::assertSame(0, $exitCode);
    self::assertStringNotContainsString("\e[", $out);
}

/** @return array{0: int, 1: string} */
private function runPretty(string $colorFlag): array
{
    // Build a one-finding fixture in a temp dir, run phptramp against it.
    // Copy the smallest existing ApplicationTest fixture-setup pattern
    // (see the --format json test around line 188) and pass
    // ['--folder', $dir, '--format', 'pretty', '--limit', '1', $colorFlag, '--no-config'].
    // Return [$exitCode, self::contents($this->stdout)].
    // (Implementation follows the existing helper idiom — see the test class's
    // other fixture-based cases for the exact temp-dir + src/ creation.)
    // ... existing fixture idiom ...
}
```

(Fill in the fixture-body following the existing `ApplicationTest` fixture idiom — the smallest existing `--format json` case is the template. The exact temp-dir setup varies by test; mirror it.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Console/ApplicationTest.php`
Expected: FAIL — `--color=always` produces no ANSI (because `Application` still passes `NullStyler` from Task 7).

- [ ] **Step 3: Wire `ColorPolicy` into `Application::analyze()`**

In `src/Console/Application.php`, update `analyze()` (line 129-166). Replace the temporary `new NullStyler()` (from Task 7) with:

```php
$reporter = (new ReporterFactory($this->workingDirectory()))->create(
    $options,
    ColorPolicy::from(
        $options->colorMode,
        stream_isatty($this->stdout),
        $this->noColorSet(),
    ),
);
```

Add the helper near the bottom of `Application`:

```php
/**
 * NO_COLOR is honored only in auto mode (Q11 precedence): always/never are
 * absolute. Any non-empty value disables color.
 */
private function noColorSet(): bool
{
    $value = getenv('NO_COLOR');

    return $value !== false && $value !== '';
}
```

Add imports at the top of `Application.php`: `use PhpTramp\Report\ColorPolicy;` (drop `use PhpTramp\Report\NullStyler;` from Task 7 — no longer needed). `stream_isatty` is a global builtin, no import.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Console/ApplicationTest.php`
Expected: PASS. (The memory streams are non-TTY, so `--color=auto` → `NullStyler` → no ANSI; `--color=always` → `AnsiStyler` → ANSI; `--color=never` → `NullStyler` → no ANSI.)

- [ ] **Step 5: Run `composer check`**

Run: `composer check`
Expected: green. (If `NO_COLOR` is set in the test environment, `--color=auto` tests still pass because `--color=always`/`never` are absolute. If the test runner sets `NO_COLOR` globally, the `--color=auto` assertion `assertStringNotContainsString("\e[")` still holds. If `NO_COLOR` is unset, same. So the tests are robust to the env.)

- [ ] **Step 6: Commit**

```bash
git add src/Console/Application.php tests/Console/ApplicationTest.php
git commit -m "feat(#15): resolve ColorPolicy (TTY + NO_COLOR) in Application and inject Styler"
```

---

## Task 9: Default flip — `Options::$format` → `'pretty'`; `Application` non-TTY downgrade

**Files:**
- Modify: `src/Console/Options.php:28` (`$format` default `'text'` → `'pretty'`)
- Modify: `src/Console/ArgvParser.php:48` (`VALID_FORMATS` add `'pretty'`); line 57 (`$format` default `'text'` → `'pretty'`)
- Modify: `src/Console/Application.php` (`run()` or `analyze()` — downgrade `pretty` → `text` when `!stream_isatty($this->stdout)`)
- Modify: `tests/Console/ArgvParserTest.php:27` (`'text'` → `'pretty'` default assertion); line 101 (add `'pretty'` to the `validFormatProvider` list)
- Modify: `tests/Console/OptionsTest.php` (default `format` assertion → `'pretty'`)
- Modify: `tests/Report/ReporterFactoryTest.php` (`testUnroutableFormatThrows` still uses `'xml'` — unchanged; but verify no test relies on the default being `'text'`)

**Interfaces:**
- Consumes: `Options`, `ArgvParser`, `Application`, `stream_isatty`.
- Produces: the default `--format` is `pretty`; non-TTY invocations silently use `text` instead *when `--color=auto`* (the default). The TTY-downgrade happens in `Application` *after* parsing, *before* reporter construction — so `Options::$format` reports `pretty` (the user's intent) but the rendered output is `text` on a pipe. `--color=never` is an explicit engagement that keeps plain `pretty` (file-grouped layout, no ANSI) through a pipe; `--color=always` keeps colored `pretty` (the escape hatch for `less -R`).

- [ ] **Step 1: Write the failing tests** — in `tests/Console/ArgvParserTest.php`:

(a) Change line 27 from `self::assertSame('text', $o->format);` to `self::assertSame('pretty', $o->format);`.

(b) In `validFormatProvider()` (line 99-104), add `'pretty'` to the list:

```php
foreach (['text', 'pretty', 'json', 'github', 'checkstyle', 'sarif', 'summary'] as $format) {
    yield $format => [$format];
}
```

In `tests/Console/ApplicationTest.php`, add:

```php
public function testDefaultFormatDowngradesToTextOnNonTty(): void
{
    // No --format flag: default is 'pretty', but STDOUT is php://memory
    // (non-TTY), so Application downgrades to 'text'. The output should
    // contain the text-format header 'FINDING' (pretty also emits 'FINDING',
    // but with NullStyler the pretty output would start with a file header
    // line — so assert the ABSENCE of the pretty-specific blank-line-after-
    // file-header shape, or assert the text-specific shape).
    //
    // Simplest: assert the output does NOT start with a file path header
    // (pretty's first line is the file path; text's first line is 'FINDING').
    [$exitCode, $out] = $this->runWithDefaults();
    self::assertSame(0, $exitCode);
    self::assertStringStartsWith('FINDING', $out);
}

public function testExplicitFormatTextIsHonoredEvenOnNonTty(): void
{
    [$exitCode, $out] = $this->runWithDefaults('--format', 'text');
    self::assertSame(0, $exitCode);
    self::assertStringStartsWith('FINDING', $out);
}

public function testExplicitFormatPrettyIsHonoredOnNonTty(): void
{
    [$exitCode, $out] = $this->runWithDefaults('--format', 'pretty', '--color=never');
    self::assertSame(0, $exitCode);
    // Pretty output starts with the file header (a path), not 'FINDING'.
    self::assertDoesNotStartWith('FINDING', $out);
}
```

(`runWithDefaults` is a helper following the existing fixture idiom — pass the temp-dir setup + `--no-config` + `--limit 1` to force a finding. Mirror the smallest existing fixture case.)

If `tests/Console/OptionsTest.php` asserts the `format` default, update it to `'pretty'`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php tests/Console/ApplicationTest.php`
Expected: FAIL — defaults still `'text'`; `pretty` not in `VALID_FORMATS`; `Application` doesn't downgrade.

- [ ] **Step 3: Flip the defaults**

(a) In `src/Console/Options.php` line 28: `public readonly string $format = 'text',` → `public readonly string $format = 'pretty',`.

(b) In `src/Console/ArgvParser.php` line 48: add `'pretty'` to `VALID_FORMATS`:

```php
private const VALID_FORMATS = ['text', 'pretty', 'json', 'github', 'checkstyle', 'sarif', 'summary'];
```

(c) Line 57: `private string $format = 'text';` → `private string $format = 'pretty';`.

- [ ] **Step 4: Add the non-TTY downgrade to `Application`**

In `src/Console/Application.php`, inside `analyze()` (or just before the `ReporterFactory::create()` call), downgrade the format:

```php
$format = $options->format === 'pretty' && ! stream_isatty($this->stdout)
    ? 'text'
    : $options->format;

$reporter = (new ReporterFactory($this->workingDirectory()))->create(
    // Re-construct Options with the downgraded format? No — ReporterFactory
    // reads $options->format. The cleanest path is a tiny value object or
    // passing the format string separately. But Options is immutable and
    // wide; re-constructing it just to flip one field is noisy.
    //
    // Cleanest: ReporterFactory::create() already takes Options; instead of
    // reconstructing Options, add a format parameter? No — that re-opens the
    // "boolean flag selects behaviour" smell.
    //
    // Best: downgrade is a pure transformation on the Options before handing
    // it to the factory. Add Options::withFormat(string): self — a copy
    // helper like Finding::withChain(). One-line, immutable, no flag.
    $options->withFormat($format),
    ColorPolicy::from(
        $options->colorMode,
        stream_isatty($this->stdout),
        $this->noColorSet(),
    ),
);
```

Add to `Options` (one method):

```php
public function withFormat(string $format): self
{
    return new self(
        folders: $this->folders,
        files: $this->files,
        limit: $this->limit,
        warnLimit: $this->warnLimit,
        minClasses: $this->minClasses,
        format: $format,
        colorMode: $this->colorMode,
        explain: $this->explain,
        changedOnly: $this->changedOnly,
        gitBase: $this->gitBase,
        diff: $this->diff,
        baseline: $this->baseline,
        generateBaseline: $this->generateBaseline,
        dumpIndex: $this->dumpIndex,
        noConfig: $this->noConfig,
        help: $this->help,
        version: $this->version,
        failOnStale: $this->failOnStale,
        exclude: $this->exclude,
        noCache: $this->noCache,
        cacheDir: $this->cacheDir,
    );
}
```

(This matches `Finding::withChain()`'s precedent — see `src/Chain/Finding.php:40-53`.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php tests/Console/ApplicationTest.php tests/Report/ReporterFactoryTest.php`
Expected: PASS. Existing `ApplicationTest` cases that don't pass `--format` and run on memory streams now render `text` (downgraded from `pretty`) — same output as before the flip, so their fixtures stay green.

- [ ] **Step 6: Run `composer check`**

Run: `composer check`
Expected: green. (`Options::withFormat` is a 1-line method; PHPMD stays clean. The `Application::analyze` body grows slightly — the `ColorPolicy::from(...)` call is multi-line but low-complexity.)

- [ ] **Step 7: Commit**

```bash
git add src/Console/Options.php src/Console/ArgvParser.php src/Console/Application.php tests/Console/ArgvParserTest.php tests/Console/OptionsTest.php tests/Console/ApplicationTest.php
git commit -m "feat(#15): flip default --format to pretty with non-TTY downgrade to text"
```

---

## Task 10: Docs — README, help text, `phptramp.dist.json`, CHANGELOG

**Files:**
- Modify: `README.md` (formats table: add `pretty` row + default statement; `--format` flag line; new `--color` flag line; `NO_COLOR` note)
- Modify: `src/Console/Application.php:365-422` (`helpText()` heredoc: `--format` line, `--color` line, formats table reference)
- Modify: `phptramp.dist.json` (add `"colorMode": "auto"` key — document the new config key; the dist file is the documented schema example)
- Modify: `CHANGELOG.md` (new entry: default format change + `--color` + `NO_COLOR` + migration note)
- Modify: `docs/plan.md` if it documents the default format (frozen-semantics revision — flag in commit message)

**No tests** — docs only. (`ApplicationTest::testHelpDocumentsTheCliContract` at `tests/Console/ApplicationTest.php:90` asserts `--format` is in the help text; add `--color` to that list too so the test gates the doc.)

- [ ] **Step 1: Add `--color` to the help-text test gate**

In `tests/Console/ApplicationTest.php` line 95-98, add `'--color'` to the `$documented` array:

```php
$documented = [
    '--folder', '--file', '--files', '--limit',
    '--format', '--color', '--changed-only', '--baseline', '--no-cache', 'Exit codes',
];
```

- [ ] **Step 2: Update `Application::helpText()`**

In `src/Console/Application.php` heredoc (line 365-422):

(a) Update the `--format` line (line 386):

```
  --format <fmt>            text|pretty|json|github|checkstyle|sarif|summary (default: pretty on TTY, text otherwise)
```

(b) Add a `--color` line after `--format`:

```
  --color <mode>            always|auto|never (default: auto; honors NO_COLOR in auto mode)
```

(c) In the "Status" footer (line 414-420), update to mention `pretty`:

```
Status: v0.1.0 — cross-file chain reporting (seven formats incl. pretty colored
terminal output), diff-aware mode (--changed-only / --git-base / --diff),
baselining (--generate-baseline / --baseline / --fail-on-stale),
#[TrampIgnore] / // phptramp-ignore suppression, the phptramp.json
config, --warn-limit, --min-classes, --color, and a per-file index cache
(--no-cache / the `cache` config key) are all shipped. See docs/plan.md.
```

- [ ] **Step 3: Update `README.md`**

(a) Around line 105, update the `--format` line:

```
  --format <fmt>            text|pretty|json|github|checkstyle|sarif|summary (default: pretty on TTY, text otherwise)
```

Add after it:

```
  --color <mode>            always|auto|never (default: auto; honors NO_COLOR in auto mode)
```

(b) In the "Output formats" section (line 182-199), add a `pretty` row to the table (before `text`):

```
| `pretty` | `src/Demo.php` (bold-blue file header) / `FINDING  $config: 3 pass-through hops across 4 classes` (colored, grouped by file) |
```

Update the prose: "`--format` selects the renderer; all seven are implemented..." Add a note: "`pretty` is the default when STDOUT is a TTY; non-TTY invocations (pipes, CI redirection) fall back to `text` automatically. Use `--color=never` to suppress color on a TTY, `--color=always` to force it in a pipe, or set the `NO_COLOR` environment variable."

- [ ] **Step 4: Update `phptramp.dist.json`**

```json
{
    "paths": ["src"],
    "limit": 6,
    "warnLimit": 4,
    "colorMode": "auto"
}
```

- [ ] **Step 5: Update `CHANGELOG.md`**

Add a new entry (top of the file):

```
## [Unreleased]

### Added
- `--format pretty`: colored, file-grouped, line-sorted terminal output. New
  default when STDOUT is a TTY.
- `--color=always|auto|never` flag (default `auto`). `NO_COLOR` environment
  variable honored in `auto` mode; `always`/`never` are absolute.
- `colorMode` config key in `phptramp.json` / `phptramp.dist.json`.

### Changed
- Default `--format` is now `pretty` (TTY-gated). Non-TTY invocations
  (pipes, CI redirection) automatically use `text`. Pass `--format text`
  explicitly to force the old default on a TTY.

### Migration
- If you snapshot `--format text` output against a baseline, no change is
  needed — pipes get `text` automatically.
- If you run `phptramp` interactively and pipe the default output, you now
  get `text` (no ANSI) in the pipe. To force color into a pipe, pass
  `--color=always`.
```

- [ ] **Step 6: Update `docs/plan.md` if it documents the default format**

Search `docs/plan.md` for `--format` / `default.*text` / `format: text`. If the default format is documented there, update to `pretty` (TTY-gated) and add a frozen-semantics-revision note in the commit message.

- [ ] **Step 7: Run `composer check`**

Run: `composer check`
Expected: green (the `--color` help-text test gate passes).

- [ ] **Step 8: Commit**

```bash
git add README.md src/Console/Application.php phptramp.dist.json CHANGELOG.md docs/plan.md tests/Console/ApplicationTest.php
git commit -m "docs(#15): document --format pretty, --color, NO_COLOR, and the new default"
```

---

## Self-Review

**1. Spec coverage:**

- New `--format pretty` value → Task 6 (PrettyReporter) + Task 7 (factory routing) + Task 9 (VALID_FORMATS + default). ✓
- `pretty` becomes default when STDOUT is a TTY → Task 9 (default flip + downgrade). ✓
- Non-TTY falls back to `text` → Task 9 (downgrade wiring). ✓
- `text` remains opt-in, frozen → Task 6 notes `TextReporter` untouched; no task modifies `TextReporter.php` or `TextReporterTest.php`. ✓
- `--color=always|auto|never` flag → Task 4 (ArgvParser + Options). ✓
- `NO_COLOR` honored in `auto` only; `always`/`never` absolute → Task 3 (ColorPolicy truth table) + Task 8 (Application reads `getenv('NO_COLOR')`). ✓
- `ColorPolicy::from(string, bool, bool): Styler` pure value object → Task 3. ✓
- `Styler` interface, per-role methods, `NullStyler`/`AnsiStyler` → Tasks 1-2. ✓
- `PrettyReporter` takes `Styler`, no color branching inside → Task 6. ✓
- `ReporterFactory` receives `Styler` → Task 7. ✓
- File-grouped + within-file line-sorted → Task 6 (`groupByFile`). ✓
- No borders, structural typography → Task 6 (layout). ✓
- `FINDING`/`WARNING` verbatim + color → Task 6 (`header` + `Styler::severity`). ✓
- `origin`/`hop N`/`terminal`/`note` labels dim → Task 6 (`Styler::label`). ✓
- FQMN plain, `($param)` cyan... wait — the palette table says `($param)` is **cyan** (`\e[36m`), but the `Styler` interface routes the param through `Styler::param` which is **bold** (`\e[1m`). This is an inconsistency: in the header, `$param` is bold; in the row's `($param)` fragment, the palette said cyan. The grilled Q8 palette table lists `$param` in header as bold, and `($param)` appended to FQMN as cyan. These are two different roles for the same parameter name. The `Styler::param` method serves the header (bold). The row's `($param)` needs a different method (`paramInMethod`? cyan?) OR `param` is used in both (bold, not cyan).

  This is a real spec gap I missed in grilling. Resolution: the Q8 table is the authority — `($param)` in rows is cyan, distinct from the header's bold `$param`. Add a `paramInMethod(string $name): string` method to `Styler` (cyan). Update Task 1's interface, Task 1's `NullStyler`, Task 2's `AnsiStyler` (cyan = `\e[36m`), and Task 6's `PrettyReporter::hopLine` (use `paramInMethod` for the row's param fragment, keep `param` for the header). **This is a fix-up to apply across Tasks 1, 2, 6 before executing them.** Flagged here so the executing agent applies it.

  Actually — re-reading the Q8 palette: "`($param)` appended to FQMN | cyan". And "`$param` in header | bold default". Two roles. The cleanest fix: rename `Styler::param` to be header-only (bold), and add `Styler::paramInMethod` (cyan) for rows. Apply this in Tasks 1, 2, 6.

- Location dim → Task 6 (`Styler::location`). ✓
- `*YOURS*` bold-magenta → Task 6 (`Styler::annotation`). ✓
- Terminal-kind dim-green → Task 6 (`Styler::terminalKind`). ✓
- File header bold-blue → Task 6 (`Styler::fileHeader`). ✓
- `64` ASCII `-` divider dim → Task 6 (`divider` + `DIVIDER_WIDTH=64`). ✓
- Summary bold, same shape as `text`'s → Task 6 (`summary` + `limitClause`). ✓
- `No tramp data found` green → Task 6 (`success`). ✓
- `explain:` block as in `text` → Task 6 (`explainLines`). ✓
- No second production dependency → all ANSI self-emitted by `AnsiStyler`. ✓
- No Unicode-width gamble → no box-drawing, no icons. ✓
- No boolean flag params inside rendering → color on/off in `Styler`. ✓
- `text` frozen → no task touches `TextReporter`. ✓
- README + help text + config schema + CHANGELOG → Task 10. ✓
- `colorMode` config key → Task 5. ✓

**2. Placeholder scan:**

- Task 8's `runPretty` helper and Task 9's `runWithDefaults` helper say "mirror the existing fixture idiom" — this is a real placeholder risk. The executing agent must look up the smallest existing `--format json` ApplicationTest case (around `tests/Console/ApplicationTest.php:188`) and copy its temp-dir + `src/` creation pattern. The idiom is concrete in the existing test; the plan points at it. This is acceptable (the alternative is duplicating 40 lines of fixture-setup code that already exists), but the agent MUST read `ApplicationTest.php` before writing these helpers.

- Task 3's `ColorPolicy` has two implementation forms (constructor + minimal); the plan says "use the minimal form." Clear.

- No "TBD", "implement later", "add appropriate error handling", "similar to Task N" elsewhere.

**3. Type consistency:**

- `Styler::severity(string, Severity): string` — used in Task 6's `header` as `$this->styler->severity($keyword, $severity)`. ✓
- `Styler::param(string): string` — used in Task 6's `header` as `$this->styler->param($finding->param)`. ✓ (And `paramInMethod` per the gap fix above.)
- `ColorPolicy::from(string, bool, bool): Styler` — used in Task 8's `Application::analyze`. ✓
- `Options::withFormat(string): self` — used in Task 9's `Application::analyze`. ✓
- `ReporterFactory::create(Options, Styler): Reporter` — used in Tasks 7, 8, 9. ✓
- `PrettyReporter(Thresholds, Paths, bool, Styler)` — used in Task 7's factory arm. ✓

**Gap-fix applied (from spec-coverage #1):** Add `paramInMethod(string): string` to `Styler`, `NullStyler`, `AnsiStyler`. Use it in `PrettyReporter::hopLine` for the row's `($param)` fragment. Keep `Styler::param` for the header's bold `$param`. This makes the interface 11 methods (not 10). The executing agent applies this before running Task 1, 2, 6 tests.

---

## Execution Handoff

Plan complete and saved to `docs/superpowers/plans/2026-08-12-pretty-format.md`. Two execution options:

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, review between tasks, fast iteration.

**2. Inline Execution** — Execute tasks in this session using `superpowers:executing-plans`, batch execution with checkpoints.

Which approach?
