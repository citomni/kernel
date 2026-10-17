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
 * BaseCommand: Transport adapter base for CLI commands.
 *
 * Subclasses declare the input they accept and implement the command. This base owns the
 * parse-validate-dispatch pipeline and provides typed accessors and IO helpers. Domain work
 * belongs to the Operations, Repositories and Services that a command calls.
 *
 * Rules:
 * - Return only SUCCESS, FAILURE or USAGE as an exit code.
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

	/** Exit code: The command did what it was asked to do. */
	public const SUCCESS = 0;

	/** Exit code: A domain or runtime failure that the command handles. */
	public const FAILURE = 1;

	/** Exit code: A usage error, reported before the command delegates any work. */
	public const USAGE   = 2;

	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Options of the command's entry in the command map. */
	protected array $options;

	/** Command name as registered in the command map. */
	protected string $commandName;

	/** Command description as registered in the command map. */
	protected string $commandDescription;

	/** @var array<string,mixed> Parsed arguments by name; empty until run() has parsed argv. */
	private array $parsedArgs = [];

	/** @var array<string,mixed> Parsed options by name; empty until run() has parsed argv. */
	private array $parsedOpts = [];

	/** Whether stdout supports ANSI colors; null until detected. */
	private ?bool $stdoutColor = null;

	/** Whether stderr supports ANSI colors; null until detected. */
	private ?bool $stderrColor = null;

	/**
	 * Inject the application container and the command's entry from the command map.
	 *
	 * Setup that every instance needs belongs in an optional protected function init(): void,
	 * not in a constructor of the subclass. Keep init() lightweight: Cheap setup only, no heavy
	 * I/O, no unnecessary allocations.
	 *
	 * Behavior:
	 * - Calls init() once, after the properties are set, when the subclass declares it.
	 *
	 * Notes:
	 * - init() runs before run(), so parsed arguments and options are not available there.
	 *
	 * @param  App                  $app                 Application instance.
	 * @param  string               $commandName         Command name from the command map.
	 * @param  string               $commandDescription  Command description from the command map.
	 * @param  array<string,mixed>  $options             Command options from the command map.
	 * @see \CitOmni\Cli\Service\Runner  How a command map entry becomes these arguments.
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
	 * Parse argv, validate the input and delegate to execute().
	 *
	 * The single entry point of every command. It answers help requests and usage errors
	 * itself, so execute() only runs with input that the parser accepted.
	 *
	 * Behavior:
	 * - When CITOMNI_ENVIRONMENT is 'dev': Validates the signature first and fails fast on
	 *   developer errors. Prints them to stderr and returns USAGE.
	 * - Intercepts --help / -h before parsing: Prints the help text to stdout and returns SUCCESS.
	 * - On parse/validation failure: Prints error + usage to stderr, returns USAGE.
	 * - On success: Populates parsed args/opts, calls execute() and returns its exit code.
	 *
	 * @param  array  $argv  Full $argv from the process (includes binary and command name).
	 * @return int  Exit code (SUCCESS, FAILURE, or USAGE).
	 * @see \CitOmni\Kernel\Command\ArgvParser::validateSignature()  The developer errors that dev mode reports.
	 * @see \CitOmni\Kernel\Command\ArgvParser::parse()  What counts as a parse or validation error.
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
	 * @return array{arguments?: array<string,array<string,mixed>>, options?: array<string,array<string,mixed>>}  Empty when the command takes no arguments or options.
	 * @see \CitOmni\Kernel\Command\ArgvParser::validateSignature()  The rules a signature must meet; checked in dev mode only.
	 */
	protected function signature(): array {
		return [];
	}


	/**
	 * Run the command and return its exit code.
	 *
	 * run() calls it only after argv has been parsed and validated against signature(), so an
	 * implementation sees only input that the parser accepted.
	 *
	 * Behavior:
	 * - SUCCESS when the command did what it was asked to do.
	 * - USAGE for a usage error that the parser cannot catch and that the command itself
	 *   detects before it delegates, for example an id below 1.
	 * - FAILURE for every other failure the command handles, including a value that a
	 *   Repository, Operation or Service refuses.
	 *
	 * @return int  Exit code: self::SUCCESS, self::FAILURE or self::USAGE.
	 */
	abstract protected function execute(): int;







	// ----------------------------------------------------------------
	// Argument accessors
	// ----------------------------------------------------------------

	/**
	 * Get a positional argument value (raw).
	 *
	 * @param  string  $name     Argument name as declared in signature().
	 * @param  mixed   $default  Returned when the parsed arguments have no entry for $name.
	 * @return mixed
	 * @see \CitOmni\Kernel\Command\ArgvParser::parse()  Which entries the parsed arguments have, signature defaults included.
	 */
	protected function arg(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedArgs) ? $this->parsedArgs[$name] : $default;
	}


	/**
	 * Get a positional argument as string. Throws on null.
	 *
	 * @param  string  $name  Argument name as declared in signature().
	 * @return string
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
	 * @param  string  $name  Argument name as declared in signature().
	 * @return int
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
	 * @param  mixed   $default  Returned when the parsed options have no entry for $name.
	 * @return mixed
	 * @see \CitOmni\Kernel\Command\ArgvParser::parse()  Which entries the parsed options have, signature defaults included.
	 */
	protected function opt(string $name, mixed $default = null): mixed {
		return \array_key_exists($name, $this->parsedOpts) ? $this->parsedOpts[$name] : $default;
	}


	/**
	 * Get an option as string. Throws on null.
	 *
	 * @param  string  $name  Option name as declared in signature().
	 * @return string
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
	 * @param  string  $name  Option name as declared in signature().
	 * @return int
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
	 * @param  string  $name  Option name as declared in signature().
	 * @return bool
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
	 *
	 * @param  string  $line  Text without the line ending; PHP_EOL is appended.
	 * @return void
	 */
	protected function stdout(string $line): void {
		\fwrite(\STDOUT, $line . \PHP_EOL);
	}


	/**
	 * Write a line to stderr.
	 *
	 * @param  string  $line  Text without the line ending; PHP_EOL is appended.
	 * @return void
	 */
	protected function stderr(string $line): void {
		\fwrite(\STDERR, $line . \PHP_EOL);
	}


	/**
	 * Write an informational message to stdout.
	 *
	 * Behavior:
	 * - Blue when stdout supports ANSI colors, plain text otherwise.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 * @see \CitOmni\Kernel\Command\BaseCommand::supportsColor()  When a stream supports ANSI colors.
	 */
	// protected function info(string $message): void {
		// $this->stdout($this->color('34', '[info]', false) . " {$message}");
	// }
	protected function info(string $message): void {
		$this->stdout($this->color('34', $message, false));
	}
	

	/**
	 * Write a success message to stdout.
	 *
	 * Behavior:
	 * - Green when stdout supports ANSI colors, plain text otherwise.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 * @see \CitOmni\Kernel\Command\BaseCommand::supportsColor()  When a stream supports ANSI colors.
	 */
	// protected function success(string $message): void {
		// $this->stdout($this->color('32', '[ok]', false) . " {$message}");
	// }
	protected function success(string $message): void {
		$this->stdout($this->color('32', $message, false));
	}
	

	/**
	 * Write a warning message to stderr.
	 *
	 * Behavior:
	 * - Yellow when stderr supports ANSI colors, plain text otherwise.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 * @see \CitOmni\Kernel\Command\BaseCommand::supportsColor()  When a stream supports ANSI colors.
	 */
	// protected function warning(string $message): void {
		// $this->stderr($this->color('33', '[warning]', true) . " {$message}");
	// }
	protected function warning(string $message): void {
		$this->stderr($this->color('33', $message, true));
	}	
	

	/**
	 * Write an error message to stderr.
	 *
	 * Behavior:
	 * - Red when stderr supports ANSI colors, plain text otherwise.
	 *
	 * @param  string  $message  Text without the line ending.
	 * @return void
	 * @see \CitOmni\Kernel\Command\BaseCommand::supportsColor()  When a stream supports ANSI colors.
	 */
	// protected function error(string $message): void {
		// $this->stderr($this->color('31', '[error]', true) . " {$message}");
	// }
	protected function error(string $message): void {
		$this->stderr($this->color('31', $message, true));
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
