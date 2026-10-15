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

namespace CitOmni\Kernel\Tests\Fixtures;


/**
 * ProviderFixture: Stand-in for a provider package's Boot\Registry.
 *
 * A suite lists it in the providers.php of its test app, so its dispatch entries
 * form the provider layer that the app's own files override or remove.
 *
 * Rules:
 * - Dispatch targets name classes that do not exist: the suites read the merged
 *   maps and never dispatch.
 */
class ProviderFixture {

	/** Provider routes: one exact route and two placeholder routes. */
	public const array ROUTES_HTTP = [
		'/provider.html' => ['controller' => 'Fixture\Provider\PageController', 'action' => 'index'],
		'regex' => [
			'/item/{id}.html' => ['controller' => 'Fixture\Provider\ItemController', 'action' => 'show'],
			'/tag/{slug}.html' => ['controller' => 'Fixture\Provider\TagController', 'action' => 'show'],
		],
	];

	/** Provider commands. */
	public const array COMMANDS_CLI = [
		'provider:run' => ['command' => 'Fixture\Provider\RunCommand', 'description' => 'Provider command'],
		'provider:keep' => ['command' => 'Fixture\Provider\KeepCommand', 'description' => 'Kept provider command'],
	];
}
