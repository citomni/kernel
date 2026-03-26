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
namespace CitOmni\Kernel\Command;

/**
 * Deterministic argv parser for CLI commands.
 *
 * Pure static utility - no App, no IO, no state. Receives a token array
 * and a command signature, returns a structured result with parsed values
 * or a human-readable error message.
 *
 * Behavior:
 * - Tokens are the argv slice after the command name (everything past
 *   `php bin/citomni command-name`).
 * - `--` stops option parsing; all subsequent tokens become positional.
 * - Long options: `--name=value`, `--name value` (string/int), `--flag` (bool).
 * - Bool negation: `--no-flag` sets a bool option `flag` to false.
 * - Short options: `-f value`, `-f=value`, `-fvalue` (string/int), `-f` (bool).
 * - Combined short flags (e.g. `-vf`) are deliberately unsupported.
 * - Duplicate options: last occurrence wins.
 * - Unknown options produce an immediate error.
 * - Type coercion is strict: `--port=abc` fails for type `int`.
 * - Arguments support the same type system as options (string, int) but not bool.
 * - Defaults are coerced to the declared type for both arguments and options.
 *
 * Signature format (stable DSL - treat as versioned contract):
 *   [
 *       'arguments' => [
 *           'name' => [
 *               'description' => string,			// for help text
 *               'required'    => bool,				// default: false
 *               'default'     => mixed,			// default: null; coerced to declared type
 *               'type'        => string,			// 'string'|'int', default: 'string'
 *           ],
 *       ],
 *       'options' => [
 *           'name' => [
 *               'short'       => string,			// single char, optional; 'h' is reserved
 *               'type'        => string,			// 'string'|'bool'|'int', default: 'string'
 *               'description' => string,			// for help text
 *               'required'    => bool,				// default: false
 *               'default'     => mixed,			// default: null (string/int) or false (bool)
 *               'allowed'     => string[]|int[],	// optional value whitelist; must match declared type
 *           ],
 *       ],
 *   ]
 *
 * Recognized top-level keys: arguments, options.
 * Recognized signature keys for arguments: description, required, default, type.
 * Recognized signature keys for options: short, type, description, required, default, allowed.
 * Any other key is rejected by validateSignature().
 *
 * Notes:
 * - Argument order in the signature array defines positional mapping.
 * - Required arguments must precede optional arguments (enforced by validator).
 * - `-h` and `--help` are reserved. Check with wantsHelp() before calling parse().
 * - Arguments do not support 'bool' type - use options for flags.
 */
final class ArgvParser {

	private const VALID_TOP_KEYS = ['arguments', 'options'];
	private const VALID_ARG_KEYS = ['description', 'required', 'default', 'type'];
	private const VALID_OPT_KEYS = ['short', 'type', 'description', 'required', 'default', 'allowed'];
	private const VALID_OPT_TYPES = ['string', 'bool', 'boolean', 'int', 'integer'];
	private const VALID_ARG_TYPES = ['string', 'int', 'integer'];





	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Check whether the token list contains a help request.
	 *
	 * Respects the `--` separator: tokens after `--` are positional and
	 * never trigger help.
	 *
	 * @param  array  $tokens  Argv slice after the command name.
	 * @return bool
	 */
	public static function wantsHelp(array $tokens): bool {
		foreach ($tokens as $token) {
			if ($token === '--') {
				return false;
			}
			if ($token === '--help' || $token === '-h') {
				return true;
			}
		}
		return false;
	}


