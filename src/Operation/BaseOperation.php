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

namespace CitOmni\Kernel\Operation;

use CitOmni\Kernel\App;

/**
 * BaseOperation: Minimal, transport-agnostic base for Operation classes.
 *
 * Responsibilities:
 * - Expose the application container ($app) for config and services.
 * - Provide a lightweight lifecycle hook via protected init().
 * - Standardize the constructor contract for Operation classes.
 *
 * Behavior:
 * - Constructor stores $app.
 * - If the child defines init(), it is called automatically (method_exists guard).
 * - No transport concerns live here (no HTTP/CLI shaping, no request/response logic).
 * - No persistence abstraction is introduced here; Operations remain SQL-free by contract.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (read-only access to services/config via $this->app->id).
 *
 * Architectural role:
 * - Operation owns transport-agnostic orchestration and state-transition logic.
 * - Operation may call services and repositories through explicit App access.
 * - Operation must not shape transport output and must not execute SQL directly.
 *
 * Error handling:
 * - No exceptions are caught; failures bubble to the global error handler.
 *
 * Typical usage:
 *
 *   final class AuthenticateUser extends BaseOperation {
 *       protected function init(): void {
 *           // Keep setup lightweight and deterministic.
 *       }
 *
 *       public function run(array $input): array {
 *           $this->app->log->write('info', 'auth', 'authentication_started', [
 *               'email' => $input['email'] ?? null,
 *           ]);
 *
 *           $repository = new \App\Repository\UserRepository($this->app);
 *           $user = $repository->findByEmail((string)($input['email'] ?? ''));
 *
 *           return [
 *               'ok' => $user !== null,
 *               'user' => $user,
 *           ];
 *       }
 *   }
 *
 * Notes:
 * - Operations are instantiated explicitly by Controllers/Commands via new ...($this->app).
 * - Keep init() minimal; it runs eagerly on construction.
 * - Operation is intentionally not a service-map singleton.
 */
abstract class BaseOperation {

	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/**
	 * Inject the application container.
	 *
	 * Typical usage:
	 *   $operation = new AuthenticateUser($app);
	 *
	 * @param App $app Application instance (shared container/config).
	 */
	public function __construct(App $app) {

		$this->app = $app;	// Expose services/config with explicit, deterministic wiring.

		/*
		 * Lifecycle hook (optional).
		 * Behavior:
		 * - If the child class defines protected init(): void, call it now.
		 * Notes:
		 * - Keep it lightweight (no heavy I/O, no unnecessary allocations).
		 */
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}
}
