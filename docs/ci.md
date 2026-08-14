# CI cookbook

phptramp's flagship mode is diff-aware: on a pull/merge request it reports only
the chains your change touches, and marks exactly which hops are yours. Wire
it into a PR pipeline with `--changed-only --git-base <ref>`; pipe an
arbitrary diff with `--diff -`; or run it as a full-repo gate the way this
project gates itself. All four patterns below are copy-paste starting points.
For the IDE side (per-save runs in PhpStorm and friends) see
[docs/phpstorm.md](phpstorm.md).

## GitHub Actions — PR job

`--changed-only --git-base` shells out to `git diff --unified=0 <base>...HEAD`
(three-dot, merge-base semantics), so the checkout needs history and the base
ref present locally. `--format github` emits `::error`/`::warning`/`::notice`
annotations that GitHub renders inline on the PR diff.

```yaml
name: phptramp

on:
  pull_request: ~

jobs:
  phptramp:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer update --no-interaction --no-progress
      - name: Fetch the base branch
        run: git fetch --no-tags origin "${GITHUB_BASE_REF}"
      - name: phptramp (changed lines only)
        run: >-
          vendor/bin/phptramp --folder src
          --changed-only --git-base "origin/${GITHUB_BASE_REF}"
          --format github
```

## GitLab CI — merge request job

Same invocation; GitLab has no built-in consumer for `--format github`
annotations, so `text` (readable in the job log) or `json` (for a downstream
step) are the usual picks. `rules` scopes the job to merge request pipelines,
where `CI_MERGE_REQUEST_TARGET_BRANCH_NAME` is available. GitLab's default
clone is shallow (~20 commits), which leaves the merge-base with the target
branch unreachable and breaks `--git-base`'s three-dot diff — `GIT_DEPTH: 0`
forces a full-history clone, the same job GitHub Actions' `fetch-depth: 0`
does above.

```yaml
phptramp:
  stage: test
  image: php:8.4-cli
  variables:
    GIT_DEPTH: 0
  rules:
    - if: '$CI_PIPELINE_SOURCE == "merge_request_event"'
  before_script:
    - git fetch --no-tags origin "$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
    - composer update --no-interaction --no-progress
  script:
    - >-
      vendor/bin/phptramp --folder src
      --changed-only --git-base "origin/$CI_MERGE_REQUEST_TARGET_BRANCH_NAME"
      --format text
```

## Piping an arbitrary diff

Any unified diff works, not just `git diff <base>...HEAD` — a range, a
`.patch` file, output from another review tool. Pipe it to stdin with
`--diff -`:

```bash
git diff main...HEAD | vendor/bin/phptramp --folder src --changed-only --diff - --format json
```

`--diff <path>` reads from a file instead of stdin; either form implies
`--changed-only`, so it can be dropped when `--diff` is present.

## Full-scan gate: the pattern this repo uses

Not every project wants diff-only gating — a full-repo scan with a config-driven
threshold is simpler and catches tramp data introduced outside the diff window
(e.g. a rename that lengthens an existing chain without touching the forwarding
line itself). This repository gates itself that way: `composer tramp` runs
`bin/phptramp` against `phptramp.dist.json` (`paths: src`, `limit: 3`,
`warn-limit: 2`), and CI fails the build on any finding at or over the limit —
see the `static` job's `Dogfood` step in
[`.github/workflows/ci.yml`](../.github/workflows/ci.yml). A consuming project
gets the identical invocation from the README's `composer tramp` recipe; the
two approaches (diff-aware PR annotations, full-scan gate) are not mutually
exclusive and commonly run side by side.

## Baseline for legacy adoption

Turning phptramp on for an existing codebase full of pre-existing tramp data means
the full-scan gate above fails immediately. Baselining fixes that: snapshot the
current findings once, commit the file, and from then on CI only fails on *new*
chains. The fingerprint is refactor-stable (origin + param + terminal token — never
line numbers or intermediate hops), so a baseline survives renames and chain
shortening without churning. See the [Baseline](./baseline.md)
section for the adoption story; the recipes below are the CI half.

### Generate the baseline once

Run locally, commit the file:

```bash
vendor/bin/phptramp --folder src --generate-baseline phptramp-baseline.json
git add phptramp-baseline.json
git commit -m "chore: snapshot phptramp baseline"
```

### Full-scan gate with baseline (the legacy-adoption job)

A full scan that no longer fails on pre-existing findings — the baseline hides
them, only *new* chains above `--limit` exit `1`. The recipe below enables
`--fail-on-stale`, so a stale baseline entry (one that matches nothing) exits
`1` — fixed chains must be pruned from the file or CI stays red. Remove the
flag to keep stale entries as stderr-only warnings with no exit-code impact.

```yaml
name: phptramp (full scan)

on:
  push:
    branches: [main]
  pull_request: ~

jobs:
  phptramp-full:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer update --no-interaction --no-progress
      - name: phptramp (baseline-gated full scan)
        run: >-
          vendor/bin/phptramp --folder src
          --baseline phptramp-baseline.json
          --fail-on-stale
          --format github
```

### Diff-aware PR job composing `--changed-only --baseline`

The strict repo's other half: on a PR, gate on *new* chains touching the diff
(`--changed-only`) while the baseline keeps the pre-existing ones quiet. Stale
detection is skipped under `--changed-only` (the diff filter removes most chains
before matching, so "stale" would be almost everything — full runs only), so
`--fail-on-stale` is intentionally not passed here — it would be a no-op.

```yaml
name: phptramp (PR)

on:
  pull_request: ~

jobs:
  phptramp-pr:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer update --no-interaction --no-progress
      - name: Fetch the base branch
        run: git fetch --no-tags origin "${GITHUB_BASE_REF}"
      - name: phptramp (changed lines only, baseline-gated)
        run: >-
          vendor/bin/phptramp --folder src
          --changed-only --git-base "origin/${GITHUB_BASE_REF}"
          --baseline phptramp-baseline.json
          --format github
```

Run the full-scan job on `push` (or a nightly schedule) to surface stale baseline
entries and prune them; run the diff-aware job on PRs to gate edits. The two are
not mutually exclusive — commonly they run side by side.
