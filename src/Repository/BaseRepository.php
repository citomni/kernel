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
 * A Repository is the persistence boundary: All SQL and datastore I/O live in repository
 * classes, and a Repository may read persistence-related config through the App. This base
 * exists to standardize their structure, not to hide SQL behind ceremony; each Repository owns
 * its explicit queries. Callers create a Repository explicitly where they need it. Orchestration
 * belongs to Operations, transport shaping to Controllers and Commands.
 *
 * Rules:
 * - Introduce no SQL abstraction in this base.
 * - Return documented scalars or array shapes with cast values, never raw database rows.
 * - Never take over orchestration, transport shaping or unrelated side effects.
 * - Catch no exceptions in this base; failures bubble to the global error handler.
 */
abstract class BaseRepository {

	/** Application container (configuration, service map, shared DB access). */
	protected App $app;

	/**
	 * Inject the application container.
	 *
	 * Setup that every instance needs belongs in an optional protected function init(): void,
	 * not in a constructor of the subclass. Keep init() lightweight: No heavy I/O, no unnecessary
	 * allocations.
	 *
	 * Behavior:
	 * - Calls init() once, after $app is set, when the subclass declares it.
	 *
	 * @param  App  $app  Application instance (shared container/config).
	 */
	public function __construct(App $app) {

		$this->app = $app;	// Expose db/config through explicit, low-overhead wiring.

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}


}
