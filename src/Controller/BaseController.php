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
 * Responsibilities:
 * - Expose the application container ($app) for services and config.
 * - Hold optional per-route configuration ($routeConfig).
 * - Provide a lightweight lifecycle hook via protected init().
 *
 * Behavior:
 * - Constructor stores $app and $routeConfig.
 * - If the child defines init(), it is called automatically (method_exists guard).
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (read-only access to services: $this->app->{serviceId}).
 *
 * Configuration keys:
 * - $routeConfig['template_file']  (string|null) — template path relative to the layer root.
 * - $routeConfig['template_layer'] (string|null) — layer slug (e.g., "app").
 *   The HTTP Router passes exactly these two keys, null when the route does not declare them.
 *
 * Error handling:
 * - No exceptions are caught; failures bubble to the global error handler.
 *
 * Notes:
 * - Rendering goes through the `tplEngine` service provided by citomni/http. Template
 *   references use the explicit "relative/path.html@layer" form; the layer must be a
 *   key in cfg->view->template_layers.
 *
 * Typical usage:
 *
 *   class HomeController extends BaseController {
 *       protected function init(): void {
 *           // lightweight setup only (no heavy I/O)
 *       }
 *       public function index(): void {
 *           $this->app->tplEngine->render(
 *               $this->routeConfig['template_file'] . '@' . $this->routeConfig['template_layer'],
 *               ['title' => 'Home']
 *           );
 *       }
 *   }
 *
 * Examples:
 *   // Render a fixed template reference directly:
 *   $this->app->tplEngine->render('public/index.html@app', ['title' => 'Home']);
 *
 *   // Access deep config (read-only wrapper):
 *   $tz = $this->app->cfg->locale->timezone ?? 'UTC';
 *
 *   // Access route config (raw array):
 *   $tpl = $this->routeConfig['template_file'] ?? null;
 *
 * Failure:
 *   // Calling an action that does not exist should be handled by the router;
 *   // this base does not attempt to catch such errors.
 */
abstract class BaseController {
	
	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Route-specific configuration for the controller. */
	protected array $routeConfig = [];


	/**
	 * Inject the application container and optional per-route config.
	 *
	 * Behavior:
	 * - Stores $app and $routeConfig on the instance.
	 * - Calls the child's protected init(): void afterwards, if defined.
	 *
	 * Notes:
	 * - The HTTP Router passes exactly `template_file` and `template_layer` from the
	 *   matched route (null when not declared); other route keys are not forwarded.
	 * - Manual instantiation is mainly useful in tests.
	 *
	 * Typical usage:
	 *   $controller = new HomeController($app, [
	 *       'template_file' => 'public/home.html',
	 *       'template_layer' => 'app',
	 *   ]);
	 *
	 * @param  App  $app  Application instance (shared container/config).
	 * @param  array<string,mixed>  $routeConfig  Optional route-specific configuration.
	 */
	public function __construct(App $app, array $routeConfig = []) {
		
		$this->app = $app;			// Expose services (e.g., $this->app->cfg, $this->app->db, ...)
		
		$this->routeConfig = $routeConfig;	// Templating hints from the route (template_file, template_layer)


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
