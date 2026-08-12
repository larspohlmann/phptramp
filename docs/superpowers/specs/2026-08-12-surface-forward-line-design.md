# Surface the forwarding call-site line per hop (issue #17)

**Status:** design approved 2026-08-12 — pending spec review.
**Branch:** `feature/17-surface-forward-line` (closes #17).
**Chosen option:** Option B from issue #17 — display the forwarding line so
duplicates are distinguishable; do not dedupe.

## Problem

When a single method forwards a parameter to the **same callee via two (or more)
distinct call sites**, `ChainTraversal` correctly emits one Finding per
root-to-terminal path (plan.md §2.3 "every outgoing edge is explored"). The two
findings share `origin`, `param`, `terminal`, and an identical hop chain; only
the divergent hop's `forwardLine` differs (e.g. `article()` line 166 vs line 180
in the reproduction). The text/pretty/github/sarif reporters render only
`$hop->line` (the method declaration line) and never `$hop->forwardLine`, so the
two findings print as byte-identical blocks. `Fingerprint::of()` is keyed on
`(origin, param, terminal)` and cannot tell them apart either — but the
fingerprint is deliberately refactor-stable (plan.md: "excludes lines and
intermediate hops"), so that is by design.

JSON already emits `forwardLine` per hop, so the bug is purely a rendering gap
in the four other reporters.

## Fix

Surface `Hop::forwardLine` in every reporter that currently hides it. No
finding-emission change, no fingerprint change, no semantics change. The two
findings stay emitted (Option B, not Option A dedup) and become distinguishable
in the rendered output.

### Display format (frozen)

For the text and pretty reporters, append the forwarding call-site line after
the declaration line, separated by `→`, only when `forwardLine !== null`
(terminal hops and truncated-chain terminals have `forwardLine === null`):

```
origin    Demo\Controller::handle($config)    src/Demo.php:12→14
hop 2     Demo\ServiceA::process($config)     src/Demo.php:18→20
hop 3     Demo\ServiceB::run($config)         src/Demo.php:24→26
terminal  Demo\Mailer::__construct($config)   src/Demo.php:32  (stored)
```

The terminal hop keeps the plain `file:line` form (no `→`), so the `→N` suffix
itself signals "this hop forwards out at line N". Column padding already uses
the visible width of the location string, so no new column or layout work is
needed.

### Per-reporter changes

- **TextReporter** (`src/Report/TextReporter.php`, `location()`): render
  `file:line→forwardLine` when `forwardLine !== null`; `file:line` otherwise.
- **PrettyReporter** (`src/Report/PrettyReporter.php`, `location()`): identical
  change. The pretty reporter's location is styled via `$this->styler->location(...)`;
  the `→N` suffix is part of the location string and inherits the same style.
- **GithubReporter** (`src/Report/GithubReporter.php`): for each hop's
  `::notice` and the anchor `::error`/`::warning`, set `line=` to
  `forwardLine ?? $hop->line`. This lands the GitHub annotation on the
  forwarding call — the actionable line. The diff-aware changed-anchor logic
  (keyed on `$hop->changed`, a per-hop boolean) is untouched; only the `line=`
  value shifts. The title text is unchanged.
- **SarifReporter** (`src/Report/SarifReporter.php`): for `relatedLocations`
  (hops 1 .. hops-1), set `region.startLine` to `forwardLine ?? $hop->line`.
  The origin `locations[0]` keeps the declaration line — the origin is the
  "where this chain starts" anchor and its declaration line is the natural
  entry point. (The origin's own `forwardLine` is the *next* hop's call site,
  not the origin's, so the declaration line is the only sensible anchor there.)
- **CheckstyleReporter**: origin-only summary, single `<error>` line. Leave
  as-is — the origin's declaration line is the only line Checkstyle shows.
- **JsonReporter**: already emits `forwardLine` per hop. No change.

### Out of scope (deliberate)

- **Fingerprint**: unchanged. Two findings sharing `(origin, param, terminal)`
  stay one baseline unit, matching plan.md's refactor-stability rule. No
  frozen-semantics change, no baseline invalidation. A partial fix (one of two
  call sites refactored away) leaves the remaining finding still baselined —
  accepted as the cost of refactor-stable fingerprints; the user can
  `--generate-baseline` to refresh.
- **Finding emission**: unchanged. Both root-to-terminal paths are still
  emitted (Option B over Option A).

### Docs

- **README.md**: update the canonical example block (lines 24-27 and the
  diff-aware example 50-53) to show `→forwardLine` on non-terminal hops. Add one
  sentence below the example explaining the `→N` suffix is the forwarding
  call-site line.
- **docs/plan.md §2.3 (Branching)**: amend the rule with a sentence covering
  the same-terminal case: "A param forwarded to the same callee via multiple
  call sites within one method yields one Finding per call site; they share
  origin, param, and terminal and differ only in the divergent hop's
  `forwardLine`, which every reporter surfaces. They share a fingerprint (the
  fingerprint excludes lines by design)." Flag in the commit message as a
  clarification, not a semantics change.

### Fixtures (strict fixture-first TDD)

New:
1. `tests/fixtures/same-callee-two-callsites/` — a method forwards `$p` to the
   same static callee twice (two call sites at lines L1 and L2), both reaching
   the same terminal. `expected-findings.json` asserts two findings with
   identical origin/param/terminal/hops/chain except the divergent hop's
   `forwardLine` (L1 vs L2). If the fixture drives an end-to-end text-render
   test, the expected text shows `file:line→L1` vs `file:line→L2`. Documents
   that emission is N (not deduped) and that the fingerprint is shared.

Updated (byte-for-byte assertions):
2. `tests/Report/TextReporterTest.php` — existing expected strings gain `→N`
   suffixes on non-terminal hops; terminal hops keep `file:line`.
3. `tests/Report/PrettyReporterTest.php` — same.
4. `tests/Report/GithubReporterTest.php` — expected `line=` values shift from
   declaration line to forwardLine for non-terminal hops (and for the anchor
   when it is a non-origin changed hop); origin-anchored findings keep
   declaration line.
5. `tests/Report/SarifReporterTest.php` — `relatedLocations` `startLine`
   shifts to forwardLine; origin `locations[0]` keeps declaration line.
6. `tests/fixtures/**/expected-findings.json` — unchanged: they are JSON-only
   and already encode `forwardLine` per hop. There are no rendered-text
   fixtures under `tests/fixtures/` (confirmed: no `*.txt` expected-output
   files exist); all text/pretty/github/sarif byte-for-byte assertions live in
   the per-reporter test files (items 2–5).

### Verification

- `composer check` green (PSR-12, PHPStan max, PHPMD, PHPUnit).
- `composer infection:diff` (against `origin/develop`) — no escaped mutants on
  the touched reporter code. The `forwardLine ?? $hop->line` selection and the
  `→N` formatting both need test coverage so an escaped mutant (e.g. dropping
  the `?? $hop->line` fallback, or the `→` separator) fails a test.
- Manual: re-run the issue #17 reproduction against
  `simple-feed-reader/backend/src` and confirm the two findings are now
  distinguishable by the divergent hop's `→166` vs `→180`.

## Scope

One issue, one branch (`feature/17-surface-forward-line`), one PR.
`Closes #17` in the PR body.
