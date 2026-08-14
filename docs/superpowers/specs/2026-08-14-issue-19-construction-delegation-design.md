# Don't flag idiomatic construction/delegation as tramp data

Design for [issue #19](https://github.com/larspohlmann/phptramp/issues/19).
Status: approved 2026-08-14.

## Problem

At tight thresholds phptramp's findings are dominated by two shapes that are
object construction and delegation, not tramp data:

1. **Parent-constructor delegation.** A subclass constructor forwarding to
   `parent::__construct()` scores a hop and a second distinct class, even
   though subclass and base are the *same object*. Every custom exception in a
   codebase produces one finding, and deep hierarchies accumulate hops.
2. **Terminals that store the value.** A chain ending in a constructor, named
   constructor or value object has delivered the parameter to its home.

The goal: a value *arriving at an object* is design; a value *passing through
objects that ignore it* is the smell.

## What this design does not do

The issue suggested `stored` terminals might not be findings by default. That
is rejected. The tool's own README headline finding — `$config` forwarded
through a controller and two services into `new Mailer($config)` — is a
`stored` terminal, as are 6 of the 16 findings across `tests/fixtures/`. The
issue's noisy example (`begin` → `persistedLog` → `new RunLog`) is 2 hops
across 2 classes; the README's legitimate one is 3 hops across 4 classes. Both
are `stored`, so the terminal kind is not what separates them — hop count is.
Excluding `stored` by default would delete the tool's canonical finding.

Instead, exclusion by terminal kind becomes an explicit, general, opt-in knob.

## 1. Parent delegation stops scoring

`Hop` gains a `readonly bool $viaParent`: true when that node forwards the
parameter on through a `parent::` call. It is detected syntactically in
`ChainTraversal::walk()` — `$forward->callee->kind === CalleeKind::StaticCall`
and `$forward->callee->receiverHint === 'parent'` — so `CallResolver` needs no
change. All `parent::` calls count, not only `parent::__construct()`: the
same-object argument holds for any of them.

Graph traversal, origin selection, cycle detection and the baseline
fingerprint are all unchanged. Only scoring changes:

- `Finding::$hops` counts chain nodes that are **not** `viaParent` (the
  terminal node is never counted, as today).
- `Finding::$classes` counts distinct declaring classes, **skipping**
  `viaParent` nodes — a delegating subclass is represented by the base it
  delegates into, which is the same object.

This amends frozen rule 4 in `docs/plan.md`, which is the point of the issue
("this is about *what counts as a hop*, not the thresholds").

### Worked cases

| Chain | `hops` | `classes` | Reported? |
|---|---|---|---|
| `Sub::__construct` → `parent::` → `Base::__construct` (stores) | 0 | 1 | never — 0 is below every positive limit |
| `A` → `parent::` → `B` → `parent::` → `C` (stores) | 0 | 1 | never |
| `Sub::__construct` → `parent::` → `Base` → collaborator `D` (stores) | 1 | 2 | only at `--limit 1` |
| collaborator `X` → `Sub::__construct` → `parent::` → `Base` (stores) | 1 | 2 | only at `--limit 1` |

A 0-hop finding is unreachable: `Thresholds::severityOf()` reports at
`hops >= limit` only when `limit > 0`, and `warnLimit` of `0` normalizes to
off. So pure delegation chains are still *built* — they just never surface.

## 2. Cut the `hops`-as-index coupling (prerequisite refactor)

`Finding::$hops` is currently overloaded: it is both the score and the position
of the terminal node in `chain`. Five reporters depend on that identity:

- `TextReporter`, `PrettyReporter`, `JsonReporter`: `count($chain) > $hops`
  and `$index === $hops`
- `GithubReporter`: `array_slice($chain, 0, $hops, true)`
- `SarifReporter`: `for ($index = 1; $index < $hops; $index++)`

Once `hops` discounts parent nodes the identity breaks, so the structural
question ("is there a terminal node, and where") must be answered
structurally:

- `TerminalKind::keepsTerminalNode(): bool` — true for `used`, `stored`,
  `&-terminated`, `unused-end`; false for `external`, `truncated`. This is
  already stated prose in the enum's docblock; this makes it executable.
- `Finding::hasTerminalNode(): bool` — delegates to the terminal kind.
- `Finding::forwardingHops(): list<Hop>` — the chain minus the terminal node.

The terminal node, when present, is always the last chain entry. Reporters use
these three and stop doing arithmetic on `hops`, which becomes purely a score.

## 3. `--exclude-terminal`

A new `PhpTramp\Chain\TerminalKindFilter`, alongside the existing
`BaselineFilter` / `SuppressionFilter` / `ChangedChainFilter`:

```php
final class TerminalKindFilter
{
    /** @param list<TerminalKind> $excluded */
    public function __construct(private readonly array $excluded) {}

    /**
     * @param list<Finding> $findings
     * @return list<Finding>
     */
    public function filter(array $findings): array;
}
```

Surface:

- CLI `--exclude-terminal <kind>`, repeatable, mirroring `--exclude` for paths.
- Config `excludeTerminals: ["stored"]`.
- Default `[]` — nothing excluded. README, fixtures and current output are
  untouched; adding a future `TerminalKind` cannot silently change behaviour,
  which an allowlist default would not guarantee.

Values are validated against `TerminalKind`'s backing values at both entry
points, each throwing its own exception type with its own wording
(`InvalidArgsException` from `ArgvParser`, `ConfigException` from
`ConfigLoader`) — the same split the existing options already use. Any kind is
accepted, including `used`; excluding it is pointless but harmless, and a
whitelist of "sensible" kinds would be a second thing to maintain.

`Options` carries `list<string> $excludeTerminals`, consistent with the
existing `list<string> $exclude`; `Application` maps it to `TerminalKind`
cases when constructing the filter.

### Where it applies

In `Application::analyze()`, **after** `SuppressionFilter`. Order matters for
staleness, not for output:

- After suppression, an excluded chain's `#[TrampIgnore]` still fires, so
  suppressions do not become spuriously stale.
- A *baselined* chain that becomes excluded does go stale. That is correct —
  it is genuinely no longer reported — and only surfaces under
  `--fail-on-stale`. Documented, not worked around.

Excluded findings never reach `--generate-baseline`, the exit code, or any
reporter.

## 4. Rendering

Chain labels stay positional — `origin`, `hop 2`, `hop 3`, `terminal` —
because they describe the rendered chain, not the score. Renumbering them
around discounted nodes would make locations harder to follow for no gain.

A `viaParent` node is marked instead:

- `text` / `pretty`: annotation `(parent)`; combined as `(parent) *YOURS*`
  when the node is also a diff hop. The annotation slot becomes a list joined
  by a space rather than a single string.
- `json`: `"viaParent": true` on that chain entry, emitted **only when true**
  — precedent is `"changed"`, which `JsonReporter` emits only in
  `--changed-only` mode.
- `github` / `checkstyle` / `sarif` / `summary`: unchanged. They render a
  one-line message plus locations; the hop count and class count already
  reflect the discount.

Nothing is hidden: the delegating node stays in the chain, it just stops
accumulating score.

## 5. Testing

Fixtures (`tests/fixtures/<case>/`, input codebase + expected output):

- `parent-delegation-hierarchy` — a three-deep exception hierarchy forwarding
  `$previous`. Expects `[]` at a tight limit, the shape that motivated the
  issue.
- `parent-delegation-mixed` — a subclass delegating to a parent that then
  forwards to a collaborator. Expects the collaborator hop counted and the
  parent hop discounted (`1 hop across 2 classes`), proving the discount is
  not a blanket suppression.
- `exclude-terminal-stored` — a `stored`-terminated chain that fires by
  default, run with `--exclude-terminal stored`, expecting `[]`.

Unit tests:

- `TerminalKindTest` — `keepsTerminalNode()` for all six cases.
- `ArgvParserTest` — repeatable `--exclude-terminal`, unknown kind rejected.
- `ConfigLoaderTest` — `excludeTerminals` list, wrong type rejected, unknown
  kind rejected.
- `ChainBuilderTest` — `viaParent` marking, hop and class discounting.
- Reporter tests — `(parent)` annotation in text/pretty, `viaParent` in JSON.

Existing fixtures must stay green: none of them use `parent::`, and the
default `excludeTerminals` is empty, so this change is behaviour-preserving
for every current test.

## 6. Documentation

- `docs/plan.md`: amend frozen rule 4 (hop and class counting skip `parent::`
  delegation), and record the rejected "stored is not a finding by default"
  option with its rationale in the Appendix.
- `README.md`: `--exclude-terminal` in the options list; a short section on
  construction/delegation explaining why parent delegation does not score.
- `Application::helpText()`: `--exclude-terminal <kind>` under Reporting.

## Commit sequence

Fixture-first TDD, one task per commit:

1. `refactor(#19): derive terminal node from TerminalKind, not hop count`
2. `feat(#19): parent:: delegation counts as neither hop nor class`
3. `feat(#19): mark parent-delegating hops in text, pretty and json output`
4. `feat(#19): --exclude-terminal / excludeTerminals filter`
5. `docs(#19): frozen semantics, README and help text`
