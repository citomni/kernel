<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\Kernel;

/**
 * Cfg: Read-only deep-access wrapper for configuration arrays.
 *
 * What it does:
 * - Gives you immutable, ergonomic access to the merged CitOmni config that App built.
 *   1) Property syntax: $cfg->http->base_url
 *   2) Array syntax:    $cfg['http']['base_url']
 *   3) Nested associative arrays become lazy-wrapped Cfg nodes (memoized).
 *   4) Numeric lists (0..n-1) are returned as plain PHP arrays.
 *   5) Empty arrays are still wrapped as Cfg nodes so you can safely chain ->toArray().
 *
 * - Keys listed in RAW_ARRAY_SET are always returned as raw arrays for performance /
 *   clarity. (As of now RAW_ARRAY_SET is intentionally empty because routes have been
 *   moved out of cfg and onto $app->routes instead.)
 *
 * - Fails fast on unknown keys to surface config mistakes early.
 *
 * Relationship to App:
 * - \CitOmni\Kernel\App builds the final merged configuration using deterministic
 *   "last wins" rules (vendor -> providers -> /config/citomni_http_cfg.php -> env overlay).
 * - App then wraps that merged array in this Cfg class and exposes it as $app->cfg.
 *
 * Error handling:
 * - \OutOfBoundsException if you access an unknown config key (property or array syntax).
 * - \LogicException on any write attempt (Cfg is immutable by design).
 * - \UnexpectedValueException if a RAW_ARRAY_SET key is not actually an array.
 *
 * Typical usage:
 *
 *   $baseUrl = $app->cfg->http->base_url ?? null;        // property chaining
 *   $tz      = $app->cfg->locale->timezone ?? 'UTC';     // nested node (Cfg)
 *   $charset = $app->cfg['locale']['charset'] ?? 'UTF-8';// array syntax, same semantics
 *
 *   // still works even if 'mail' was merged in as an empty array:
 *   $mailCfg = $app->cfg->mail->toArray();               // []
 *
 * Capability hint:
 *   if ($app->hasService('mailer')) { /send email/ }
 *
 * Manual construction (tests / sandbox):
 *
 *   $cfg = new \CitOmni\Kernel\Cfg([
 *       'http'   => ['base_url' => 'https://example.com'],
 *       'locale' => ['timezone' => 'Europe/Copenhagen', 'charset' => 'UTF-8'],
 *       'mail'   => [], // empty arrays become nested Cfg nodes, so chaining is still safe
 *   ]);
 *
 *   echo $cfg->http->base_url;           // "https://example.com"
 *   $zone = $cfg->locale->timezone;      // "Europe/Copenhagen"
 *   $mail = $cfg->mail->toArray();       // []
 */
final class Cfg implements \ArrayAccess, \IteratorAggregate, \Countable {
	
	
/*
 *---------------------------------------------------------------
 * STATE & CONSTANTS - Minimal, hot-path friendly
 *---------------------------------------------------------------
 * PURPOSE
 *   Keep normalized config data and a tiny memo cache for nested nodes.
 *
 * NOTES
 *   - Memoization avoids re-wrapping associative arrays on hot paths.
 */
	
	
	/** 
	 * Underlying normalized configuration data.
	 *
	 * @var array<string|int,mixed>
	 */
	private array $data;


	/** 
	 * Cache for nested nodes accessed via property syntax to avoid
	 * repeatedly constructing wrapper instances on hot paths.
	 *
	 * @var array<string, self>
	 */
	private array $cache = [];
	
	
	/**
	 * Keys that must always be returned as raw arrays (never wrapped).
	 *
	 * Historically this included "routes", because routing tables were exposed
	 * via $app->cfg->routes and consumers indexed them directly as arrays.
	 *
	 * Routes now live on $app->routes instead, so RAW_ARRAY_SET is intentionally
	 * empty in current CitOmni. Leaving the mechanism in place keeps BC if we ever
	 * decide another high-traffic key should bypass wrapping.
	 */
	private const RAW_ARRAY_SET = [];



/*
 *---------------------------------------------------------------
 * CONSTRUCTION - Normalized input only
 *---------------------------------------------------------------
 */

	/**
	 * @param array<string|int,mixed> $data Normalized configuration array.
	 * 
	 * The App is responsible for supplying a *normalized* array where vendor,
	 * providers, and app layers have already been merged (deterministic
	 * "last-wins" semantics).
	 */
	public function __construct(array $data) {
		// Expect normalized arrays (App already normalizes vendor/app cfg sources)
		$this->data = $data;
	}


/*
 *---------------------------------------------------------------
 * MAGIC ACCESSORS - Property-style configuration access
 *---------------------------------------------------------------
 * PURPOSE
 *   $cfg->http->base_url, $cfg->locale->timezone, etc.
 * CONTRACT
 *   - Unknown keys => OutOfBoundsException
 *   - RAW_ARRAY_SET => raw arrays (never wrapped)
 *   - Assoc arrays => lazily wrapped Cfg (memoized)
 */

