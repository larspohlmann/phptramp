# Release Docs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the README into a landing page and add the standard community health files, ready for the v0.1.0 release.

**Architecture:** Pure documentation work — no PHP changes. Deep reference material moves from README.md into `docs/configuration.md` and `docs/baseline.md`; community health files land under `.github/` (plus `CODE_OF_CONDUCT.md` at repo root, where GitHub detects it). Spec: `docs/superpowers/specs/2026-08-14-release-docs-design.md`.

**Tech Stack:** Markdown, GitHub issue-form YAML.

## Global Constraints

- Branch: `feature/21-release-docs` (already created). Commits: `docs(#21): <title>`.
- No changes under `src/`, `bin/`, `tests/` — `composer check` must stay green untouched.
- Repository: `larspohlmann/phptramp`; default branch `develop`; PRs target `develop`.
- CoC enforcement contact (maintainer-approved for publication): `lars.pohlmann@googlemail.com`.
- Security reporting: GitHub private vulnerability reporting only — no email channel.
- When moving README content, move it **verbatim or tightened — never reworded semantically**. The README's semantic claims (hop definition, fingerprint fields, cache validation model) are frozen documentation of frozen semantics.
- "Current README" line numbers below refer to README.md as of commit `f8ac384` (before Task 5 rewrites it). Tasks 3 and 4 must run before Task 5.

---

### Task 1: Issue templates, PR template, security policy

**Files:**
- Create: `.github/ISSUE_TEMPLATE/bug_report.yml`
- Create: `.github/ISSUE_TEMPLATE/feature_request.yml`
- Create: `.github/ISSUE_TEMPLATE/config.yml`
- Create: `.github/PULL_REQUEST_TEMPLATE.md`
- Create: `.github/SECURITY.md`

**Interfaces:**
- Consumes: nothing.
- Produces: files GitHub picks up by path convention; no other task references them.

- [ ] **Step 1: Write `.github/ISSUE_TEMPLATE/bug_report.yml`**

```yaml
name: Bug report
description: Report a wrong finding, a missed finding, or a crash
labels: ["bug"]
body:
  - type: input
    id: phptramp-version
    attributes:
      label: phptramp version
      description: Output of `composer show larspohlmann/phptramp` (or the git ref).
      placeholder: "0.1.0"
    validations:
      required: true
  - type: input
    id: php-version
    attributes:
      label: PHP version
      placeholder: "8.4.23"
    validations:
      required: true
  - type: textarea
    id: code
    attributes:
      label: Minimal reproducing PHP code
      description: The smallest self-contained PHP snippet that triggers the behavior.
      render: php
    validations:
      required: true
  - type: input
    id: command
    attributes:
      label: Exact command
      placeholder: "vendor/bin/phptramp --folder src --limit 3"
    validations:
      required: true
  - type: textarea
    id: expected
    attributes:
      label: Expected output
      description: What you expected phptramp to report, and why.
    validations:
      required: true
  - type: textarea
    id: actual
    attributes:
      label: Actual output
      description: Full output, including stderr. Use `--explain` if call resolution looks wrong.
    validations:
      required: true
```

- [ ] **Step 2: Write `.github/ISSUE_TEMPLATE/feature_request.yml`**

```yaml
name: Feature request
description: Suggest a new capability or option
labels: ["enhancement"]
body:
  - type: textarea
    id: problem
    attributes:
      label: Problem
      description: What are you trying to do that phptramp doesn't support today?
    validations:
      required: true
  - type: textarea
    id: proposal
    attributes:
      label: Proposed behavior
      description: What should phptramp do, ideally with an example invocation and output.
    validations:
      required: true
  - type: markdown
    attributes:
      value: |
        Note: the core analysis semantics are frozen in
        [docs/plan.md](https://github.com/larspohlmann/phptramp/blob/develop/docs/plan.md),
        and when a case is ambiguous phptramp deliberately picks the conservative
        option (fewer findings). Auto-fixing and second production dependencies
        are documented non-goals.
```

- [ ] **Step 3: Write `.github/ISSUE_TEMPLATE/config.yml`**

```yaml
blank_issues_enabled: true
```

- [ ] **Step 4: Write `.github/PULL_REQUEST_TEMPLATE.md`**

