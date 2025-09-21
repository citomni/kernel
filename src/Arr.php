<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Kernel - Deterministic core for high-performance CitOmni apps.
 * Source:  https://github.com/citomni/kernel
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Kernel;


/**
 * Arr: Deterministic array utilities for config assembly and overlays.
 *
 * Responsibilities:
 * - Deterministic "last-wins" merge for configuration arrays.
 *   1) Associative sub-arrays merge recursively per key.
 *   2) List (indexed) arrays are replaced entirely (no concatenation).
 *   3) Integer-key collisions are overwritten by the right-hand side.
 * - Deep normalization of config structures.
 *   1) Top-level input MUST be array|object|Traversable (else RuntimeException).
 *   2) Nested arrays/objects/Traversables are converted to arrays recursively.
 *   3) Scalars/resources are preserved as-is.
 * - Lightweight predicates.
 *   1) isList(): detects sequential integer-keyed arrays starting at 0.
 *
 * Collaborators:
 * - None at runtime; used broadly by \CitOmni\Kernel\App during config/service assembly.
 *   (Static-only helper; no I/O, no global state.)
 *
 * Configuration keys:
 * - N/A (general-purpose array helpers; do not consume cfg directly).
 *
 * Error handling:
 * - normalizeConfig(): throws \RuntimeException if the top-level value is not array|object|Traversable.
 * - mergeAssocLastWins(): no exceptions; produces a deterministic merged array.
 *
 * Typical usage:
 *
 *   // Merge provider/app/env overlays into a vendor baseline:
 *   $merged = \CitOmni\Kernel\Arr::mergeAssocLastWins($baseline, $overlay);
 *
 *   // Ensure objects/iterables become pure arrays for the cfg wrapper:
 *   $cfg = \CitOmni\Kernel\Arr::normalizeConfig($merged);
 *
 * Examples:
 *
 *   // 1) Assoc merge (deep) vs list replace:
 *   $a = ['http' => ['headers' => ['X' => 'a']], 'routes' => ['/a', '/b']];
 *   $b = ['http' => ['headers' => ['Y' => 'b']], 'routes' => ['/c']];
 *   $r = \CitOmni\Kernel\Arr::mergeAssocLastWins($a, $b);
 *   // $r === ['http' => ['headers' => ['X' => 'a', 'Y' => 'b']], 'routes' => ['/c']]
 *
 *   // 2) Normalization of mixed objects/iterables:
 *   $obj = (object)['db' => (object)['host' => 'localhost']];
 *   $it  = new \ArrayObject(['list' => [1, 2, 3]]);
 *   $n   = \CitOmni\Kernel\Arr::normalizeConfig(['o' => $obj, 'i' => $it]);
 *   // $n === ['o' => ['db' => ['host' => 'localhost']], 'i' => ['list' => [1, 2, 3]]]
 *
 * Failure:
 *
 *   // Top-level invalid type (not array|object|Traversable) => RuntimeException:
 *   \CitOmni\Kernel\Arr::normalizeConfig('oops'); // bubbles to global handler
 *
 * Standalone (only if necessary):
 *
 *   // Minimal demo (assumes Composer autoload and namespace setup):
 *   require __DIR__ . '/vendor/autoload.php';
 *   $r = \CitOmni\Kernel\Arr::mergeAssocLastWins(['x' => [1,2]], ['x' => [9]]);
 *   // $r === ['x' => [9]]  // No, we do not concatenate lists.
 */
final class Arr {

