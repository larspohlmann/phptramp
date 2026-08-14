# Configuration

Everything the CLI flags control can also be set in a config file; flags win.

## The config file

phptramp reads `phptramp.json` (falling back to `phptramp.dist.json`) from the current
working directory. CLI flags always take precedence over the config file. An unknown
key, or a value of the wrong type, is a config error rather than a silently ignored typo.

```json
{
    "paths": ["src"],
    "exclude": ["src/Legacy/*.php"],
    "limit": 3,
    "warnLimit": 2,
    "minClasses": 0,
    "format": "json"
}
```

## Keys

| Key | Type | Same as |
|---|---|---|
| `paths` | list of strings | entries ending in `.php` become `--file`s, everything else a `--folder` |
| `exclude` | list of strings | `fnmatch` globs matched against each folder-discovered file's path, relative to the config file's directory; explicit files (`paths` entries ending in `.php`) are never excluded |
| `limit` | integer | `--limit` |
| `warnLimit` | integer | `--warn-limit` |
| `minClasses` | integer | `--min-classes` |
| `excludeTerminals` | list of strings | `--exclude-terminal`; **unions** with the flag rather than being replaced by it — `{"excludeTerminals":["stored"]}` plus `--exclude-terminal external` on the command line excludes both. That is the opposite of `paths`/`--folder`, where the first CLI path replaces config-seeded paths: the config file is project policy, and a one-off CI run adds to it rather than overriding it. |
| `format` | string | `--format` |
| `baseline` | string | `--baseline` |
| `cache` | string | directory for the per-file index cache (default: `.phptramp.cache/`, resolved relative to the config file's directory) |

## Index cache

phptramp caches each source file's parsed index under `.phptramp.cache/` (a
per-file cache, default-on). The cache is what makes warm re-runs — including
per-save IDE invocations — cheap: the whole-project index (the part call
resolution needs) rebuilds from cache instead of re-parsing every file.

- **Disable** with `--no-cache`, or **move the directory** with the `cache`
  config key (see [Configuration](#configuration)).
- **Identity-validated and version-keyed.** Each entry stores the source
  file's path, mtime, and size, plus the tool and payload-format versions. Any
  mismatch — an edited file, a bumped version, a corrupt or stale entry — is
  a silent cache miss followed by a fresh parse. A stale or corrupt cache
  **never changes findings**.
- **Safe to wipe.** Delete `.phptramp.cache/` any time; the next run simply
  re-parses and repopulates it. Add `/.phptramp.cache/` to your own
  `.gitignore` so the cache is not committed.

## Performance

Recorded 2026-08-11 on the maintainer's machine (Apple Silicon, PHP 8.4.23):

| Run | Wall-clock | Notes |
|---|---|---|
| `--folder vendor` cold | ≈ 19.6s | 4,610 files / 601,867 lines parsed |
| `--folder vendor` warm | ≈ 4.5s | cache hot — indexing ≈ 0; the residual is chain resolution + reporting |
| `--folder src` cold (36 files) | ≈ 0.24s | small project baseline |
| Extrapolation | ≈ 1.6s cold per 50k LOC | linear in parsed lines |

The cache eliminates the parsing portion of a run (≈ 15s saved on the
`--folder vendor` measurement); chain resolution and reporting are unchanged.
On a typical `src/` the warm per-save run is effectively instant after the
first cold run. See [phpstorm.md](phpstorm.md) for the IDE use this
enables.