	/**
	 * Parse CLI tokens against a command signature.
	 *
	 * @param  array  $tokens     Argv slice after the command name.
	 * @param  array  $signature  Command signature (see class PHPDoc).
	 * @return array{args: array<string, mixed>, opts: array<string, mixed>, error: ?string}
	 */
	public static function parse(array $tokens, array $signature): array {
		$argDefs  = $signature['arguments'] ?? [];
		$optDefs  = $signature['options'] ?? [];
		$shortMap = self::buildShortMap($optDefs);

		$rawOpts     = [];
		$positionals = [];
		$optsStopped = false;
		$i           = 0;
		$count       = \count($tokens);

		// -- 1. Walk tokens and classify ----------------------------------

		while ($i < $count) {
			$token = (string)$tokens[$i];

			if ($token === '--' && !$optsStopped) {
				$optsStopped = true;
				$i++;
				continue;
			}

			if ($optsStopped || $token === '' || $token[0] !== '-') {
				$positionals[] = $token;
				$i++;
				continue;
			}

			if (\str_starts_with($token, '--')) {
				$r = self::consumeLong($token, $tokens, $i, $count, $optDefs);
			} else {
				$r = self::consumeShort($token, $tokens, $i, $count, $optDefs, $shortMap);
			}

			if ($r['error'] !== null) {
				return self::err($r['error']);
			}

			$rawOpts[$r['name']] = $r['value'];
			$i = $r['next'];
		}

		// -- 2. Map positionals to argument definitions --------------------

		$argNames = \array_keys($argDefs);
		$args     = [];

		foreach ($positionals as $idx => $val) {
			if (!isset($argNames[$idx])) {
				return self::err("Unexpected argument: {$val}");
			}
			$args[$argNames[$idx]] = $val;
		}

		// -- 3. Resolve arguments: defaults and type coercion --------------
		// Both CLI-provided values and defaults are coerced to the declared
		// type so that typed accessors always receive the canonical PHP type.

		foreach ($argDefs as $name => $def) {
			$type = self::resolveArgType($def);

			if (\array_key_exists($name, $args)) {
				$coerced = self::coerce($name, $args[$name], $type, 'argument');
				if ($coerced['error'] !== null) {
					return self::err($coerced['error']);
				}
				$args[$name] = $coerced['value'];
			} else {
				$raw = $def['default'] ?? null;
				if ($raw !== null) {
					$coerced = self::coerce($name, $raw, $type, 'argument');
					if ($coerced['error'] !== null) {
						return self::err($coerced['error']);
					}
					$args[$name] = $coerced['value'];
				} else {
					$args[$name] = null;
				}
			}
		}

		// -- 4. Resolve options: defaults, coercion, allowed values --------
		// Same principle: both CLI-provided values and defaults are coerced.

		$opts = [];

		foreach ($optDefs as $name => $def) {
			$type = self::resolveOptType($def);

			if (\array_key_exists($name, $rawOpts)) {
				$coerced = self::coerce($name, $rawOpts[$name], $type, 'option');
				if ($coerced['error'] !== null) {
					return self::err($coerced['error']);
				}
				$opts[$name] = $coerced['value'];
			} else {
				$raw = \array_key_exists('default', $def)
					? $def['default']
					: ($type === 'bool' ? false : null);
				if ($raw !== null) {
					$coerced = self::coerce($name, $raw, $type, 'option');
					if ($coerced['error'] !== null) {
						return self::err($coerced['error']);
					}
					$opts[$name] = $coerced['value'];
				} else {
					$opts[$name] = null;
				}
			}

			if (isset($def['allowed']) && \is_array($def['allowed']) && $opts[$name] !== null) {
				if (!\in_array($opts[$name], $def['allowed'], true)) {
					$list = \implode(', ', \array_map(static fn($v) => (string)$v, $def['allowed']));
					return self::err("Option --{$name} must be one of: {$list}");
				}
			}
		}

		// -- 5. Validate required arguments and options --------------------

		foreach ($argDefs as $name => $def) {
			if (!empty($def['required']) && ($args[$name] === null || $args[$name] === '')) {
				return self::err("Missing required argument: {$name}");
			}
		}

		foreach ($optDefs as $name => $def) {
			if (!empty($def['required']) && ($opts[$name] === null || $opts[$name] === '')) {
				return self::err("Missing required option: --{$name}");
			}
		}

		return ['args' => $args, 'opts' => $opts, 'error' => null];
	}


