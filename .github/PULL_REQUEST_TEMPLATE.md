Closes #

## What & why

<!-- What does this change, and why? Link the issue for full context. -->

## Checklist

- [ ] `composer check` is green (cs + stan + md + test)
- [ ] `composer infection:diff` is green (`git fetch origin main` first — CI gates the diff at MSI ≥ 80)
- [ ] Semantic changes come with a fixture under `tests/fixtures/`
- [ ] Title is a conventional commit with the issue number, e.g. `feat(#123): title`
- [ ] Targets `develop` (never `main`)
