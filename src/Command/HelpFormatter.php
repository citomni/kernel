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
 * Generate help and usage text from a command signature.
 *
 * Pure static utility - no App, no IO, no state. Receives command metadata
 * and a signature array, returns formatted text strings ready for output.
 *
 * Behavior:
 * - format() produces the full help block shown by --help / -h.
 * - usage() produces the single-line usage summary shown alongside parse errors.
 * - Both methods are deterministic and produce identical output for identical input.
 * - Output is plain text with no ANSI codes - color is BaseCommand's concern.
 *
 * Notes:
 * - Arguments are shown in positional order as declared in the signature.
 * - Options are shown in declaration order, not alphabetically.
 * - --help / -h is always appended as the last option.
 * - Bool options show --no-name negation syntax in the options list.
 */
final class HelpFormatter {


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Generate the full help text for a command.
	 *
	 * Typical output:
	 *   import - Import data from a CSV file
	 *
	 *   Usage:
	 *     import <file> [--dry-run] [--batch=<int>]
	 *
	 *   Arguments:
	 *     file              CSV file to import  (required)
	 *
	 *   Options:
	 *     --dry-run             Validate without writing
	 *     -b, --batch=<int>     Batch size  (default: 100)
	 *     -h, --help            Show this help
	 *
	 * @param  string  $commandName         Command name (e.g. 'import' or 'cache:warm').
	 * @param  string  $commandDescription  One-line description from the command map.
	 * @param  array   $signature           Command signature array.
	 * @return string  Complete help text (no trailing newline - BaseCommand adds PHP_EOL).
	 */
	public static function format(string $commandName, string $commandDescription, array $signature): string {
		$argDefs = $signature['arguments'] ?? [];
		$optDefs = $signature['options'] ?? [];
		$lines   = [];

		// -- 1. Header ----------------------------------------------------

		if ($commandDescription !== '') {
			$lines[] = "{$commandName} - {$commandDescription}";
		} else {
			$lines[] = $commandName;
		}

		// -- 2. Usage line ------------------------------------------------

		$lines[] = '';
		$lines[] = 'Usage:';
		$lines[] = '  ' . self::buildUsageLine($commandName, $argDefs, $optDefs);

		// -- 3. Arguments section -----------------------------------------

		if ($argDefs !== []) {
			$lines[] = '';
			$lines[] = 'Arguments:';

			$argRows = [];
			foreach ($argDefs as $name => $def) {
				$label = $name;
				$desc  = self::buildArgDescription($def);
				$argRows[] = [$label, $desc];
			}

			self::appendAligned($lines, $argRows);
		}

		// -- 4. Options section -------------------------------------------

		$lines[] = '';
		$lines[] = 'Options:';

		$optRows = [];
		foreach ($optDefs as $name => $def) {
			$label = self::buildOptionLabel($name, $def);
			$desc  = self::buildOptionDescription($name, $def);
			$optRows[] = [$label, $desc];
		}

		// Always append --help as the last option.
		$optRows[] = ['-h, --help', 'Show this help'];

		self::appendAligned($lines, $optRows);

		return \implode(\PHP_EOL, $lines);
	}


	/**
	 * Generate the single-line usage summary.
	 *
	 * Shown alongside parse errors so the user sees correct invocation
	 * without needing to run --help separately.
	 *
	 * @param  string  $commandName  Command name.
	 * @param  array   $signature    Command signature array.
	 * @return string  Usage line (e.g. "Usage: import <file> [--dry-run] [--batch=<int>]").
	 */
	public static function usage(string $commandName, array $signature): string {
		$argDefs = $signature['arguments'] ?? [];
		$optDefs = $signature['options'] ?? [];

		return 'Usage: ' . self::buildUsageLine($commandName, $argDefs, $optDefs);
	}







	// ----------------------------------------------------------------
	// Usage line builder
	// ----------------------------------------------------------------

	/**
	 * Build the usage line content (without "Usage: " prefix).
	 *
	 * @return string  e.g. "import <file> [--dry-run] [--batch=<int>]"
	 */
	private static function buildUsageLine(string $commandName, array $argDefs, array $optDefs): string {
		$parts = [$commandName];

		foreach ($argDefs as $name => $def) {
			$required = !empty($def['required']);
			$parts[] = $required ? "<{$name}>" : "[<{$name}>]";
		}

		foreach ($optDefs as $name => $def) {
			$parts[] = self::buildUsageToken($name, $def);
		}

		return \implode(' ', $parts);
	}


