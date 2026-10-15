# Cfg suite

Isolated checks for `\CitOmni\Kernel\Cfg`: enum cases come back unchanged through property access, array access, and `toArray()`, unknown keys fail fast, and the three read rules hold. No database, no Composer autoloader, no files written, and no setup per session.

## Run

```
php tests/cfg/run.php
```

Expected result:

```
7 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

Every check builds its `Cfg` the way App builds it: an array normalized with the real `Arr::normalizeConfig()`, wrapped in a `Cfg`. Both classes run from `src/`, loaded by the autoloader in `tests/bootstrap.php`. The enum checks use the fixtures in `tests/Fixtures/`; the read-rule checks use a cfg with one node holding a `true` leaf and a `null` leaf.

The read rules that guides and code rely on:

1. A read without `??` throws `OutOfBoundsException` when any node is missing.
2. `??` returns its default for a missing node at any depth, and for `null`.
3. `isset()` reports whether a key exists, also when its value is `null`.

## Checks

R marks a regression check: it fails against `src/` as it was before `582ec5e` (Preserve enum cases in Arr::normalizeConfig()). The other checks pin existing behavior.

| | Check | Guards |
|---|---|---|
| R | __get() returns enum cases unchanged and does not wrap an IteratorAggregate case | `Arr` flattened or iterated the cases before `Cfg` held them. |
| R | offsetGet() returns enum cases unchanged and does not wrap an IteratorAggregate case | The same, through array access. |
| R | toArray() holds the same enum instances at the root and in a nested node | The same, through `toArray()`. |
| | an unknown key throws OutOfBoundsException at the root and in a nested node | Existing behavior |
| | Rule 1: without ??, a missing intermediate node throws before its leaf is read | Read rule 1, property and array syntax |
| | Rule 2: ?? returns its default for a missing node at any depth and for null | Read rule 2: one and two missing nodes, a missing leaf, a `null` leaf, an existing value |
| | Rule 3: isset() reports whether a key exists, also when its value is null | Read rule 3, unlike PHP's own `isset()` on arrays |

Against `src/` at the parent of `582ec5e`: `3 passed, 4 failed`. The three R checks fail, and so does the unknown-key check: its `Cfg` holds the enum fixtures, and the old `normalizeConfig()` iterates `TraversableEnumFixture` while building it.

`php tests/run.php` runs every suite in the package.
