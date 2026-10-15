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

namespace CitOmni\Kernel\Service;

use CitOmni\Kernel\App;

/**
 * BaseService: Minimal, cross-mode superclass for CitOmni services.
 *
 * Gives every service the application container, the options of its service map entry and a
 * lifecycle hook, with no reflection beyond method_exists, no DI attributes and no hidden
 * magic. Resolving a service id to a class and its options belongs to the App; services read
 * runtime config from the App's cfg as they need it.
 *
 * Rules:
 * - Do not catch exceptions unless absolutely necessary; let the global error handler log them.
 */
abstract class BaseService {
	
	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Arbitrary service options (constructor-provided). */
	protected array $options;

	/**
	 * Inject the application container and optional options bag.
	 *
	 * Setup that every instance needs belongs in an optional protected function init(): void,
	 * not in a constructor of the subclass. Keep init() minimal: Cheap setup only, no heavy I/O;
	 * it runs eagerly during construction.
	 *
	 * Behavior:
	 * - Calls init() once, after $app and $options are set, when the subclass declares it.
	 *
	 * @param  App  $app  Application instance (shared container/config).
	 * @param  array<string,mixed>  $options  Optional service-specific settings.
	 * @see \CitOmni\Kernel\App::__get()  How a service map entry becomes these arguments.
	 */
	public function __construct(App $app, array $options = []) {		
		
		$this->app = $app;  // Expose services (e.g., $this->app->cfg, $this->app->log, ...)
		
		$this->options = $options;  // Store per-service tunables (local only, no global config impact)

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}
}
