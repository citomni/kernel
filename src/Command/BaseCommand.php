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

use CitOmni\Kernel\App;

/**
 * Base class for all CLI commands.
 *
 * Subclasses declare accepted input via signature() and implement
 * their logic in execute(). BaseCommand owns the parse-validate-dispatch
 * pipeline and provides typed accessors and IO helpers.
 *
 * Behavior:
 * - run() is final: it parses argv, validates input, and calls execute().
 * - --help / -h is intercepted before parsing - never reaches execute().
 * - Parse/validation errors print a message + usage and return USAGE (2).
 * - execute() returns SUCCESS (0) or FAILURE (1) for runtime outcomes.
 * - In dev mode, the signature is validated for developer errors before parsing.
 * - Output helpers use ANSI colors when the target stream is a TTY, or when
 *   the FORCE_COLOR environment variable is set (any value except '0' and '').
 *
 * Typical usage:
 *   final class ImportCommand extends BaseCommand {
 *       protected function signature(): array {
 *           return [
 *               'arguments' => [
 *                   'file' => ['required' => true, 'description' => 'CSV file to import'],
 *               ],
 *               'options' => [
 *                   'dry-run' => ['type' => 'bool', 'description' => 'Validate without writing'],
 *                   'batch'   => ['short' => 'b', 'type' => 'int', 'default' => 100, 'description' => 'Batch size'],
 *               ],
 *           ];
 *       }
 *       protected function execute(): int {
 *           $file   = $this->argString('file');
 *           $dryRun = $this->getBool('dry-run');
 *           $batch  = $this->getInt('batch');
 *           // ...
 *           return self::SUCCESS;
 *       }
 *   }
 */
abstract class BaseCommand {

	public const SUCCESS = 0;
	public const FAILURE = 1;
	public const USAGE   = 2;

	protected App $app;
	protected array $options;
	protected string $commandName;
	protected string $commandDescription;
	private array $parsedArgs = [];
	private array $parsedOpts = [];
	private ?bool $stdoutColor = null;
	private ?bool $stderrColor = null;

	/**
	 * @param  App     $app                 Application instance.
	 * @param  string  $commandName         Command name from the command map (set by Runner).
	 * @param  string  $commandDescription  Command description from the command map (set by Runner).
	 * @param  array   $options             Command options from the command map (set by Runner).
	 */
	public function __construct(App $app, string $commandName, string $commandDescription, array $options = []) {
		$this->app = $app;
		$this->commandName = $commandName;
		$this->commandDescription = $commandDescription;
		$this->options = $options;
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}






	// ----------------------------------------------------------------
	// Template method: parse -> validate -> execute
	// ----------------------------------------------------------------

	/**
	 * Entry point called by Runner. Parses argv, validates input, and
	 * delegates to execute() on success.
	 *
	 * Behavior:
	 * - In dev mode: validates the signature first and fails fast on developer errors.
	 * - Intercepts --help / -h before parsing.
	 * - On parse/validation failure: prints error + usage to stderr, returns USAGE.
	 * - On success: populates parsed args/opts and calls execute().
	 *
	 * @param  array  $argv  Full $argv from the process (includes binary and command name).
	 * @return int  Exit code (SUCCESS, FAILURE, or USAGE).
	 */
	final public function run(array $argv = []): int {
		$sig    = $this->signature();
		$tokens = \array_slice($argv, 2);

		// -- 1. Dev-only signature validation ------------------------------

		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT === 'dev') {
			$sigErrors = ArgvParser::validateSignature($sig);
			if ($sigErrors !== []) {
				$this->error("Invalid signature for command '{$this->commandName}':");
				foreach ($sigErrors as $sigErr) {
					$this->stderr("  - {$sigErr}");
				}
				return self::USAGE;
			}
		}

		// -- 2. Help interception -----------------------------------------

		if (ArgvParser::wantsHelp($tokens)) {
			$this->stdout(HelpFormatter::format(
				$this->commandName,
				$this->commandDescription,
				$sig,
			));
			return self::SUCCESS;
		}

