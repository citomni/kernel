<?php

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
];
