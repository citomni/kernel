# App cache suite

Isolated checks for `\CitOmni\Kernel\App::clearCache()` together with `warmCache()`: which files are removed, what is returned, what the next boot reads, and failing fast when a file cannot be removed. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/app-cache/run.php
```

Expected result:

```
9 passed, 0 failed
```

As root, or as any user who can remove a read-only file from a read-only directory, the fail-fast check cannot be set up and is skipped:

```
8 passed, 0 failed, 1 skipped
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real `App` runs from `src/`, loaded by the autoloader in `tests/bootstrap.php`, against an app in the suite's run directory, `citomni_kernel_app-cache_test_<random>` under `sys_get_temp_dir()`. `CITOMNI_APP_PATH` points at that app, and `config/citomni_cfg.php` holds one `marker` value. The run directory is removed at shutdown.

`\CitOmni\Http\Boot\Registry` and `\CitOmni\Cli\Boot\Registry` are aliases of `tests/Fixtures/RegistryFixture.php`, because the kernel depends on neither package.

Every check starts with `resetApp()`: it removes `var/cache` and writes the marker `v1`. A fresh App after a step shows what the next boot reads: `v1` from a warm cache, `v2` from a source rewritten after the warm.

The fail-fast check makes the CLI cache files read-only and `var/cache` non-writable: POSIX refuses `unlink()` in a directory without write permission, and Windows refuses to delete a read-only file. A probe file in the same directory decides first whether this user can be refused at all.

## Checks

None of the checks is a regression check: `clearCache()` was added together with them in `8af8bc2`, and the `warmCache()` checks pin existing behavior.

| Check | Covers |
|---|---|
| clearCache() without cache files returns nulls and does not create var/cache | All-`null` result; no directory created |
| warmCache() returns the three paths of its own mode and writes those files | Result shape and files for CLI and HTTP |
| the next boot reads a warm cache and ignores a changed source | A warm cache wins over its sources |
| clearCache() removes only its own mode's files, returns them in the warmCache() shape, and keeps var/cache | Result shape; the other mode's files and `var/cache` stay |
| after clearCache(), the next boot of that mode rebuilds from sources and the other mode still reads its cache | Effect on the next boot of each mode; `routes.http.php` reported as `dispatch` |
| a second clearCache() returns nulls | All-`null` result with an empty `var/cache` |
| clearCache() reports only the files that were present as removed | `null` for a file that was already gone |
| clearCache(opcacheInvalidate: false) removes the files | The flag does not change what is removed |
| clearCache() fails fast when a cache file exists but cannot be removed | `RuntimeException` naming the file; the file stays. Skipped where the probe file can be removed. |

`php tests/run.php` runs every suite in the package.