	/**
	 * Recursively merge two arrays with deterministic "last-wins" semantics.
	 *
	 * Behavior:
	 * - Integer keys:
	 *   - If the right-hand array ($b) contains an integer key, its value always
	 *     replaces the left-hand value from $a at that position.
	 *   - This ensures predictable overwrite semantics for list-like structures.
	 *
	 * - Associative keys:
	 *   - If both $a[$k] and $b[$k] exist and are associative arrays
	 *     (i.e. not lists), they are merged recursively using the same rules.
	 *   - This allows deep configuration overlays where nested associative
	 *     structures can be selectively overridden.
	 *
	 * - Lists (numeric-indexed arrays):
	 *   - If either side is detected as a list (array_is_list(...)), recursion
	 *     is skipped and the right-hand value from $b replaces the left-hand
	 *     value from $a entirely.
	 *
	 * - Scalars or mismatched types:
	 *   - The value from $b always overwrites the value from $a.
	 *   - This includes "empty" values such as null, false, 0, '' (empty string),
	 *     or [] (empty array). An explicit empty override is therefore honored.
	 *
	 * Examples:
	 *   Scalars:
	 *     ['x' => 'a']      + ['x' => 'b']      => ['x' => 'b']
	 *     ['x' => 'a']      + ['x' => null]     => ['x' => null]
	 *     ['x' => 'a']      + ['x' => false]    => ['x' => false]
	 *     ['x' => 'a']      + ['x' => '']       => ['x' => '']
	 *
	 *   Lists:
	 *     ['x' => [1,2]]    + ['x' => [3,4]]    => ['x' => [3,4]]
	 *     ['x' => []]       + ['x' => [9]]      => ['x' => [9]]
	 *
	 *   Assoc arrays:
	 *     ['x' => ['a' => 1, 'b' => 2]]
	 *     + ['x' => ['b' => 9, 'c' => 3]]
	 *     => ['x' => ['a' => 1, 'b' => 9, 'c' => 3]]
	 *
	 *   Mixed types:
	 *     ['x' => ['a' => 1]] + ['x' => 'foo']  => ['x' => 'foo']
	 *     ['x' => 'foo']      + ['x' => ['a'=>1]] => ['x' => ['a'=>1]]
	 *
	 *   Integer keys:
	 *     [0 => 'a'] + [0 => 'b'] => [0 => 'b']
	 *     [0 => 'a'] + [1 => 'b'] => [0 => 'a', 1 => 'b']
	 *
	 * Determinism:
	 * - There is no key-preserving union or concatenation for list arrays.
	 *   Replacement is total. Associative arrays merge recursively.
	 * - This guarantees predictable "last definition wins" semantics, which
	 *   is essential for config layering (baseline -> provider -> app -> env).
	 *
	 * @param array<string|int,mixed> $a Base array (left-hand side).
	 * @param array<string|int,mixed> $b Overriding array (right-hand side).
	 * @return array<string|int,mixed> Merged result honoring "last-wins" semantics.
	 */
	public static function mergeAssocLastWins(array $a, array $b): array {
		foreach ($b as $k => $v) {
			if (\is_int($k)) {
				// Deterministic overwrite for integer keys
				$a[$k] = $v;
				continue;
			}

			// Both sides associative arrays? Merge deeply.
			if (\is_array($v) && \array_key_exists($k, $a) && \is_array($a[$k]) && !self::isList($a[$k]) && !self::isList($v)) {
				$a[$k] = self::mergeAssocLastWins($a[$k], $v);
			} else {
				// For lists or scalars, replace entirely (last wins).
				$a[$k] = $v;
			}
		}
		return $a;
	}


	/**
	 * Normalize a configuration value into a pure array (deep).
	 *
	 * Accepted inputs:
	 * - array: Each element is recursively normalized.
	 * - object: Converted via get_object_vars(), then normalized recursively.
	 * - Traversable: Converted via iterator_to_array(), then normalized recursively.
	 *
	 * Rules:
	 * - Nested arrays, objects, or Traversables are expanded into arrays all the way down.
	 * - Scalars (string, int, bool, float, null) are preserved as-is.
	 * - Empty arrays and objects normalize to empty arrays.
	 * - Top-level input MUST be array, object, or Traversable - otherwise RuntimeException.
	 * - Nested values (incl. scalars/resources) are preserved as-is unless they are arrays,
	 *   objects, or Traversables (which are normalized recursively).
	 *
	 * Purpose:
	 * - Guarantees that config structures containing stdClass instances or
	 *   other iterable objects become fully usable associative arrays.
	 *
	 * @param mixed $x Input configuration value (expected array, object, or Traversable).
	 * @return array<string,mixed> Fully normalized configuration array.
	 * @throws \RuntimeException If input is not array, object, or Traversable.
	 */
	public static function normalizeConfig(mixed $x): array {
		if (\is_array($x)) {
			$out = [];
			foreach ($x as $k => $v) {
				$out[$k] = self::convertValueDeep($v);
			}
			return $out;
		}
		if ($x instanceof \Traversable) {
			return self::normalizeConfig(\iterator_to_array($x));
		}
		if (\is_object($x)) {
			return self::normalizeConfig(\get_object_vars($x));
		}
		throw new \RuntimeException('Config must be array, object, or Traversable.');
	}


	/**
	 * Recursively convert a single value into arrays where applicable.
	 *
	 * Behavior:
	 * - Arrays: each element is processed recursively.
	 * - Traversable: converted via iterator_to_array(), then processed recursively.
	 * - Objects: converted via get_object_vars(), then processed recursively.
	 * - Everything else (incl. scalars/resources): returned as-is.
	 *
	 * Notes:
	 * - This method does NOT throw; type validation is handled by normalizeConfig()
	 *   for the top-level input.
	 *
	 * @param mixed $v Any value.
	 * @return mixed Array/normalized value or the original value when not convertible.
	 */
	private static function convertValueDeep(mixed $v): mixed {
		if (\is_array($v)) {
			$out = [];
			foreach ($v as $kk => $vv) {
				$out[$kk] = self::convertValueDeep($vv);
			}
			return $out;
		}
		if ($v instanceof \Traversable) {
			return self::normalizeConfig(\iterator_to_array($v));
		}
		if (\is_object($v)) {
			return self::normalizeConfig(\get_object_vars($v));
		}
		return $v;
	}


	/**
	 * Check whether a value is a **list** (sequential integer keys starting at 0).
	 *
	 * Rules:
	 * - Empty arrays are considered lists.
	 * - Arrays with keys [0..n-1] in order are lists.
	 * - Any other array shape is treated as associative.
	 *
	 * @param mixed $a Input value.
	 * @return bool True if input is a list, false otherwise.
	 */
	private static function isList(mixed $a): bool {
		return \is_array($a) && \array_is_list($a);
	}
}