	/**
	 * Validate a command signature for developer errors.
	 *
	 * Intended for dev-mode only. Returns an array of human-readable
	 * error strings. Empty array = signature is valid.
	 *
	 * Checks performed:
	 * - Unrecognized top-level keys (only 'arguments' and 'options' allowed).
	 * - Top-level 'arguments' and 'options' must be arrays if present.
	 * - Unrecognized keys in argument/option definitions.
	 * - Invalid 'type' values.
	 * - Bool type on arguments (not supported - use options for flags).
	 * - Invalid 'short' aliases (must be single [a-zA-Z0-9], not 'h').
	 * - Duplicate short aliases.
	 * - Required argument declared after an optional argument.
	 * - 'allowed' on bool options (meaningless).
	 * - 'allowed' values must match the declared option type.
	 * - Bool options must not have 'default' => null (meaningless; use false or omit).
	 * - Bool-negation conflicts (option 'no-X' coexists with bool option 'X').
	 * - 'required' combined with a non-null default (contradictory).
	 * - Default values must be coercible to the declared type.
	 *
	 * @param  array  $signature  Command signature.
	 * @return string[]  List of validation errors (empty = valid).
	 */
	public static function validateSignature(array $signature): array {
		$errors = [];

		// -- Top-level keys -----------------------------------------------

		$unknownTop = \array_diff(\array_keys($signature), self::VALID_TOP_KEYS);
		if ($unknownTop !== []) {
			$errors[] = "Unrecognized top-level keys: " . \implode(', ', $unknownTop);
		}

		if (isset($signature['arguments']) && !\is_array($signature['arguments'])) {
			$errors[] = "Top-level 'arguments' must be an array.";
		}
		if (isset($signature['options']) && !\is_array($signature['options'])) {
			$errors[] = "Top-level 'options' must be an array.";
		}

		// Bail early if structure is fundamentally broken - the per-field
		// checks below assume arrays and would produce misleading errors.
		if ($errors !== []) {
			return $errors;
		}

		$argDefs = $signature['arguments'] ?? [];
		$optDefs = $signature['options'] ?? [];


		// -- Arguments ----------------------------------------------------

		$seenOptionalArg = false;

		foreach ($argDefs as $name => $def) {
			if (!\is_string($name) || $name === '') {
				$errors[] = "Argument name must be a non-empty string, got: " . \var_export($name, true);
				continue;
			}
			if (!\is_array($def)) {
				$errors[] = "Argument '{$name}': definition must be an array.";
				continue;
			}

			$unknownKeys = \array_diff(\array_keys($def), self::VALID_ARG_KEYS);
			if ($unknownKeys !== []) {
				$errors[] = "Argument '{$name}': unrecognized keys: " . \implode(', ', $unknownKeys);
			}

			$type = (string)($def['type'] ?? 'string');
			if (!\in_array($type, self::VALID_ARG_TYPES, true)) {
				$errors[] = "Argument '{$name}': invalid type '{$type}'. Allowed: string, int.";
			}

			$required = !empty($def['required']);
			if ($required && $seenOptionalArg) {
				$errors[] = "Argument '{$name}': required argument after optional argument.";
			}
			if (!$required) {
				$seenOptionalArg = true;
			}

			if ($required && \array_key_exists('default', $def) && $def['default'] !== null) {
				$errors[] = "Argument '{$name}': 'required' with a non-null default is contradictory.";
			}

			if (\array_key_exists('default', $def) && $def['default'] !== null && \in_array($type, self::VALID_ARG_TYPES, true)) {
				$ct = self::resolveArgType($def);
				$test = self::coerce($name, $def['default'], $ct, 'argument');
				if ($test['error'] !== null) {
					$errors[] = "Argument '{$name}': default value " . \var_export($def['default'], true)
						. " cannot be coerced to declared type '{$ct}'.";
				}
			}
		}


		// -- Options ------------------------------------------------------

		$shortsSeen = [];
		$optNames   = \array_keys($optDefs);
		$boolNames  = [];

		foreach ($optDefs as $name => $def) {
			if (!\is_string($name) || $name === '') {
				$errors[] = "Option name must be a non-empty string, got: " . \var_export($name, true);
				continue;
			}
			if (!\is_array($def)) {
				$errors[] = "Option '{$name}': definition must be an array.";
				continue;
			}

			$unknownKeys = \array_diff(\array_keys($def), self::VALID_OPT_KEYS);
			if ($unknownKeys !== []) {
				$errors[] = "Option '{$name}': unrecognized keys: " . \implode(', ', $unknownKeys);
			}

			$type = (string)($def['type'] ?? 'string');
			if (!\in_array($type, self::VALID_OPT_TYPES, true)) {
				$errors[] = "Option '{$name}': invalid type '{$type}'. Allowed: string, bool, int.";
			}

			$canonicalType = self::resolveOptType($def);
			if ($canonicalType === 'bool') {
				$boolNames[] = $name;
			}

			if (isset($def['short'])) {
				$short = $def['short'];
				if (!\is_string($short) || \strlen($short) !== 1 || !\preg_match('/^[a-zA-Z0-9]$/', $short)) {
					$errors[] = "Option '{$name}': 'short' must be a single alphanumeric character, got: " . \var_export($short, true);
				} elseif ($short === 'h') {
					$errors[] = "Option '{$name}': short alias 'h' is reserved for --help.";
				} elseif (isset($shortsSeen[$short])) {
					$errors[] = "Option '{$name}': duplicate short alias '{$short}' (already used by '{$shortsSeen[$short]}').";
				} else {
					$shortsSeen[$short] = $name;
				}
			}

			if ($canonicalType === 'bool' && isset($def['allowed'])) {
				$errors[] = "Option '{$name}': 'allowed' is meaningless for bool options.";
			}

			if (isset($def['allowed']) && \is_array($def['allowed']) && $canonicalType !== 'bool') {
				$expectedPhpType = $canonicalType === 'int' ? 'integer' : 'string';
				foreach ($def['allowed'] as $av) {
					if (\gettype($av) !== $expectedPhpType) {
						$errors[] = "Option '{$name}': 'allowed' value " . \var_export($av, true)
							. " does not match declared type '{$canonicalType}'.";
					}
				}
			}

			if ($canonicalType === 'bool' && \array_key_exists('default', $def) && $def['default'] === null) {
				$errors[] = "Option '{$name}': bool options must not have 'default' => null (use false or omit default).";
			}

			if (!empty($def['required']) && \array_key_exists('default', $def) && $def['default'] !== null) {
				$errors[] = "Option '{$name}': 'required' with a non-null default is contradictory.";
			}

			if (\array_key_exists('default', $def) && $def['default'] !== null && \in_array($type, self::VALID_OPT_TYPES, true)) {
				$test = self::coerce($name, $def['default'], $canonicalType, 'option');
				if ($test['error'] !== null) {
					$errors[] = "Option '{$name}': default value " . \var_export($def['default'], true)
						. " cannot be coerced to declared type '{$canonicalType}'.";
				}
			}
		}


		// -- Bool-negation conflicts --------------------------------------

		foreach ($boolNames as $boolName) {
			$negName = 'no-' . $boolName;
			if (\in_array($negName, $optNames, true)) {
				$errors[] = "Option '{$negName}' conflicts with bool negation of '{$boolName}'.";
			}
		}

		return $errors;
	}






