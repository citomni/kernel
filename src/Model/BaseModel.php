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
 
namespace CitOmni\Kernel\Model;

use CitOmni\Kernel\App;

/**
 * BaseModel: Lightweight foundation for DB-backed domain models.
 *
 * Responsibilities:
 * - Provide access to the application container ($app).
 * - Establish and reuse a per-request database connection ($db).
 * - Offer an optional lifecycle hook via protected init().
 *
 * Behavior:
 * - Constructor stores $app and calls $this->app->db->establish() once.
 * - If the child defines init(), it is called automatically (method_exists guard).
 * - No heavy logic in constructors; keep setup minimal and deterministic.
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (service resolver)
 * - 'db' service (expected to expose establish(), query helpers, transactions)
 *
 * Configuration keys:
 * - Database settings are consumed by the 'db' service; BaseModel itself has none.
 *
 * Error handling:
 * - Does not catch exceptions. Connection/query failures surface via the DB service
 *   and bubble to the global error handler.
 *
 * Typical usage:
 *
 *   class UserModel extends BaseModel {
 *       public function findById(int $id): ?array {
 *           return $this->db->fetchRow('SELECT * FROM users WHERE id = ?', [$id]) ?: null;
 *       }
 *   }
 *
 * Examples:
 *   $user = (new UserModel($app))->findById(123);
 *
 * Failure:
 *   // If the 'db' service is missing or misconfigured, the constructor will fail
 *   // when calling $this->app->db->establish(); the exception bubbles up.
 *
 * Standalone:
 *   // Not intended for standalone use; constructed inside controllers/services.
 */
abstract class BaseModel {
	

	/**
	 * Reference to the core application container.
	 *
	 * Provides access to shared services, configuration, and application-wide resources
	 * for use throughout the model and its child classes.
	 *
	 * @var \App Core application instance.
	 */
	protected App $app;


	/**
	 * Optional configuration options passed at construction.
	 *
	 * Provides per-instance parameters or overrides for models.
	 * These values are opaque to the base class and consumed
	 * only by child models that recognize them.
	 *
	 * @var array<string,mixed> Arbitrary options for model customization.
	 */
	protected array $options;


	/**
	 * Database connection handle (LiteMySQLi).
	 *
	 * Single per-request connection obtained from the Db service and reused for
	 * all queries in this model to minimize overhead.
	 * Initialized in the constructor via `$this->app->db->establish()`.
	 *
	 * Exposes helper methods such as: query(), fetchRow(), fetchAll(), insert(),
	 * update(), delete(), lastInsertId(), affectedRows(), beginTransaction(),
	 * commit(), rollback(), easyTransaction(), etc.
	 *
	 * @var \LiteMySQLi\LiteMySQLi
	 */
	// protected \LiteMySQLi\LiteMySQLi $db;
	

    /**
     * BaseModel constructor.
     * 
	 * Inject the application container and optional options bag.
	 *
	 * @param App $app Application instance (shared container/config).
	 * @param array<string,mixed> $options Optional model-specific settings.
	 */
    public function __construct(App $app, array $options = []) {		
		
		$this->app = $app;  // Expose services (e.g., $this->app->cfg, $this->app->log, ...)
		
		$this->options = $options;  // Store per-service tunables (local only, no global config impact)

		
		// Establish the db-connection
		// $this->db = $this->app->db->establish(); // One connection per request/process

		// Call an `init()` method if the child class has defined one 
		// Note: This is an optional lifecycle hook; keep it lightweight.
		if (\method_exists($this, 'init')) {
			$this->init();
		}
		
    }

}
