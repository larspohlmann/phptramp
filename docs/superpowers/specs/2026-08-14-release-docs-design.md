# Release docs: README restructure and community health files

**Issue:** [#21](https://github.com/larspohlmann/phptramp/issues/21)
**Date:** 2026-08-14
**Status:** Approved

## Goal

Prepare the GitHub side of the project for the first release (v0.1.0). The
README currently reads as a 320-line accumulated feature log; the standard
community health files of a well-structured open-source project are missing
entirely. After this work, a first-time visitor understands the tool in one
screen, a contributor knows exactly how to contribute, and GitHub's community
profile checklist is satisfied.

## Decisions (settled with the maintainer)

- **README:** full restructure into a landing page; deep reference material
  moves to linked pages under `docs/`.
- **Code of Conduct:** Contributor Covenant 2.1 with
  `lars.pohlmann@googlemail.com` as the public enforcement contact.
- **Security policy:** GitHub private vulnerability reporting (no email).
  The maintainer must enable it once in *Settings → Code security*.
- **Out of scope (YAGNI):** FUNDING.yml, SUPPORT.md, GitHub Discussions,
  a docs website.

## 1. README restructure

Target shape (~150 lines), in order:

1. **Title + one-line pitch** — what tramp data is, in one sentence.
2. **Badge row** — CI workflow status, Packagist version, PHP ≥ 8.2, MIT
   license. Packagist badges will render once the package is published;
   acceptable before that.
3. **Hero example** — the existing `FINDING $config` output block, unchanged.
4. **What it does** — the hop/tramp-data explainer, tightened; the
   construction-and-delegation subsection compressed to a short paragraph
   linking `docs/plan.md` semantics only if needed (no fixture dumps).
5. **Installation + quickstart** — `composer require --dev`, the
   `composer tramp` script recipe (tightened: drop the self-hosting
   digression paragraph).
6. **CLI** — the compact options block, kept verbatim (it is the tool's face).
7. **Feature overviews, one short section each, linking out for depth:**
   - Diff-aware CI mode → `docs/ci.md`
   - Configuration (`phptramp.json`, 3-line example) → `docs/configuration.md`
   - Baseline (2–3 sentences) → `docs/baseline.md`
   - Suppression (`#[TrampIgnore]` / `// phptramp-ignore`) — short, stays fully
     in README
   - Warn tier (`--warn-limit`) — short, stays fully in README
   - Output formats — the format table stays, the surrounding color-mode prose
     tightens
   - IDE integration → `docs/phpstorm.md`
8. **Non-goals** — kept.
9. **Contributing + License footer** — links to `.github/CONTRIBUTING.md`,
   `CODE_OF_CONDUCT.md`, `LICENSE`.

Dropped from README (moved, not lost): the "Status: v0.1.0" paragraph
(badges + CHANGELOG cover it), the full configuration key table, the index
cache section, the performance table, the 4-step baseline adoption story, the
`--changed-only`-interaction subsection.

## 2. New docs pages

Content moves verbatim-or-tightened from the README; no semantics are
rewritten.

- **`docs/configuration.md`** — config file discovery (`phptramp.json` →
  `phptramp.dist.json`), CLI-over-config precedence, unknown-key errors, the
  full key table (including the `excludeTerminals` union-vs-replace note),
  the index cache (validation model, `--no-cache`, `cache` key, gitignore
  advice), and the performance measurements.
- **`docs/baseline.md`** — refactor-stable fingerprint semantics, the 4-step
  adoption story, `--fail-on-stale`, the `--changed-only` interaction, link
  to the legacy-adoption recipe in `docs/ci.md`.

Existing `docs/ci.md` and `docs/phpstorm.md` stay; all cross-links between
README and docs pages are verified after the move.

## 3. Community health files

- **`.github/CONTRIBUTING.md`** — dev setup (clone, `composer update`,
  `git flow init -d` with tag prefix `v`), the `composer check` gate, the
  diff-scoped Infection PR gate (`composer infection:diff`, minMsi 80),
  fixture-first TDD expectations, conventional commits with issue number
  (`feat(#123): title`), branch naming (`feature/NN-slug`), PRs target
  `develop`, `Closes #NN` in PR bodies. Written for human contributors —
  distills CLAUDE.md without referencing it.
- **`CODE_OF_CONDUCT.md`** — Contributor Covenant 2.1, verbatim standard
  text, enforcement contact `lars.pohlmann@googlemail.com`. Repo root (GitHub
  detects it there; keeps `.github/` lean).
- **`.github/SECURITY.md`** — supported versions (latest release), report via
  GitHub private vulnerability reporting (Security tab → Report a
  vulnerability), expected response window, no email channel.
- **`.github/ISSUE_TEMPLATE/bug_report.yml`** — form fields: phptramp
  version, PHP version, minimal reproducing PHP snippet, exact command,
  expected output, actual output.
- **`.github/ISSUE_TEMPLATE/feature_request.yml`** — problem statement,
  proposed behavior, note that core semantics are frozen in `docs/plan.md`
  and defaults are conservative.
- **`.github/ISSUE_TEMPLATE/config.yml`** — `blank_issues_enabled: true`
  (no Discussions to link; questions still need a door).
- **`.github/PULL_REQUEST_TEMPLATE.md`** — `Closes #NN` prompt, checklist:
  `composer check` green, `composer infection:diff` green, fixture added for
  semantic changes, conventional commit title with issue number, PR targets
  `develop`.

## 4. Delivery

Branch `feature/21-release-docs` off `develop`; one conventional commit per
logical unit (`docs(#21): …`); PR into `develop` with `Closes #21`. Markdown
files are not covered by `composer check`'s linters, but the full check runs
before the PR anyway (guards against accidental source edits). Link
integrity is verified manually (every relative link resolves; anchors match
headings).

## Error handling / risks

- **Packagist badges 404 until first publish** — accepted; they self-heal.
- **Private vulnerability reporting must be enabled by the maintainer** —
  SECURITY.md says so; the PR description carries a reminder.
- **Moved README anchors** — `docs/ci.md` / `docs/phpstorm.md` may link to
  README sections that move; sweep all `docs/*.md` for links into README and
  fix them.

## Testing

No PHP code changes; `composer check` must stay green. Acceptance is
manual: README renders correctly on GitHub, every relative link resolves,
issue forms validate (GitHub rejects malformed YAML forms at issue-creation
time), community profile checklist (Insights → Community standards) shows
license, README, code of conduct, contributing, security policy, issue
templates, PR template all present after merge.