	// ----------------------------------------------------------------
	// Token consumption
	// ----------------------------------------------------------------

	/**
	 * Consume a long option token (--name, --name=value, --no-name).
	 *
	 * @return array{name: string, value: mixed, next: int, error: ?string}
	 */
	private static function consumeLong(string $token, array $tokens, int $i, int $count, array $optDefs): array {
		$body  = \substr($token, 2);
		$eqPos = \strpos($body, '=');

		if ($eqPos !== false) {
			$name  = \substr($body, 0, $eqPos);
			$value = \substr($body, $eqPos + 1);
		} else {
			$name  = $body;
			$value = null;
		}

		// -- Bool negation: --no-flag -> flag = false ----------------------
		// Only when 'flag' is a defined bool option and 'no-flag' itself
		// is NOT a defined option (avoids ambiguity).

		if (!isset($optDefs[$name]) && \str_starts_with($name, 'no-')) {
			$positive = \substr($name, 3);
			if ($positive !== '' && isset($optDefs[$positive]) && self::resolveOptType($optDefs[$positive]) === 'bool') {
				if ($eqPos !== false) {
					return self::tokenErr("Option --{$name} (negation) does not accept a value");
				}
				return ['name' => $positive, 'value' => false, 'next' => $i + 1, 'error' => null];
			}
		}

		if (!isset($optDefs[$name])) {
			return self::tokenErr("Unknown option: --{$name}");
		}

		$type = self::resolveOptType($optDefs[$name]);

		// -- Bool option --------------------------------------------------

		if ($type === 'bool') {
			if ($eqPos !== false) {
				return ['name' => $name, 'value' => $value, 'next' => $i + 1, 'error' => null];
			}
			return ['name' => $name, 'value' => true, 'next' => $i + 1, 'error' => null];
		}

		// -- String/int option: requires a value --------------------------

		if ($eqPos !== false) {
			return ['name' => $name, 'value' => $value, 'next' => $i + 1, 'error' => null];
		}

		$nextIdx = $i + 1;
		if ($nextIdx >= $count) {
			return self::tokenErr("Option --{$name} requires a value");
		}

		return ['name' => $name, 'value' => (string)$tokens[$nextIdx], 'next' => $nextIdx + 1, 'error' => null];
	}