	/**
	 * Build one option token for the usage line.
	 *
	 * Examples: [--dry-run], --email=<string>, [--batch=<int>]
	 */
	private static function buildUsageToken(string $name, array $def): string {
		$type     = self::resolveType($def);
		$required = !empty($def['required']);

		if ($type === 'bool') {
			$token = "--{$name}";
		} else {
			$token = "--{$name}=<{$type}>";
		}

		return $required ? $token : "[{$token}]";
	}






	// ----------------------------------------------------------------
	// Argument description
	// ----------------------------------------------------------------

	/**
	 * Build the description column for an argument.
	 */
	private static function buildArgDescription(array $def): string {
		$parts = [];

		$desc = (string)($def['description'] ?? '');
		if ($desc !== '') {
			$parts[] = $desc;
		}

		$tags = [];
		if (!empty($def['required'])) {
			$tags[] = 'required';
		}
		if (\array_key_exists('default', $def) && $def['default'] !== null) {
			$tags[] = 'default: ' . self::formatDefault($def['default']);
		}
		if ($tags !== []) {
			$parts[] = '(' . \implode(', ', $tags) . ')';
		}

		return \implode('  ', $parts);
	}






	// ----------------------------------------------------------------
	// Option label and description
	// ----------------------------------------------------------------

	/**
	 * Build the label column for an option (short + long + value hint).
	 *
	 * Examples: --dry-run, -b, --batch=<int>, -f, --format=<string>
	 */
	private static function buildOptionLabel(string $name, array $def): string {
		$type    = self::resolveType($def);
		$short   = (isset($def['short']) && \is_string($def['short']) && \strlen($def['short']) === 1) ? $def['short'] : null;
		$hasVal  = ($type !== 'bool');

		$longPart = $hasVal ? "--{$name}=<{$type}>" : "--{$name}";

		if ($short !== null) {
			$shortPart = $hasVal ? "-{$short}" : "-{$short}";
			return "{$shortPart}, {$longPart}";
		}

		// Indent to align with labels that have a short alias.
		return "    {$longPart}";
	}


	/**
	 * Build the description column for an option.
	 */
	private static function buildOptionDescription(string $name, array $def): string {
		$type  = self::resolveType($def);
		$parts = [];

		$desc = (string)($def['description'] ?? '');
		if ($desc !== '') {
			$parts[] = $desc;
		}

		$tags = [];

		if (!empty($def['required'])) {
			$tags[] = 'required';
		}

		if (\array_key_exists('default', $def) && $def['default'] !== null) {
			if ($type !== 'bool' || $def['default'] !== false) {
				$tags[] = 'default: ' . self::formatDefault($def['default']);
			}
		}

		if (isset($def['allowed']) && \is_array($def['allowed']) && $def['allowed'] !== []) {
			$tags[] = 'values: ' . \implode(', ', \array_map(static fn($v) => (string)$v, $def['allowed']));
		}

		if ($type === 'bool') {
			$tags[] = 'negation: --no-' . $name;
		}

		if ($tags !== []) {
			$parts[] = '(' . \implode(', ', $tags) . ')';
		}

		return \implode('  ', $parts);
	}







	// ----------------------------------------------------------------
	// Formatting helpers
	// ----------------------------------------------------------------

	/**
	 * Append label/description rows to the output lines with consistent alignment.
	 *
	 * @param  string[]  $lines  Output lines array (modified in place).
	 * @param  array     $rows   Array of [label, description] pairs.
	 */
	private static function appendAligned(array &$lines, array $rows): void {
		$maxLabel = 0;
		foreach ($rows as [$label]) {
			$len = \strlen($label);
			if ($len > $maxLabel) {
				$maxLabel = $len;
			}
		}

		$pad = $maxLabel + 4;

		foreach ($rows as [$label, $desc]) {
			if ($desc !== '') {
				$lines[] = '  ' . \str_pad($label, $pad) . $desc;
			} else {
				$lines[] = '  ' . $label;
			}
		}
	}


	/**
	 * Format a default value for display.
	 */
	private static function formatDefault(mixed $value): string {
		if (\is_bool($value)) {
			return $value ? 'true' : 'false';
		}
		if (\is_int($value)) {
			return (string)$value;
		}
		return (string)$value;
	}


	/**
	 * Resolve the canonical type string from a definition.
	 */
	private static function resolveType(array $def): string {
		return match ($def['type'] ?? 'string') {
			'bool', 'boolean' => 'bool',
			'int', 'integer'  => 'int',
			default           => 'string',
		};
	}

}
