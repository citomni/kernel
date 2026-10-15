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
 * An Operation owns transport-agnostic orchestration and state-transition logic for one
 * concrete domain action. It reaches services and repositories through explicit App access;
 * SQL belongs to Repositories, and shaping the result for HTTP or CLI belongs to the Controller
 * or Command that calls it. Operations are created explicitly by their callers and are
 * intentionally not service-map singletons.
 *
 * Rules:
 * - Add no public method other than execute(...) and an optional preflight(...), which takes
 *   the same domain inputs, changes nothing and reports what execute() would do or refuse.
 * - Return documented, domain-shaped arrays, with a status when the outcome can vary.
 * - Never shape transport output or return transport objects, and never execute SQL directly.
 * - Catch no exceptions in this base; failures bubble to the global error handler.
 */
abstract class BaseOperation {

	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/**
	 * Inject the application container.
	 *
	 * Setup that every instance needs belongs in an optional protected function init(): void,
	 * not in a constructor of the subclass. Keep init() lightweight and deterministic: No heavy
	 * I/O, no unnecessary allocations.
	 *
	 * Behavior:
	 * - Calls init() once, after $app is set, when the subclass declares it.
	 *
	 * @param  App  $app  Application instance (shared container/config).
	 */
	public function __construct(App $app) {

		$this->app = $app;	// Expose services/config with explicit, deterministic wiring.

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}
}