	/**
	 * REPLACED BELOW: OLD ONE HAS BEEN KEPT FOR SAFETY UNTIL NEW ONE HAS BEEN BATTLE-TESTED!
	 * 
	 * Magic property accessor for configuration nodes.
	 *
	 * Behavior:
	 * - Fail fast on unknown keys (throws \OutOfBoundsException).
	 * - If the value is an array and the key is in RAW_ARRAY_SET (e.g., "routes"),
	 *   return the raw array (never wrapped).
	 * - If the value is an associative array, lazily wrap it as a Cfg node and memoize
	 *   the wrapper instance for subsequent accesses.
	 * - Otherwise, return the value as-is (scalars or numeric lists).
	 *
	 * Notes:
	 * - Memoization ensures hot paths do not re-wrap the same nested associative arrays.
	 * - Lists (0..n-1) are always returned as plain arrays.
	 *
	 * Typical usage:
	 *   $baseUrl = $cfg->http->base_url ?? null;
	 *   $routes  = $cfg->routes; // raw array by contract
	 *
	 * @param string $key Existing configuration key at this node.
	 * @return mixed Wrapped Cfg node, raw array, or scalar depending on the stored value.
	 * @throws \OutOfBoundsException When the key does not exist at this node.
	 */
	/* 
	public function __get(string $key): mixed {
		if (!\array_key_exists($key, $this->data)) {
			throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
		}

		$val = $this->data[$key];

		// 1) Enforce raw array for specific keys (e.g., routes)
		if (\is_array($val) && isset(self::RAW_ARRAY_SET[$key])) {
			return $val;
		}

		// 2) Wrap associative arrays as Cfg; leave lists as raw arrays
		if (\is_array($val) && self::isAssoc($val)) {
			return $this->cache[$key] ??= new self($val);
		}

		return $val;
	}
	*/
	
	
	/**
	 * Magic property accessor for configuration nodes.
	 *
	 * Returns either:
	 * - a wrapped configuration node (Cfg),
	 * - a raw array,
	 * - or a scalar,
	 * depending on the stored value and key-specific contract.
	 *
	 * Behavior:
	 * - Fail fast on unknown keys: throws \OutOfBoundsException.
	 * - Keys listed in RAW_ARRAY_SET are always returned as raw arrays. If such a
	 *   key does not actually hold an array, \UnexpectedValueException is thrown.
	 *   (RAW_ARRAY_SET is currently empty in core CitOmni, see class doc.)
	 * - For other array values:
	 *     - Empty arrays OR associative arrays -> wrapped as a Cfg node and memoized.
	 *     - Numeric lists (0..n-1) -> returned as raw arrays.
	 * - Non-array values (scalars) are returned as-is.
	 *
	 * Notes:
	 * - Memoization means repeated access to the same nested node does not re-wrap.
	 * - Deterministic "last wins" merge rules mean environment overlays can collapse
	 *   nodes to empty arrays. Those still become valid Cfg nodes, so chaining like
	 *   `$cfg->mail->toArray()` is always safe.
	 *
	 * @param string $key Existing configuration key at this node.
	 * @return mixed Wrapped Cfg node, raw array, or scalar depending on the stored value.
	 * @throws \OutOfBoundsException      If the key does not exist at this node.
	 * @throws \UnexpectedValueException  If a RAW_ARRAY_SET key does not hold an array.
	 */
	public function __get(string $key): mixed {
		if (!\array_key_exists($key, $this->data)) {
			throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
		}

		$val = $this->data[$key];

		// 1) Raw-array contract for special keys (e.g., 'routes')
		if (isset(self::RAW_ARRAY_SET[$key])) {
			if (!\is_array($val)) {
				// Fail fast: 'routes' (or other raw keys) must always be arrays
				throw new \UnexpectedValueException("Config key '{$key}' must be an array.");
			}
			return $val; // always raw (even when empty)
		}

		// 2) Non-arrays are returned as-is (scalars)
		if (!\is_array($val)) {
			return $val;
		}

		// 3) Empty arrays and associative arrays -> wrap as Cfg (prevents ->toArray() crashes)
		if ($val === [] || self::isAssoc($val)) {
			return $this->cache[$key] ??= new self($val);
		}

		// 4) Numeric lists remain raw arrays (e.g., trusted_proxies)
		return $val;
	}





	/**
	 * Checks whether a configuration key exists.
	 *
	 * Uses array_key_exists (not isset) so null values still count as present.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function __isset(string $key): bool {
		return \array_key_exists($key, $this->data);
	}


/*
 *---------------------------------------------------------------
 * EXPORTS - Raw view of this node
 *---------------------------------------------------------------
 */

	/**
	 * Returns the underlying configuration array as-is.
	 *
	 * @return array<string|int,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}



/*
 *---------------------------------------------------------------
 * ARRAYACCESS (READ-ONLY) - Index-style configuration access
 *---------------------------------------------------------------
 * PURPOSE
 *   $cfg['http']['base_url'], $cfg['locale']['charset'], etc.
 *
 * CONTRACT
 *   - Unknown keys => OutOfBoundsException.
 *   - Writes are forbidden (no, not even with "please"):
 *       offsetSet()/offsetUnset() => LogicException.
 *   - RAW_ARRAY_SET keys => raw arrays; associative arrays may be wrapped Cfg.
 *
 * PARITY
 *   - Same semantics as magic accessors; also memoized for hot paths.
 */


