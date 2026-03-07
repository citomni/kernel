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

namespace CitOmni\Kernel\Repository;

use CitOmni\Kernel\App;

/**
 * BaseRepository: Minimal persistence base for CitOmni repositories.
 *
 * Responsibilities:
 * - Expose the application container ($app) for shared DB access, config, and relevant services.
 * - Provide a lightweight lifecycle hook via protected init().
 * - Standardize the constructor contract for Repository classes.
 *
 * Behavior:
 * - Constructor stores $app.
 * - If the child defines init(), it is called automatically (method_exists guard).
 * - No SQL abstraction is introduced here; repositories still own explicit queries.
 * - No transport concerns live here (no HTTP/CLI output shaping).
 *
 * Collaborators:
 * - \CitOmni\Kernel\App (shared access to db/config/services where persistence-relevant).
 *
 * Architectural role:
 * - Repository is the persistence boundary.
 * - All SQL and datastore I/O live in repository classes.
 * - Repository may read persistence-related config via $this->app.
 * - Repository must not take over orchestration, transport shaping, or unrelated side effects.
 *
 * Error handling:
 * - No exceptions are caught; failures bubble to the global error handler.
 *
 * Typical usage:
 *
 *   final class UserRepository extends BaseRepository {
 *       public function findById(int $userId): ?array {
 *           return $this->app->db->fetchRow(
 *               'SELECT id, email, status FROM users WHERE id = ? LIMIT 1',
 *               [$userId]
 *           );
 *       }
 *
 *       public function findByStatus(string $status): array {
 *           return $this->app->db->fetchAll(
 *               'SELECT id, email, status FROM users WHERE status = ? ORDER BY id ASC',
 *               [$status]
 *           );
 *       }
 *
 *       public function emailExists(string $email): bool {
 *           return $this->app->db->exists(
 *               'users',
 *               'email = ?',
 *               [$email]
 *           );
 *       }
 *
 *       public function create(string $email, string $status = 'active'): int {
 *           return $this->app->db->insert('users', [
 *               'email' => $email,
 *               'status' => $status,
 *           ]);
 *       }
 *
 *       public function setStatus(int $userId, string $status): int {
 *           return $this->app->db->update(
 *               'users',
 *               ['status' => $status],
 *               'id = ?',
 *               [$userId]
 *           );
 *       }
 *
 *       public function deleteById(int $userId): int {
 *           return $this->app->db->delete(
 *               'users',
 *               'id = ?',
 *               [$userId]
 *           );
 *       }
 *   }
 *
 * Notes:
 * - Repositories are instantiated explicitly where needed.
 * - Keep init() minimal; it runs eagerly on construction.
 * - This base exists to standardize structure, not to hide SQL behind ceremony.
 *
 */
abstract class BaseRepository {

	/** Application container (configuration, service map, shared DB access). */
	protected App $app;

	/**
	 * Inject the application container.
	 *
	 * Typical usage:
	 *   $repository = new UserRepository($app);
	 *
	 * @param App $app Application instance (shared container/config).
	 */
	public function __construct(App $app) {

		$this->app = $app;	// Expose db/config through explicit, low-overhead wiring.


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
