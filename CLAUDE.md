# phptramp

Composer-installable static analyzer that reports **tramp data** — parameters
passed through chains of PHP methods that never use them. Pure CLI, CI
(diff-aware, baseline-able), and IDE use. PHP ≥ 8.2; the only production
dependency is `nikic/php-parser ^5`. See [docs/plan.md](docs/plan.md) for the
phased implementation plan and the frozen core semantics.

## Run the CI checks locally

CI runs exactly these tools — run them locally before you commit or push so a
red pipeline never comes as a surprise. Every one has a `composer` script:

```bash
composer cs          # PHP_CodeSniffer, PSR-12 over src/ and tests/ (composer cs:fix to autofix)
composer stan        # PHPStan level max over src/ and bin/
composer md          # PHPMD, codesize ruleset over src/
composer test        # PHPUnit
composer check       # cs + stan + md + test — the pre-commit gate
```

`composer check` mirrors the `tests` + `static` CI jobs (the matrix runs the
suite on PHP 8.2 / 8.3 / 8.4; static analysis runs on 8.4). **It must be green
before every commit.**

### Mutation testing — the PR gate that `composer check` does not cover

Pull requests additionally run diff-scoped Infection, and it gates the merge
(`minMsi 80`). `composer check` passing is **not** enough — run the mutation
job locally before opening or updating a PR:

```bash
composer infection        # mutation testing over all of src/ (needs pcov or xdebug)
composer infection:diff   # over the files this branch changes — exactly what CI gates
```

`infection:diff` diffs against `origin/main`, so `git fetch origin main` first.
An escaped mutant is a test-suite gap on code whose whole product is semantic
correctness — treat it as a real finding: add a test that would fail if that
line were wrong, do not tune the threshold. Inspect escaped mutants in
`infection.log` or with `--show-mutations=max`.

## Layout

| Path | What |
|---|---|
| `bin/phptramp` | CLI entry point |
| `src/Console/` | `ArgvParser` → `Options`, `Application` wiring |
| `src/Discovery/` | `FileLocator` — folders/files → `*.php` list |
| `src/Index/` | php-parser indexing + the parameter usage classifier (the semantic heart) |
| `src/Resolve/`, `src/Chain/` | call resolution + cross-file chain stitching (Phase 2) |
| `tests/**` | mirrors `src/`; `tests/fixtures/<case>/` are input-codebase + expected-output pairs |

## Working style

- **Strict fixture-first TDD, one task per commit.** Write the failing test,
  confirm it fails for the expected reason, write the minimal code, go green,
  run `composer check`, commit. Never batch tasks into one commit.
- **Conventional commits** (`feat:`, `fix:`, `test:`, `refactor:`, `docs:`,
  `ci:`); the body explains *why* when it is not obvious.
- **Semantics are frozen in [docs/plan.md](docs/plan.md).** If a case is not
  covered there, pick the conservative option (fewer findings), add a fixture
  documenting the choice, and flag it in the commit message — never improvise
  silently.
- **No second production dependency, and no auto-fix — ever** (both are
  deliberate, documented non-goals).
