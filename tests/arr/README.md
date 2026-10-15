# Arr suite

Isolated checks for `\CitOmni\Kernel\Arr`: enum cases in `normalizeConfig()` and `mergeAssocLastWins()`, the conversion of other value types, and the round trip of enum cases through a cache file. No database, no Composer autoloader, and no setup per session.

## Run

```
php tests/arr/run.php
```

Expected result:

```
13 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real `Arr` runs from `src/`, loaded by the autoloader in `tests/bootstrap.php`. The fixtures in `tests/Fixtures/` are a backed enum, a pure enum, and an enum that implements `IteratorAggregate` and throws from `getIterator()`, so a check that iterates it fails with a `LogicException`.

The cache round trip writes one file below the suite's run directory, `citomni_kernel_arr_test_<random>` under `sys_get_temp_dir()`, which is removed at shutdown.

## Checks

R marks a regression check: it fails against `Arr` as it was before `582ec5e` (Preserve enum cases in Arr::normalizeConfig()). The other checks pin existing behavior.

| | Check | Guards |
|---|---|---|
| | TraversableEnumFixture is Traversable | Fixture sanity: the `IteratorAggregate` checks prove nothing without it. |
| R | normalizeConfig() keeps nested backed and pure enum cases as the same instance | `get_object_vars()` flattened a case into `['name' => ..., 'value' => ...]` or `['name' => ...]`. |
| R | normalizeConfig() keeps an IteratorAggregate enum case without iterating it | The case went through the `Traversable` branch and was iterated. |
| R | normalizeConfig() keeps enum cases inside a list, stdClass, and ArrayIterator | The same flattening inside containers. |
| R | normalizeConfig() keeps enum cases inside a root stdClass and a root ArrayIterator | The same flattening on the top-level object and `Traversable` paths. |
| R | normalizeConfig() rejects an enum root and names the case | An enum root silently came back as an array. |
| | normalizeConfig() turns stdClass, Traversable, and DTOs into arrays (deep) | Existing behavior: other objects keep their conversion. |
| | normalizeConfig() leaves scalars, lists, and empty arrays unchanged | Existing behavior |
| | normalizeConfig() rejects a scalar root | Existing behavior |
| | mergeAssocLastWins() replaces enum cases and arrays like scalars | Existing behavior: the merge needed no change. |
| R | layered normalizeConfig() and mergeAssocLastWins() keep enum cases | The `App::buildConfig()` pipeline flattened enum cases in every layer. |
| R | var_export() writes enum cases as class-constant references | Cache files held the flattened arrays, not the cases. |
| R | a cache file round-trips enum cases as the same instances | The same, read back with `require`. |

Against `src/` at the parent of `582ec5e`: `5 passed, 8 failed`. The eight R checks fail.

`php tests/run.php` runs every suite in the package.
