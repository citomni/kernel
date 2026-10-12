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

return [
	'package' => 'citomni/kernel',
	'version' => 1,
	'files' => [
		[
			'target' => 'config/citomni_cfg.php',
			'source' => 'install/scaffold/config/citomni_cfg.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cfg.dev.php',
			'source' => 'install/scaffold/config/citomni_cfg.dev.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cfg.stage.php',
			'source' => 'install/scaffold/config/citomni_cfg.stage.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/citomni_cfg.prod.php',
			'source' => 'install/scaffold/config/citomni_cfg.prod.php.stub',
			'type' => 'config',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/services.php',
			'source' => 'install/scaffold/config/services.php.stub',
			'type' => 'service-map',
			'policy' => 'create-only',
		],
		[
			'target' => 'config/providers.php',
			'source' => 'install/scaffold/config/providers.php.stub',
			'type' => 'providers',
			'policy' => 'create-only',
		],
	],
	'gitignore' => [
		'/var/cache/*',
		'!/var/cache/.gitkeep',
	],
];
