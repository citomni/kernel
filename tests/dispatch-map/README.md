# Dispatch map suite

Isolated checks for removing entries from the route and command maps: a source sets an entry of an earlier source to `null`, and `\CitOmni\Kernel\App` drops it after the last merge. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/dispatch-map/run.php
```

Expected result:

```
7 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real `App` runs from `src/`, loaded by the autoloader in `tests/bootstrap.php`, against an app in the suite's run directory, `citomni_kernel_dispatch-map_test_<random>` under `sys_get_temp_dir()`. `CITOMNI_APP_PATH` points at that app. The run directory is removed at shutdown.

`\CitOmni\Http\Boot\Registry` and `\CitOmni\Cli\Boot\Registry` are aliases of `tests/Fixtures/RegistryFixture.php`, the vendor layer. `tests/Fixtures/ProviderFixture.php` is the provider layer when a case lists it in `config/providers.php`. `CITOMNI_ENVIRONMENT` is not defined, so the env overlay files are the `.prod.php` files.

Every case starts with `writeConfig()`: it removes `var/cache` and every config file, then writes the files of the case. A fresh App after that builds its maps from the sources.

## Checks

R marks a regression check: it fails against `App` as it was in v1.0.2.7, which kept `null` entries in the merged maps. The other check pins existing behavior.

| | Check | Covers |
|---|---|---|
| R | a null entry in the app's command file removes a provider command | Provider layer removed by the app base file; other entries stay |
| R | a null entry in the env overlay removes a command of the app base file and of the vendor baseline | Env overlay removes entries of the app base file and the vendor layer |
| R | a null entry that no earlier source defines leaves no key | No `null` key for an entry nothing defined |
| | a null field inside an entry stays as merged | Only whole entries are dropped |
| R | a null route removes an exact route, and a null entry under regex removes one placeholder route | Top-level routes and placeholder routes under `'regex'` |
| R | 'regex' => null removes every placeholder route | `'regex'` itself is an entry |
| R | warmCache() writes both maps without the removed entries | The cached maps match the built ones |

`php tests/run.php` runs every suite in the package.