```markdown
Closes #

## What & why

<!-- What does this change, and why? Link the issue for full context. -->

## Checklist

- [ ] `composer check` is green (cs + stan + md + test)
- [ ] `composer infection:diff` is green (`git fetch origin main` first — CI gates the diff at MSI ≥ 80)
- [ ] Semantic changes come with a fixture under `tests/fixtures/`
- [ ] Title is a conventional commit with the issue number, e.g. `feat(#123): title`
- [ ] Targets `develop` (never `main`)
```

- [ ] **Step 5: Write `.github/SECURITY.md`**

```markdown
# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Instead, use
GitHub's private vulnerability reporting:
[Report a vulnerability](https://github.com/larspohlmann/phptramp/security/advisories/new)
(also reachable via the repository's **Security** tab).

You can expect an initial response within 7 days. Once a fix is released,
the advisory is published and the reporter credited (unless you prefer
otherwise).

phptramp is a static analyzer: it parses PHP source as *data* and never
executes analyzed code. Reports about phptramp being made to crash or
mis-report on crafted input are ordinary bugs — file those as regular
[bug reports](https://github.com/larspohlmann/phptramp/issues/new?template=bug_report.yml).
A vulnerability here means something like path traversal, cache poisoning
that alters findings, or code execution triggered by analyzed input.
```

- [ ] **Step 6: Validate the YAML forms parse**

Run: `ruby -ryaml -e 'YAML.load_file(".github/ISSUE_TEMPLATE/bug_report.yml"); YAML.load_file(".github/ISSUE_TEMPLATE/feature_request.yml"); YAML.load_file(".github/ISSUE_TEMPLATE/config.yml"); puts "OK"`
Expected: `OK` (if ruby is unavailable, use `php -r` with `symfony/yaml` — not installed — so fall back to careful visual inspection of indentation).

- [ ] **Step 7: Commit**

```bash
git add .github/ISSUE_TEMPLATE .github/PULL_REQUEST_TEMPLATE.md .github/SECURITY.md
git commit -m "docs(#21): add issue/PR templates and security policy"
```

---

### Task 2: Code of Conduct and contributing guide

**Files:**
- Create: `CODE_OF_CONDUCT.md` (repo root)
- Create: `.github/CONTRIBUTING.md`

**Interfaces:**
- Consumes: nothing.
- Produces: `CODE_OF_CONDUCT.md` and `.github/CONTRIBUTING.md`, linked from the README footer in Task 5 as `CODE_OF_CONDUCT.md` and `.github/CONTRIBUTING.md`.

- [ ] **Step 1: Write `CODE_OF_CONDUCT.md`**

Use the **Contributor Covenant v2.1 standard text, verbatim**, from
<https://www.contributor-covenant.org/version/2/1/code_of_conduct/code_of_conduct.md>
(license CC BY 4.0; the standard text ends with an attribution block — keep
it). Make exactly one edit: in the **Enforcement** section, replace the
placeholder `[INSERT CONTACT METHOD]` with `lars.pohlmann@googlemail.com`.
Fetch the text with WebFetch from the URL above; if unreachable, reproduce
the standard v2.1 text exactly.

- [ ] **Step 2: Write `.github/CONTRIBUTING.md`**

```markdown
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
updating a PR:

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
```

- [ ] **Step 3: Verify relative links resolve**

Run: `ls docs/plan.md phpcs.xml.dist phpstan.neon.dist phpmd.xml.dist CODE_OF_CONDUCT.md`
Expected: all five paths listed (CONTRIBUTING's `../`-relative links resolve from `.github/`).

- [ ] **Step 4: Commit**

```bash
git add CODE_OF_CONDUCT.md .github/CONTRIBUTING.md
git commit -m "docs(#21): add code of conduct and contributing guide"
```

---

### Task 3: `docs/configuration.md`

**Files:**
- Create: `docs/configuration.md`
- Reference (read-only): `README.md` — sections `## Configuration` (lines 149–176), `## Index cache` (178–194), `### Performance` (196–211)

**Interfaces:**
- Consumes: current README content (commit `f8ac384`).
- Produces: `docs/configuration.md` with headings `# Configuration`, `## The config file`, `## Keys`, `## Index cache`, `## Performance` — Task 5's README links to `docs/configuration.md` (no anchors).

- [ ] **Step 1: Write `docs/configuration.md`**

Structure, with content **moved from the current README**:

```markdown
# Configuration

<one-sentence intro: everything the CLI flags control can also be set in a
config file; flags win.>

## The config file

<README lines 151–154 verbatim: discovery of phptramp.json →
phptramp.dist.json, CWD, CLI precedence, unknown-key errors — plus the JSON
example from lines 155–164.>

## Keys

<the full key table from README lines 166–176, verbatim, including the
`excludeTerminals` union-vs-replace explanation and the `cache` key row.>

## Index cache

<README lines 180–194 verbatim: what is cached and why, the three bullet
points (disable/move; identity-validated and version-keyed; safe to wipe).>

## Performance

<README lines 198–211 verbatim: the measurement provenance line, the table,
and the closing paragraph — but change the phpstorm link target from
`docs/phpstorm.md` to `phpstorm.md`, since this file lives inside docs/.>
```

- [ ] **Step 2: Verify the intra-docs link**

Run: `grep -n 'phpstorm' docs/configuration.md`
Expected: link target is `phpstorm.md` (not `docs/phpstorm.md`).

- [ ] **Step 3: Commit**

```bash
git add docs/configuration.md
git commit -m "docs(#21): move configuration, cache, and performance reference to docs/"
```

---

### Task 4: `docs/baseline.md`

**Files:**
- Create: `docs/baseline.md`
- Reference (read-only): `README.md` — section `## Baseline` (lines 267–303)

**Interfaces:**
- Consumes: current README content (commit `f8ac384`).
- Produces: `docs/baseline.md` with headings `# Baseline`, `## Fingerprints`, `## Adopting phptramp on an existing codebase`, `## Stale entries`, `## Interaction with --changed-only` — Task 5's README links to `docs/baseline.md` (no anchors).

- [ ] **Step 1: Write `docs/baseline.md`**

Structure, with content **moved from the current README**:

```markdown
# Baseline

<README lines 269–270 first sentence: what --baseline is for.>

## Fingerprints

<README lines 270–276 verbatim: refactor-stable sha1 over semantic identity
only, the field list, the terminal-token rule.>

## Adopting phptramp on an existing codebase

<README lines 277–289 verbatim: the numbered Snapshot / Commit / Gate /
Shrink story.>

## Stale entries

<README lines 291–293 verbatim: --fail-on-stale strict hygiene, stderr-only
without it.>

## Interaction with `--changed-only`

<README lines 297–303 verbatim: stale detection skipped under
--changed-only, the PR + nightly composition recipe — but change the ci.md
link target from `docs/ci.md` to `ci.md`, since this file lives inside docs/.>
```

- [ ] **Step 2: Verify the intra-docs link**

Run: `grep -n 'ci.md' docs/baseline.md`
Expected: link target is `ci.md` (not `docs/ci.md`).

- [ ] **Step 3: Commit**

```bash
git add docs/baseline.md
git commit -m "docs(#21): move baseline reference to docs/"
```

---

### Task 5: README restructure

**Files:**
- Modify: `README.md` (full rewrite)
- Reference (read-only): `docs/configuration.md`, `docs/baseline.md` (created in Tasks 3–4)

**Interfaces:**
- Consumes: `docs/configuration.md` and `docs/baseline.md` existing; `.github/CONTRIBUTING.md` and `CODE_OF_CONDUCT.md` existing (Task 2).
- Produces: the final landing-page README.

- [ ] **Step 1: Rewrite `README.md`**

Target structure (~150 lines). "Keep" means the block from the current
README (commit `f8ac384`) verbatim unless noted:

```markdown
# phptramp

<keep the blockquote pitch, lines 3–5.>

[![CI](https://github.com/larspohlmann/phptramp/actions/workflows/ci.yml/badge.svg)](https://github.com/larspohlmann/phptramp/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/larspohlmann/phptramp)](https://packagist.org/packages/larspohlmann/phptramp)
[![PHP ≥ 8.2](https://img.shields.io/badge/php-%E2%89%A5%208.2-777bb3)](composer.json)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

<DROP the whole "Status: v0.1.0" paragraph (lines 7–16).>

## What it does

<keep the hero example block, lines 20–28.>
<keep the origin/hop-counting paragraph (30–32) and the hop-definition
paragraph (34–38) verbatim.>
<compress "Construction and delegation aren't tramp data" (69–89) to one
short paragraph — keep the first sentence's claim and the `(parent)` marker
mention, drop the fixture example and the exclude-terminal cross-reference:>

> Construction and delegation don't count: a constructor forwarding to
> `parent::__construct()` (or any `parent::` call) is the same object
> handling its own value, so that node scores neither a hop nor a class and
> is marked `(parent)` in the chain.

## Diff-aware CI mode

<keep lines 40–56: the flagship-feature paragraph, the *YOURS* example.>
<keep the →N explanation paragraph (58–62).>
<keep the --git-base/--diff paragraph (64–67) including the docs/ci.md link.>

## Installation

<keep lines 93–95 (composer require).>

### `composer tramp`

<keep lines 99–111 (the scripts snippet and both invocations).>
<DROP the self-hosting digression paragraph (114–120); replace with one
sentence:> This repository gates itself with exactly this script — see its
own [`phptramp.dist.json`](phptramp.dist.json).

## CLI

<keep the options block (124–144) and the exit-codes paragraph (146–147)
verbatim.>

## Configuration

phptramp reads `phptramp.json` (falling back to `phptramp.dist.json`) from
the current working directory; CLI flags always win. All keys, the index
cache, and performance numbers are documented in
[docs/configuration.md](docs/configuration.md).

```json
{
    "paths": ["src"],
    "limit": 3,
    "warnLimit": 2
}
```

## Output formats

<keep the first paragraph (215–217) minus its second sentence (the
unit-test/fixture-harness provenance), the format table (226–234), and the
--changed-only json note (236–238). Compress the color-mode prose (219–224)
to:> `pretty` is the default on a TTY; pipes and CI fall back to `text`
automatically. `--color=always|auto|never` overrides (`NO_COLOR` is honored
in `auto` mode).

## Suppression

<keep lines 240–252 verbatim.>

## `--warn-limit`

<keep lines 254–265 verbatim.>

## Baseline

Adopting phptramp on a codebase with existing tramp data? `--baseline`
snapshots today's findings so CI only gates *new* chains, with
refactor-stable fingerprints that survive renames and moved lines — and
`--fail-on-stale` nudges you to prune entries as you fix them. The full
workflow is in [docs/baseline.md](docs/baseline.md).

## IDE integration

<keep lines 307–310 verbatim.>

## Non-goals

<keep lines 312–315, and append a second bullet:>
- **No second production dependency.** `nikic/php-parser` is the only one, deliberately.

## Contributing

Contributions are welcome — see the
[contributing guide](.github/CONTRIBUTING.md) for the workflow and the CI
gates, and the [code of conduct](CODE_OF_CONDUCT.md). Bugs are best reported
with the [bug report form](https://github.com/larspohlmann/phptramp/issues/new?template=bug_report.yml).

## License

[MIT](LICENSE)
```

- [ ] **Step 2: Verify all relative links in the new README resolve**

Run: `grep -oE '\]\(([^)#h][^)]*)\)' README.md | tr -d ']()' | sort -u | xargs ls`
Expected: every listed path exists (`docs/ci.md`, `docs/configuration.md`, `docs/baseline.md`, `docs/phpstorm.md`, `.github/CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `LICENSE`, `composer.json`, `phptramp.dist.json`).

- [ ] **Step 3: Verify length target**

Run: `wc -l README.md`
Expected: roughly 130–180 lines.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs(#21): restructure README into a landing page"
```

---

### Task 6: Link sweep and final verification

**Files:**
- Possibly modify: `docs/ci.md`, `docs/phpstorm.md` (only if they link to removed README anchors)

**Interfaces:**
- Consumes: all files from Tasks 1–5.
- Produces: a fully link-consistent tree, `composer check` green.

- [ ] **Step 1: Sweep docs for links into README or to moved anchors**

Run: `grep -rn 'README\|#configuration\|#index-cache\|#baseline\|#performance' docs/*.md`
For any hit that targets a README section removed in Task 5, retarget it to
`configuration.md` / `baseline.md` as appropriate. If there are no hits, no
edit — record that in the task report.

- [ ] **Step 2: Confirm no source files changed on this branch**

Run: `git diff --name-only develop...HEAD | grep -E '^(src|bin|tests)/' || echo CLEAN`
Expected: `CLEAN`

- [ ] **Step 3: Run the pre-commit gate**

Run: `composer check`
Expected: all four tools green (nothing under src/ changed, so this guards
against accidents only).

- [ ] **Step 4: Commit (only if Step 1 changed files)**

```bash
git add docs/ci.md docs/phpstorm.md
git commit -m "docs(#21): retarget doc links after README restructure"
```
