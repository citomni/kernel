<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Kernel - Deterministic core for high-performance CitOmni apps.
 * Source:  https://github.com/citomni/kernel
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Kernel\Controller;

use CitOmni\Kernel\App;

/**
 * BaseController: Minimal, cross-mode base for controllers (HTTP/CLI).
 *
 * Responsibilities:
 * - Expose the application container ($app) for services and config.
 * - Hold optional per-route configuration ($routeConfig).
 * - Provide a lightweight lifecycle hook via protected init().
 *
 * Behavior:
 * - Constructor stores $app and $routeConfig.
 * - If the child defines init(), it is called automatically (method_exists guard).
 * - No HTTP specifics here; HTTP packages may extend or compose this base.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (read-only access to services: $this->app->id).
 *
 * Configuration keys:
 * - $routeConfig (array) — route-level inputs (e.g., template_file, template_layer).
 *
 * Error handling:
 * - No exceptions are caught; failures bubble to the global error handler.
 *
 * Typical usage:
 *
 *   class HomeController extends BaseController {
 *       protected function init(): void {
 *           // lightweight setup only (no heavy I/O)
 *       }
 *       public function index(): void {
 *           $this->app->view->render('public/index.html', 'citomni/http', []);
 *       }
 *   }
 *
 * Examples:
 *   // Access deep config (read-only wrapper):
 *   $tz = $this->app->cfg->locale->timezone ?? 'UTC';
 *
 *   // Access route config (raw array):
 *   $tpl = $this->routeConfig['template_file'] ?? null;
 *
 * Failure:
 *   // Calling an action that does not exist should be handled by the router;
 *   // this base does not attempt to catch such errors.
 *
 */
abstract class BaseController {
	
	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Route-specific configuration for the controller. */
	protected array $routeConfig = [];

	/**
	 * Inject the application container and optional per-route config.
	 *
	 * Typical usage:
	 *   $controller = new SomeController($app, ['template' => 'home.php']);
	 *
	 * @param App $app Application instance (shared container/config).
	 * @param array<string,mixed> $routeConfig Optional route-specific configuration.
	 */
	public function __construct(App $app, array $routeConfig = []) {
		
		$this->app = $app;			// Expose services (e.g., $this->app->cfg, $this->app->db, ...)
		
		$this->routeConfig = $routeConfig;	// Route-specific inputs (e.g., template, layer, flags)


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
	 * Accessor for the route configuration array (read-only contract).
	 *
	 * @return array<string,mixed> Current route configuration.
	 */
	public function getRouteConfig(): array {
		return $this->routeConfig;
	}
}
