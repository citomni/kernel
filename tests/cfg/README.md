# Cfg suite

Isolated checks for `\CitOmni\Kernel\Cfg` and `App::$cfg`. Every behavior statement B1-B22 of the [Cfg access reference](https://github.com/citomni/docs/blob/main/reference/cfg-access.md) has a case named after it, and so have the code forms of rules R2, R5 and R6. A failing case means that the reference and the code disagree. No database, no Composer autoloader, no files written, and no setup per session.

## Run

```
php tests/cfg/run.php
```

Expected result:

```
31 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check builds its `Cfg` the way App builds it: an array normalized with the real `Arr::normalizeConfig()`, wrapped in a `Cfg`. Both classes run from `src/`, loaded by the autoloader in `tests/bootstrap.php`. The enum checks use the fixtures in `tests/Fixtures/`. The B1 and B18 checks use `App` through reflection, without constructing it.

The suite's error handler turns every PHP diagnostic into an `\ErrorException`. A read that must stay silent, such as `??` past a scalar, therefore fails its case if PHP raises a warning, and a case that expects a diagnostic, such as B9 or B20, checks its severity and text.

## Checks

R marks a regression check: it fails against the `src/` it protects. The other checks pin existing behavior.

| | Case | Guards |
|---|---|---|
| | B1 | `App::$cfg` is public, readonly and typed `Cfg`. |
| | B2-B5 | Maps and empty arrays come back as nodes, lists as arrays, gapped or unordered integer keys as maps. |
| | B6 | Values that are not arrays come back unchanged. |
| R | B6, two enum cases | `Arr` flattened or iterated enum cases before `Cfg` held them; fails against the parent of `582ec5e`. |
| | B7 | Repeated reads return the same node. |
| | B8, three cases | A direct read throws `OutOfBoundsException` at the first missing key and names it. |
| | B9 | Past a value that is not a node, PHP raises its own warning and `Cfg` throws nothing. |
| | B10-B12 | `??`, `isset()` and `empty()` never throw on a missing key; `??` raises no diagnostic; `isset()` is true for `null`. |
| | B13-B14 | `??` guards only its left operand; `?->` guards no key. |
| R | B15, enum case | `toArray()` holds the same enum instances; fails against the parent of `582ec5e`. |
| | B15-B17 | `toArray()`, `foreach` and `count()` return stored data; a node is not an array. |
| | B18-B20 | `App::$cfg` cannot be reassigned; array-syntax writes throw; changing a list read through `Cfg` changes nothing. |
| R | B21, B22 | Property-syntax writes and unsets throw `LogicException` and change nothing. |
| | R2, R5, R6 | The code forms of the read rules. |

Against `src/Cfg.php` without `__set()` and `__unset()`, as at `959f8a4`: `29 passed, 2 failed`. B21 fails because PHP creates a dynamic property instead, and B22 fails because nothing is thrown.

Against `src/` at the parent of `582ec5e`: `25 passed, 6 failed`. The three enum cases fail, and so does the first B8 case: its `Cfg` holds the enum fixtures, and the old `normalizeConfig()` iterates `TraversableEnumFixture` while building it. B21 and B22 fail as above.

`php tests/run.php` runs every suite in the package.
