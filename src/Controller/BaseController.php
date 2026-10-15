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

namespace CitOmni\Kernel\Controller;

use CitOmni\Kernel\App;

/**
 * BaseController: Minimal transport adapter base for HTTP controllers.
 *
 * Gives every controller the application container, the per-route configuration it was
 * created with and a lightweight lifecycle hook. Routing, request and response handling and
 * template rendering belong to the HTTP layer's services; domain decisions belong to
 * Operations, Policies and Repositories.
 *
 * Rules:
 * - Catch no exceptions in this base; failures bubble to the global error handler.
 */
abstract class BaseController {
	
	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Route-specific configuration for the controller. */
	protected array $routeConfig = [];


	/**
	 * Inject the application container and optional per-route config.
	 *
	 * Setup and guards that every action needs belong in an optional protected function
	 * init(): void, not in a constructor of the subclass. Keep init() lightweight: No heavy
	 * I/O, no heavy allocations.
	 *
	 * Behavior:
	 * - Calls init() once, after $app and $routeConfig are set, when the subclass declares it.
	 *
	 * Notes:
	 * - Manual instantiation is mainly useful in tests.
	 *
	 * @param  App  $app  Application instance (shared container/config).
	 * @param  array<string,mixed>  $routeConfig  Optional route-specific configuration.
	 * @see \CitOmni\Http\Service\Router  Which route keys reach $routeConfig.
	 */
	public function __construct(App $app, array $routeConfig = []) {
		
		$this->app = $app;			// Expose services (e.g., $this->app->cfg, $this->app->db, ...)
		
		$this->routeConfig = $routeConfig;	// Templating hints from the route (template_file, template_layer)

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}


	/**
	 * Return the route configuration this controller was created with.
	 *
	 * @return array<string,mixed>  Current route configuration.
	 */
	public function getRouteConfig(): array {
		return $this->routeConfig;
	}


}