		// -- 3. Parse and validate ----------------------------------------

		$result = ArgvParser::parse($tokens, $sig);

		if ($result['error'] !== null) {
			$this->error($result['error']);
			$this->stderr('');
			$this->stderr(HelpFormatter::usage($this->commandName, $sig));
			return self::USAGE;
		}

		$this->parsedArgs = $result['args'];
		$this->parsedOpts = $result['opts'];

		// -- 4. Delegate to command implementation -------------------------

		return $this->execute();
	}







	// ----------------------------------------------------------------
	// Subclass contract
	// ----------------------------------------------------------------

	/**
	 * Declare accepted arguments and options.
	 *
	 * Override to define the command's input contract. Commands with no
	 * arguments or options can omit this method.
	 *
	 * Signature format (stable DSL):
	 *   [
	 *       'arguments' => [
	 *           'name' => [
	 *               'description' => string,     // for help text
	 *               'required'    => bool,        // default: false; required must precede optional
	 *               'default'     => mixed,       // default: null; coerced to declared type
	 *               'type'        => string,      // 'string'|'int', default: 'string'
	 *                                             // bool is not supported for arguments - use options for flags
	 *           ],
	 *       ],
	 *       'options' => [
	 *           'name' => [
	 *               'short'       => string,      // single char, optional; 'h' is reserved for --help
	 *               'type'        => string,       // 'string'|'bool'|'int', default: 'string'
	 *               'description' => string,       // for help text
	 *               'required'    => bool,          // default: false
	 *               'default'     => mixed,         // default: null (string/int) or false (bool); coerced to declared type
	 *               'allowed'     => array,         // optional value whitelist; values must match declared PHP type
	 *           ],
	 *       ],
	 *   ]
	 *
	 * @return array  Signature array. Empty = no arguments or options.
	 */
	protected function signature(): array {
		return [];
	}


	/**
	 * Command implementation. Called after argv is parsed and validated.
	 *
	 * @return int  Exit code (use self::SUCCESS / self::FAILURE).
	 */
	abstract protected function execute(): int;







	// ----------------------------------------------------------------
	// Argument accessors
	// ----------------------------------------------------------------

	/**
	 * Get a positional argument value (raw).
	 *
	 * @param  string  $name     Argument name as declared in signature().
	 * @param  mixed   $default  Fallback if the argument was not provided.
	 * @return mixed
	 */
	protected function arg(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedArgs) ? $this->parsedArgs[$name] : $default;
	}


	/**
	 * Get a positional argument as string. Throws on null.
	 *
	 * @throws \RuntimeException  If the value is null.
	 */
	protected function argString(string $name): string {
		$v = $this->arg($name);
		if ($v === null) {
			throw new \RuntimeException("Argument '{$name}' is null; no value provided and no default in signature.");
		}
		return (string)$v;
	}


	/**
	 * Get a positional argument as int. Throws on null.
	 *
	 * @throws \RuntimeException  If the value is null.
	 */
	protected function argInt(string $name): int {
		$v = $this->arg($name);
		if ($v === null) {
			throw new \RuntimeException("Argument '{$name}' is null; no value provided and no default in signature.");
		}
		return (int)$v;
	}








	// ----------------------------------------------------------------
	// Option accessors
	// ----------------------------------------------------------------

	/**
	 * Get an option value (raw).
	 *
	 * @param  string  $name     Option name as declared in signature().
	 * @param  mixed   $default  Fallback if the option was not provided and has no signature default.
	 * @return mixed
	 */
	protected function opt(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedOpts) ? $this->parsedOpts[$name] : $default;
	}


	/**
	 * Get an option as string. Throws on null.
	 *
	 * @throws \RuntimeException  If the value is null (option not provided and no default).
	 */
	protected function getString(string $name): string {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null; no value provided and no default in signature.");
		}
		return (string)$v;
	}


	/**
	 * Get an option as int. Throws on null.
	 *
	 * @throws \RuntimeException  If the value is null.
	 */
	protected function getInt(string $name): int {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null; no value provided and no default in signature.");
		}
		return (int)$v;
	}


	/**
	 * Get an option as bool. Throws on null.
	 *
	 * Notes:
	 * - Bool options default to false when not provided (set by the parser),
	 *   so null only occurs if the signature explicitly sets 'default' => null
	 *   (which is a design defect caught by the signature validator).
	 * - Uniform with getString()/getInt(): null means defective signature.
	 *
	 * @throws \RuntimeException  If the value is null (defective signature).
	 */
	protected function getBool(string $name): bool {
		$v = $this->opt($name);
		if ($v === null) {
			throw new \RuntimeException("Option --{$name} is null (defective signature - bool options must default to false).");
		}
		return (bool)$v;
	}








	// ----------------------------------------------------------------
	// IO helpers
	// ----------------------------------------------------------------

	/**
	 * Write a line to stdout.
	 */
	protected function stdout(string $line): void {
		\fwrite(\STDOUT, $line . \PHP_EOL);
	}

	/**
	 * Write a line to stderr.
	 */
	protected function stderr(string $line): void {
		\fwrite(\STDERR, $line . \PHP_EOL);
	}

	/**
	 * Write an informational message to stdout.
	 */
	protected function info(string $message): void {
		$this->stdout($this->color('34', '[info]', false) . " {$message}");
	}

	/**
	 * Write a success message to stdout.
	 */
	protected function success(string $message): void {
		$this->stdout($this->color('32', '[ok]', false) . " {$message}");
	}

	/**
	 * Write a warning message to stderr.
	 */
	protected function warning(string $message): void {
		$this->stderr($this->color('33', '[warning]', true) . " {$message}");
	}

	/**
	 * Write an error message to stderr.
	 */
	protected function error(string $message): void {
		$this->stderr($this->color('31', '[error]', true) . " {$message}");
	}








	// ----------------------------------------------------------------
	// Color support
	// ----------------------------------------------------------------

	/**
	 * Wrap a string in ANSI color codes if the target stream supports it.
	 *
	 * @param  string  $code    ANSI color code (e.g. '31' for red).
	 * @param  string  $text    Text to colorize.
	 * @param  bool    $stderr  Whether the output targets stderr.
	 * @return string  Colorized or plain text.
	 */
	private function color(string $code, string $text, bool $stderr): string {
		if (!$this->supportsColor($stderr)) {
			return $text;
		}
		return "\033[{$code}m{$text}\033[0m";
	}


	/**
	 * Detect whether the given output stream supports ANSI colors.
	 *
	 * Behavior:
	 * - Returns true if FORCE_COLOR env is set to a truthy value (not '0' or '').
	 * - Returns true if the stream is a TTY (interactive terminal).
	 * - Returns false otherwise (pipes, file redirects, non-interactive).
	 *
	 * @param  bool  $stderr  Whether to check stderr (true) or stdout (false).
	 * @return bool
	 */
	private function supportsColor(bool $stderr): bool {
		if ($stderr) {
			return $this->stderrColor ??= self::detectColor(\STDERR);
		}
		return $this->stdoutColor ??= self::detectColor(\STDOUT);
	}


	/**
	 * Detect color support for a specific stream.
	 *
	 * FORCE_COLOR semantics: the variable is considered truthy when it is
	 * present in the environment and its value is neither '' nor '0'.
	 * This matches the de facto force-color convention used by CI systems.
	 *
	 * @param  mixed  $stream  Stream resource (STDOUT or STDERR).
	 * @return bool
	 */
	private static function detectColor(mixed $stream): bool {
		$force = \getenv('FORCE_COLOR');
		if ($force !== false && $force !== '' && $force !== '0') {
			return true;
		}
		return \function_exists('stream_isatty') && \stream_isatty($stream);
	}

}
