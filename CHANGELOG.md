# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed
(see `.github/workflows/release.yml`). History before the first release lives in
the git log and the merged pull requests.

## [Unreleased]

### Added
- `--format pretty`: colored, file-grouped, line-sorted terminal output. New
  default when STDOUT is a TTY.
- `--color=always|auto|never` flag (default `auto`). `NO_COLOR` environment
  variable honored in `auto` mode; `always`/`never` are absolute.
- `colorMode` config key in `phptramp.json` / `phptramp.dist.json`.

### Changed
- Default `--format` is now `pretty` (TTY-gated). In `--color=auto` (the
  default), non-TTY invocations (pipes, CI redirection) automatically use
  `text`. `--color=never` keeps plain `pretty` in a pipe; `--color=always`
  keeps colored `pretty` (escape hatch for `less -R`). Pass `--format text`
  explicitly to force the old default on a TTY.

### Migration
- If you snapshot `--format text` output against a baseline, no change is
  needed — pipes get `text` automatically (the default `--color=auto`
  downgrades non-TTY `pretty` to `text`).
- If you run `phptramp` interactively and pipe the default output, you now
  get `text` (no ANSI) in the pipe. To force colored `pretty` into a pipe,
  pass `--color=always`. To force plain `pretty` (file-grouped layout
  without ANSI), pass `--color=never`.
