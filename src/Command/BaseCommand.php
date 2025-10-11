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
 * BaseCommand: Minimal, cross-mode base for CLI command services.
 *
 * Responsibilities:
 * - Expose the application container ($app) for services and config.
 * - Hold optional per-command options ($options) defined by the service map.
 * - Provide a lightweight lifecycle hook via protected init().
 * - Define a stable execution contract via abstract run(array $argv): int.
 *
 * Behavior:
 * - Constructor stores $app and $options.
 * - If the child defines init(), it is called automatically (method_exists guard).
 * - No argument parsing here; runners pass raw $argv and consume the int return code.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (read-only access to services: $this->app->id).
 * - A CLI runner (e.g., in citomni/cli) that resolves a command service and calls run().
 *
 * Configuration keys:
 * - $options (array) - service-map options set under providers/app config
 *   (e.g., ['default_name' => 'world'] or feature flags).
 *
 * Error handling:
 * - No exceptions are caught here; failures bubble to the global error handler.
 *
 * Typical usage:
 *
 *   // Service map (provider):
 *   public const MAP_CLI = [
 *       'hello' => \CitOmni\MyPkg\Command\HelloCommand::class,
 *   ];
 *
 *   // Command implementation:
 *   final class HelloCommand extends BaseCommand {
 *       protected function init(): void {
 *           // lightweight setup only (no heavy I/O)
 *       }
 *       public function run(array $argv = []): int {
 *           $name = $argv[0] ?? ($this->options['default_name'] ?? 'world');
 *           $line = $this->app->greeting->make($name);
 *           \fwrite(\STDOUT, $line . \PHP_EOL);
 *           return 0;
 *       }
 *   }
 *
 * Examples:
 *   // Access deep config (read-only wrapper):
 *   $tz = $this->app->cfg->locale->timezone ?? 'UTC';
 *
 *   // Access option with fallback:
 *   $flag = (bool)($this->options['dry_run'] ?? false);
 *
 * Failure:
 *   // A thrown \Throwable during run() is not intercepted here; your runner
 *   // may wrap execution if it wants friendlier diagnostics.
 */
abstract class BaseCommand {

	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Service-level options for this command. */
	protected array $options;

	/**
	 * Inject the application container and optional command options.
	 *
	 * Typical usage:
	 *   $cmd = new SomeCommand($app, ['default_name' => 'world']);
	 *
	 * @param App $app Application instance (shared container/config).
	 * @param array<string,mixed> $options Optional command/service options.
	 */
	public function __construct(App $app, array $options = []) {

		$this->app = $app;          // Expose services (e.g., $this->app->cfg, $this->app->db, ...)

		$this->options = $options;  // Command-level options from service map (last-wins merged)


		/*
		 * Lifecycle hook (optional).
		 * Behavior:
		 * - If the child class defines protected init(): void, call it now.
		 * Notes:
		 * - Keep it lightweight (no heavy I/O, no heavy allocations).
		 */
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}

	/**
	 * Execute the command.
	 *
	 * Contract:
	 * - Runners pass raw $argv (positional args only, parsing is runner-specific).
	 * - Return an integer exit code (0 = success, non-zero = failure).
	 *
	 * @param array<int,string> $argv Raw CLI arguments (runner-dependent).
	 * @return int Exit code (0 = success).
	 */
	abstract public function run(array $argv = []): int;
}
