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

namespace CitOmni\Kernel\Service;

use CitOmni\Kernel\App;

/**
 * BaseService: Minimal, cross-mode superclass for CitOmni services.
 *
 * Responsibilities:
 * - Expose the application container ($app) for config/services.
 * - Hold per-service options ($options) from the services map.
 * - Provide a lifecycle hook via protected init().
 *
 * Behavior:
 * - Constructor stores $app and $options, then calls init() if defined.
 * - No reflection beyond method_exists; no DI attributes; no hidden magic.
 * - Keep init() lightweight (no heavy I/O); it runs eagerly on construction.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (resolves services via $this->app->id).
 *
 * Configuration keys:
 * - None here; services consume config from $this->app->cfg as needed.
 *
 * Error handling:
 * - Do not catch exceptions unless absolutely necessary; let the global handler log.
 *
 * Typical usage:
 *
 *   class MailService extends BaseService {
 *       protected function init(): void {
 *           $this->from = (string)($this->app->cfg->mail->from->email ?? '');
 *       }
 *       public function send(string $to, string $subject, string $body): void {
 *           // ...
 *       }
 *   }
 *
 * Service definition contract (from App):
 * // 'mail' => \Vendor\Package\Service\MailService::class
 * // 'mail' => ['class' => \Vendor\Package\Service\MailService::class, 'options' => ['async' => false]]
 *
 * Failure:
 * // Malformed config/services.php (wrong shape) is detected by App::buildServices()
 * // and results in \RuntimeException; BaseService does not catch it.
 * // (See App docs for merge order and precedence.)
 *
 */
abstract class BaseService {
	
	/** Application container (configuration, service map, utilities). */
	protected App $app;

	/** @var array<string,mixed> Arbitrary service options (constructor-provided). */
	protected array $options;

	/**
	 * Inject the application container and optional options bag.
	 *
	 * @param App $app Application instance (shared container/config).
	 * @param array<string,mixed> $options Optional service-specific settings.
	 */
	public function __construct(App $app, array $options = []) {		
		
		$this->app = $app;  // Expose services (e.g., $this->app->cfg, $this->app->log, ...)
		
		$this->options = $options;  // Store per-service tunables (local only, no global config impact)


		/*
		 * Lifecycle hook (optional).
		 * Behavior:
		 * - Call protected init(): void when implemented by the subclass.
		 * Notes:
		 * - Keep init() minimal; it runs eagerly during construction.
		 */
		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}
}
