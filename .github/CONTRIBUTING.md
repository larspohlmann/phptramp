# Contributing to phptramp

Thanks for wanting to contribute! This guide covers the workflow and the
gates a change must pass. The project deliberately keeps a high bar: the
whole product is semantic correctness, so the process is strict.

## Getting started

phptramp is a Composer library targeting PHP ≥ 8.2 with a single production
dependency (`nikic/php-parser`). `composer.lock` is intentionally not
committed.

```bash
git clone https://github.com/larspohlmann/phptramp.git
cd phptramp
composer update
```

Branching follows **git-flow (AVH)**. Configure it once per clone
(version-tag prefix `v`):

```bash
git flow init -d
```

## Before you build: check the semantics

The analysis semantics — what counts as a hop, how calls resolve, why the
tool prefers missing a finding over inventing one — are **frozen in
[docs/plan.md](../docs/plan.md)**. If your change touches behavior that
plan doesn't cover, pick the conservative option (fewer findings), add a
fixture documenting the choice, and say so in the commit message. Never
change behavior silently.

Two hard non-goals, both deliberate: **no auto-fix**, and **no second
production dependency**.

## The gates

CI runs exactly these; run them locally so a red pipeline never surprises you:

```bash
composer cs          # PHP_CodeSniffer, PSR-12 (composer cs:fix to autofix)
composer stan        # PHPStan, level max
composer md          # PHPMD, codesize ruleset
composer test        # PHPUnit
composer check       # all four — must be green before every commit
```

Pull requests additionally run **diff-scoped mutation testing** (Infection,
MSI ≥ 80) — and it gates the merge. Run it locally before opening or
updating a PR (needs a coverage driver: `pcov` or `xdebug`):

```bash
git fetch origin main
composer infection:diff
```

An escaped mutant is a real finding: add a test that would fail if that
line were wrong. Don't tune the threshold.

## Workflow

1. **Open or pick an issue** — every change should trace to one.
2. **Branch off `develop`**, embedding the issue number:
   `git flow feature start 42-short-slug` → `feature/42-short-slug`.
3. **Fixture-first TDD, one task per commit.** Write the failing test
   (usually a fixture pair under `tests/fixtures/<case>/` — input codebase
   plus expected JSON output), confirm it fails for the expected reason,
   write the minimal code, go green, run `composer check`, commit.
4. **Conventional commits with the issue number in the type:**
   `feat(#42): title` (also `fix`, `test`, `refactor`, `docs`, `ci`).
5. **PR into `develop`** (never `main`), body containing `Closes #42`.

## Code style

Clean Code is mandatory, not advisory — see the enforced specifics in
[phpcs.xml.dist](../phpcs.xml.dist), [phpstan.neon.dist](../phpstan.neon.dist)
and [phpmd.xml.dist](../phpmd.xml.dist). The short version: intention-revealing
names, short single-purpose methods, guard clauses over nesting, `final`
classes with `readonly` promoted constructor properties, typed exceptions
instead of magic return values, and comments only for *why*. Tests are held
to the same standard as production code; fixture inputs are the one
exception (they are analyzer input data).

## Reporting bugs

Use the [bug report form](https://github.com/larspohlmann/phptramp/issues/new?template=bug_report.yml) —
a minimal reproducing PHP snippet plus the exact command is the fastest
path to a fix.
