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

namespace CitOmni\Kernel\Tests\DispatchMap;

use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;
use CitOmni\Kernel\Tests\Fixtures\ProviderFixture;
use CitOmni\Kernel\Tests\Fixtures\RegistryFixture;

/*
 * Isolated suite for removing dispatch map entries: a source sets an entry of an
 * earlier source to null, and App drops it after the last merge, at the top level
 * of the route and command maps and among the placeholder routes under 'regex'.
 *
 * Every case writes the app's config files from scratch and starts without
 * var/cache, so each App builds its maps from the sources.
 *
 * Usage:
 *   php tests/dispatch-map/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

// Fail fast on every diagnostic; leave diagnostics silenced with @ to PHP.
\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	if ((\error_reporting() & $errno) === 0) {
		return false;
	}
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';

// App reads its vendor baselines from the mode packages' Registry classes.
// The kernel depends on neither package, so both names point at one fixture.
\class_alias(RegistryFixture::class, 'CitOmni\Http\Boot\Registry');
\class_alias(RegistryFixture::class, 'CitOmni\Cli\Boot\Registry');

// CITOMNI_ENVIRONMENT stays undefined, so the env overlay files are the .prod.php files.
\define('CITOMNI_APP_PATH', tempDir());
\mkdir(\CITOMNI_APP_PATH . '/config');


/**
 * The app's config directory.
 *
 * @return string
 */
function configDir(): string {
	return \CITOMNI_APP_PATH . '/config';
}


/**
 * Replace the app's config files and remove var/cache.
 *
 * @param  array<string, array<mixed>>  $files  File name without .php => the array the file returns.
 * @return void
 */
function writeConfig(array $files): void {
	removeTree(\CITOMNI_APP_PATH . '/var/cache');

	foreach (\glob(configDir() . '/*.php') ?: [] as $file) {
		\unlink($file);
	}

	foreach ($files as $name => $entries) {
		$file = configDir() . '/' . $name . '.php';
		\file_put_contents($file, "<?php\nreturn " . \var_export($entries, true) . ";\n");

		// Rewritten within the same second: keep a CLI with opcache.enable_cli=1
		// from serving the previous version.
		if (\function_exists('opcache_invalidate')) {
			\opcache_invalidate($file, true);
		}
	}
}


/**
 * The command map of a fresh CLI App.
 *
 * @return array<string, mixed>
 */
function commands(): array {
	return (new App(configDir(), Mode::CLI))->commands;
}


/**
 * The route map of a fresh HTTP App.
 *
 * @return array<string, mixed>
 */
function routes(): array {
	return (new App(configDir(), Mode::HTTP))->routes;
}


// ----------------------------------------------------------------
// Commands
// ----------------------------------------------------------------

test('a null entry in the app\'s command file removes a provider command', static function (): void {
	writeConfig([
		'providers' => [ProviderFixture::class],
		'citomni_cli_commands' => ['provider:run' => null],
	]);
	$commands = commands();

	check(!\array_key_exists('provider:run', $commands), 'the removed provider command is not in the map');
	check(isset($commands['provider:keep']), 'the other provider command stays');
	check(isset($commands['fixture:noop']), 'the vendor command stays');
});

test('a null entry in the env overlay removes a command of the app base file and of the vendor baseline', static function (): void {
	writeConfig([
		'citomni_cli_commands' => ['app:run' => ['command' => 'Fixture\App\RunCommand']],
		'citomni_cli_commands.prod' => ['app:run' => null, 'fixture:noop' => null],
	]);

	check(commands() === [], 'the env overlay removed the app command and the vendor command');
});

test('a null entry that no earlier source defines leaves no key', static function (): void {
	writeConfig([
		'citomni_cli_commands' => ['unknown:command' => null],
	]);

	check(!\array_key_exists('unknown:command', commands()), 'the null entry is not in the map');
});

test('a null field inside an entry stays as merged', static function (): void {
	writeConfig([
		'providers' => [ProviderFixture::class],
		'citomni_cli_commands' => ['provider:run' => ['description' => null]],
	]);
	$definition = commands()['provider:run'];

	check(\array_key_exists('description', $definition) && $definition['description'] === null, 'the null field stays in the entry');
	check($definition['command'] === 'Fixture\Provider\RunCommand', 'the other fields of the entry stay');
});


// ----------------------------------------------------------------
// Routes
// ----------------------------------------------------------------

test('a null route removes an exact route, and a null entry under regex removes one placeholder route', static function (): void {
	writeConfig([
		'providers' => [ProviderFixture::class],
		'citomni_http_routes' => [
			'/' => null,
			'/provider.html' => null,
			'regex' => ['/item/{id}.html' => null],
		],
	]);
	$routes = routes();

	check(!\array_key_exists('/', $routes), 'the removed vendor route is not in the map');
	check(!\array_key_exists('/provider.html', $routes), 'the removed provider route is not in the map');
	check(\array_keys($routes['regex']) === ['/tag/{slug}.html'], 'only the other placeholder route is left under regex');
});

test('\'regex\' => null removes every placeholder route', static function (): void {
	writeConfig([
		'providers' => [ProviderFixture::class],
		'citomni_http_routes' => ['regex' => null],
	]);
	$routes = routes();

	check(!\array_key_exists('regex', $routes), 'regex is not in the map');
	check(isset($routes['/provider.html']), 'the exact provider route stays');
});


// ----------------------------------------------------------------
// Cache
// ----------------------------------------------------------------

test('warmCache() writes both maps without the removed entries', static function (): void {
	writeConfig([
		'providers' => [ProviderFixture::class],
		'citomni_cli_commands' => ['provider:run' => null],
		'citomni_http_routes' => ['regex' => ['/item/{id}.html' => null]],
	]);

	$commands = require (new App(configDir(), Mode::CLI))->warmCache()['dispatch'];
	check(!\array_key_exists('provider:run', $commands), 'the cached command map has no removed command');
	check(isset($commands['provider:keep']), 'the cached command map keeps the other provider command');

	$routes = require (new App(configDir(), Mode::HTTP))->warmCache()['dispatch'];
	check(\array_keys($routes['regex']) === ['/tag/{slug}.html'], 'the cached route map has no removed placeholder route');
});

done();
