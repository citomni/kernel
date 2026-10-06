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
 * Stand-in for the mode packages' Boot\Registry classes.
 *
 * App reads its vendor baselines from \CitOmni\Http\Boot\Registry and
 * \CitOmni\Cli\Boot\Registry. The kernel depends on neither package, so a
 * test aliases both names to this class with class_alias(). It carries the
 * constants of both modes; each App reads only those of its own mode.
 * The dispatch targets and service classes below are never resolved.
 */
final class RegistryFixture {

	public const CFG_HTTP = [
		'fixture' => ['mode' => 'http'],
	];

	public const ROUTES_HTTP = [
		'/' => ['controller' => 'Fixture\Http\HomeController', 'action' => 'index'],
	];

	public const MAP_HTTP = [
		'fixture' => 'Fixture\Http\FixtureService',
	];

	public const CFG_CLI = [
		'fixture' => ['mode' => 'cli'],
	];

	public const COMMANDS_CLI = [
		'fixture:noop' => ['command' => 'Fixture\Cli\NoopCommand', 'description' => 'Fixture command'],
	];

	public const MAP_CLI = [
		'fixture' => 'Fixture\Cli\FixtureService',
	];
}