	/**
	 * Consume a short option token (-f, -f value, -f=value, -fvalue).
	 *
	 * @return array{name: string, value: mixed, next: int, error: ?string}
	 */
	private static function consumeShort(string $token, array $tokens, int $i, int $count, array $optDefs, array $shortMap): array {
		if (!isset($token[1]) || !\preg_match('/^[a-zA-Z0-9]$/', $token[1])) {
			return self::tokenErr("Invalid option: {$token}");
		}

		$char = $token[1];

		if (!isset($shortMap[$char])) {
			return self::tokenErr("Unknown option: -{$char}");
		}

		$name = $shortMap[$char];
		$type = self::resolveOptType($optDefs[$name]);
		$rest = \substr($token, 2);

		// -- Bool option --------------------------------------------------

		if ($type === 'bool') {
			if ($rest !== '' && $rest !== false) {
				return self::tokenErr("Option -{$char} (bool) does not accept an inline value; use --{$name}=true/false");
			}
			return ['name' => $name, 'value' => true, 'next' => $i + 1, 'error' => null];
		}

		// -- String/int option: value from remainder or next token ---------

		if ($rest !== '' && $rest !== false) {
			if ($rest[0] === '=') {
				$rest = \substr($rest, 1);
			}
			return ['name' => $name, 'value' => $rest, 'next' => $i + 1, 'error' => null];
		}

		$nextIdx = $i + 1;
		if ($nextIdx >= $count) {
			return self::tokenErr("Option -{$char} (--{$name}) requires a value");
		}

		return ['name' => $name, 'value' => (string)$tokens[$nextIdx], 'next' => $nextIdx + 1, 'error' => null];
	}






	// ----------------------------------------------------------------
	// Type resolution and coercion
	// ----------------------------------------------------------------

	/**
	 * Resolve the canonical type string from an option definition.
	 */
	private static function resolveOptType(array $def): string {
		return match ($def['type'] ?? 'string') {
			'bool', 'boolean' => 'bool',
			'int', 'integer'  => 'int',
			default           => 'string',
		};
	}