	/**
	 * ArrayAccess: check if an offset exists.
	 *
	 * @param mixed $offset
	 * @return bool
	 */
	public function offsetExists(mixed $offset): bool {
		return \array_key_exists($offset, $this->data);
	}


	/**
	 * ArrayAccess read accessor for configuration nodes.
	 *
	 * Behavior:
	 * - Fail fast on unknown keys: throws \OutOfBoundsException.
	 * - If the key is in RAW_ARRAY_SET, the underlying value must be an array and is
	 *   always returned as that raw array (never wrapped). If it's not an array,
	 *   \UnexpectedValueException is thrown.
	 * - Otherwise:
	 *     - Empty arrays or associative arrays are wrapped as Cfg nodes and memoized
	 *       (parity with __get()).
	 *     - Numeric lists (0..n-1) are returned as plain arrays.
	 *     - Scalars are returned as-is.
	 *
	 * Typical usage:
	 *   $charset = $cfg['locale']['charset'] ?? 'UTF-8';
	 *   $httpCfg = $cfg['http']->toArray();
	 *
	 * @param string|int $offset Existing configuration key at this node.
	 * @return mixed Wrapped Cfg node, raw array, or scalar depending on the stored value.
	 * @throws \OutOfBoundsException     When the key does not exist at this node.
	 * @throws \UnexpectedValueException If a RAW_ARRAY_SET key is not an array.
	 */
	public function offsetGet(mixed $offset): mixed {
		if (!\array_key_exists($offset, $this->data)) {
			throw new \OutOfBoundsException("Unknown cfg key: '{$offset}'");
		}

		$key = (string)$offset;
		$val = $this->data[$key];

		// 1) Enforce raw array for specific keys (e.g., routes)
		if (isset(self::RAW_ARRAY_SET[$key])) {
			if (!\is_array($val)) {
				throw new \UnexpectedValueException("Config key '{$key}' must be an array.");
			}
			return $val;
		}

		// 2) Wrap assoc OR empty arrays as Cfg; memoize (parity with __get())
		if (\is_array($val)) {
			if ($val === [] || self::isAssoc($val)) {
				return $this->cache[$key] ??= new self($val);
			}
			return $val; // numeric list stays a plain array
		}

		return $val;
	}




	/**
	 * ArrayAccess: disallow writes (read-only by design).
	 *
	 * @param mixed $offset
	 * @param mixed $value
	 * @return void
	 * @throws \LogicException Always, because Cfg is immutable.
	 */
	public function offsetSet(mixed $offset, mixed $value): void {
		throw new \LogicException('Cfg is read-only.');
	}


	/**
	 * ArrayAccess: disallow unsetting (read-only by design).
	 *
	 * @param mixed $offset
	 * @return void
	 * @throws \LogicException Always, because Cfg is immutable.
	 */
	public function offsetUnset(mixed $offset): void {
		throw new \LogicException('Cfg is read-only.');
	}


/*
 *---------------------------------------------------------------
 * ITERATION - IteratorAggregate
 *---------------------------------------------------------------
 * PURPOSE
 *   Iterate raw values of the current node.
 *
 * NOTES
 *   - Yields raw arrays/scalars; wrap on demand if you need chaining.
 *   - Keeps iteration predictable and lightweight.
 */

	/**
	 * Iterates raw values of the underlying array.
	 *
	 * Consumers may wrap nested associative arrays into Cfg on demand
	 * if they need continued chaining during iteration.
	 *
	 * @return \Traversable<mixed>
	 */
	public function getIterator(): \Traversable {
		// Iterates raw values; wrap on demand in user code if needed
		yield from $this->data;
	}




/*
 *---------------------------------------------------------------
 * COUNTING - Countable
 *---------------------------------------------------------------
 * PURPOSE
 *   Return the number of top-level keys at this node.
 */

	/**
	 * Returns the number of top-level keys in this node.
	 *
	 * @return int
	 */
	public function count(): int {
		return \count($this->data);
	}



/*
 *---------------------------------------------------------------
 * INTERNALS - Helpers and predicates
 *---------------------------------------------------------------
 * PURPOSE
 *   Keep low-level helpers isolated and easy to audit.
 *
 * DETAILS
 *   - isAssoc(): empty arrays are considered non-associative.
 */


	/**
	 * Determines whether an array is associative (i.e., not a 0..n-1 list).
	 *
	 * Implementation detail:
	 * - An empty array is considered **not** associative.
	 * - Compares actual keys to a sequential 0..n-1 range to detect lists.
	 *
	 * @param array<mixed> $a
	 * @return bool
	 */
	private static function isAssoc(array $a): bool {
		return $a !== [] && \array_keys($a) !== \range(0, \count($a) - 1);
	}
}
