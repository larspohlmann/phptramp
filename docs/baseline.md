# Baseline

`--baseline` keeps pre-existing tramp data from gating CI on a codebase you adopt
phptramp against mid-flight.

## Fingerprints

The fingerprint is refactor-stable: a sha1 over the
chain's semantic identity only (origin FQMN, parameter name, terminal token — never
line numbers or intermediate hops), so shortening a chain, moving a file, or shifting
a line does *not* "re-open" a baselined finding. The terminal token is the terminal
FQMN when the chain resolves one, else the terminal kind (`external`/`truncated`);
a chain whose truncation reason changes when resolution improves stays baselined.

## Adopting phptramp on an existing codebase

The adoption story:

1. **Snapshot.** Run `phptramp --folder src --generate-baseline phptramp-baseline.json`
   once on the current tree. The file is sorted, stable JSON — one entry per finding,
   diff-clean to commit and review.
2. **Commit.** Check `phptramp-baseline.json` into the repo alongside `phptramp.json`.
3. **Gate.** Run `phptramp --folder src --baseline phptramp-baseline.json` in CI.
   Every entry in the file is invisible to the reporters and the exit code —
   identical to not existing. New or re-shaped chains above `--limit` fail the build
   as usual.
4. **Shrink.** Fix a baselined chain and the matching entry becomes *stale*: phptramp
   prints `phptramp: stale baseline entry: <…>` on stderr and (with `--fail-on-stale`)
   exits `1`, nudging you to delete the line and let the gate cover that chain again.

## Stale entries

`--fail-on-stale` opts a repo into strict stale hygiene — exit `1` whenever a
baseline entry or a suppression matches nothing. Without it, stale lines are
stderr-only warnings and never change the exit code.

## Interaction with `--changed-only`

Stale detection is **skipped entirely under `--changed-only`** (full runs only).
The diff filter removes most chains before the baseline is matched, so "stale"
would be almost everything almost always — meaningless noise. Compose
`--changed-only --baseline` on PRs to gate on *new* chains touching the diff while
the baseline keeps the rest quiet; run a full scan (without `--changed-only`) on a
schedule or nightly to surface stale entries and prune the file. See
[ci.md](ci.md) for the legacy-adoption recipe.