	/**
	 * Resolve the canonical type string from an argument definition.
	 *
	 * Arguments do not support bool - only string and int.
	 */
	private static function resolveArgType(array $def): string {
		return match ($def['type'] ?? 'string') {
			'int', 'integer' => 'int',
			default          => 'string',
		};
	}


	/**
	 * Coerce a raw value to the declared type.
	 *
	 * Used for both CLI-provided values and signature defaults, ensuring
	 * that typed accessors always receive the canonical PHP type.
	 *
	 * @param  string  $name   Argument or option name (for error messages).
	 * @param  mixed   $value  Raw value from token consumption or default.
	 * @param  string  $type   Canonical type ('string', 'int', 'bool').
	 * @param  string  $kind   'argument' or 'option' (for error messages).
	 * @return array{value: mixed, error: ?string}
	 */
	private static function coerce(string $name, mixed $value, string $type, string $kind): array {
		if ($value === null) {
			return ['value' => null, 'error' => null];
		}
		$label = $kind === 'argument' ? "Argument {$name}" : "Option --{$name}";
		return match ($type) {
			'bool' => self::coerceBool($label, $value),
			'int'  => self::coerceInt($label, $value),
			default => ['value' => (string)$value, 'error' => null],
		};
	}


	/**
	 * Coerce a value to bool.
	 *
	 * Accepts: true/false (native), 1/0 (native int), "1"/"0",
	 * "true"/"false", "yes"/"no", "on"/"off".
	 *
	 * @param  string  $label  Human-readable name for error messages.
	 * @return array{value: ?bool, error: ?string}
	 */
	private static function coerceBool(string $label, mixed $value): array {
		if (\is_bool($value)) {
			return ['value' => $value, 'error' => null];
		}
		if (\is_int($value)) {
			return match ($value) {
				1 => ['value' => true, 'error' => null],
				0 => ['value' => false, 'error' => null],
				default => ['value' => null, 'error' => "{$label} expects a boolean, got {$value}"],
			};
		}
		return match (\strtolower(\trim((string)$value))) {
			'1', 'true', 'yes', 'on'  => ['value' => true, 'error' => null],
			'0', 'false', 'no', 'off' => ['value' => false, 'error' => null],
			default => ['value' => null, 'error' => "{$label} expects a boolean, got '{$value}'"],
		};
	}


	/**
	 * Coerce a value to int.
	 *
	 * Accepts: native int, or a string of optional leading minus and digits.
	 * No floats, no hex, no thousand separators.
	 *
	 * @param  string  $label  Human-readable name for error messages.
	 * @return array{value: ?int, error: ?string}
	 */
	private static function coerceInt(string $label, mixed $value): array {
		if (\is_int($value)) {
			return ['value' => $value, 'error' => null];
		}
		$s = \trim((string)$value);
		if ($s === '' || !\preg_match('/^-?\d+$/', $s)) {
			return ['value' => null, 'error' => "{$label} expects an integer, got '{$value}'"];
		}
		return ['value' => (int)$s, 'error' => null];
	}







	// ----------------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------------

	/**
	 * Build a short-alias -> long-name lookup map from option definitions.
	 *
	 * @return array<string, string>
	 */
	private static function buildShortMap(array $optDefs): array {
		$map = [];
		foreach ($optDefs as $name => $def) {
			if (isset($def['short']) && \is_string($def['short']) && \strlen($def['short']) === 1) {
				$map[$def['short']] = $name;
			}
		}
		return $map;
	}


	/**
	 * Build a parse-level error result.
	 *
	 * @return array{args: array, opts: array, error: string}
	 */
	private static function err(string $message): array {
		return ['args' => [], 'opts' => [], 'error' => $message];
	}


	/**
	 * Build a token-consumption error (used by consumeLong/consumeShort).
	 *
	 * @return array{name: string, value: null, next: int, error: string}
	 */
	private static function tokenErr(string $message): array {
		return ['name' => '', 'value' => null, 'next' => 0, 'error' => $message];
	}

}
