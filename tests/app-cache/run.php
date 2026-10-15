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

namespace CitOmni\Kernel\Tests\AppCache;

use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;
use CitOmni\Kernel\Tests\Fixtures\RegistryFixture;

/*
 * Isolated suite for App::clearCache(): it removes the three cache files
 * warmCache() writes for the App's own mode, returns the same shape as
 * warmCache(), returns nulls when nothing is cached, leaves the other mode's
 * files alone, and fails fast when a file exists but cannot be removed.
 *
 * Every case starts from an app without var/cache. A fresh App after a step
 * shows what the next boot reads.
 *
 * Usage:
 *   php tests/app-cache/run.php
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

\define('CITOMNI_APP_PATH', tempDir());
\mkdir(\CITOMNI_APP_PATH . '/config');

/** clearCache() result when none of the mode's cache files were present. */
const NONE = ['cfg' => null, 'dispatch' => null, 'services' => null];


/**
 * The app's config directory.
 *
 * @return string
 */
function configDir(): string {
	return \CITOMNI_APP_PATH . '/config';
}


/**
 * The app's cache directory.
 *
 * @return string
 */
function cacheDir(): string {
	return \CITOMNI_APP_PATH . '/var/cache';
}


/**
 * The cache files of one mode, keyed like the result of warmCache().
 *
 * @param  \CitOmni\Kernel\Mode  $mode  Mode whose files to list.
 * @return array{cfg: string, dispatch: string, services: string}
 */
function cacheFiles(Mode $mode): array {
	$dir = cacheDir();

	return $mode === Mode::HTTP
		? ['cfg' => $dir . '/cfg.http.php', 'dispatch' => $dir . '/routes.http.php', 'services' => $dir . '/services.http.php']
		: ['cfg' => $dir . '/cfg.cli.php', 'dispatch' => $dir . '/commands.cli.php', 'services' => $dir . '/services.cli.php'];
}


/**
 * Write the app's common cfg source with a marker value.
 *
 * @param  string  $marker  Value of the cfg key "marker".
 * @return void
 */
function writeSource(string $marker): void {
	$file = configDir() . '/citomni_cfg.php';
	\file_put_contents($file, "<?php\nreturn ['marker' => " . \var_export($marker, true) . "];\n");

	// Rewritten within the same second: keep a CLI with opcache.enable_cli=1
	// from serving the previous version.
	if (\function_exists('opcache_invalidate')) {
		\opcache_invalidate($file, true);
	}
}


/**
 * Start a case from an app without var/cache and with the marker v1 in its source.
 *
 * @return void
 */
function resetApp(): void {
	removeTree(cacheDir());
	writeSource('v1');
}


/**
 * The marker the next boot of a mode reads: from its cache when warm, otherwise from the source.
 *
 * @param  \CitOmni\Kernel\Mode  $mode  Mode to boot.
 * @return string
 */
function bootMarker(Mode $mode): string {
	return (new App(configDir(), $mode))->cfg->marker;
}


// ----------------------------------------------------------------
// Warm and clear
// ----------------------------------------------------------------

test('clearCache() without cache files returns nulls and does not create var/cache', static function (): void {
	resetApp();
	$cli = new App(configDir(), Mode::CLI);

	check($cli->clearCache() === NONE, 'clear without cache files returns nulls');
	check(!\is_dir(cacheDir()), 'clear without cache files does not create var/cache');
});

test('warmCache() returns the three paths of its own mode and writes those files', static function (): void {
	resetApp();
	$cliFiles  = cacheFiles(Mode::CLI);
	$httpFiles = cacheFiles(Mode::HTTP);

	check((new App(configDir(), Mode::CLI))->warmCache() === $cliFiles, 'CLI warmCache() returns the three CLI paths');
	check(\array_filter($cliFiles, \is_file(...)) === $cliFiles, 'CLI cache files exist after warm');
	check((new App(configDir(), Mode::HTTP))->warmCache() === $httpFiles, 'HTTP warmCache() returns the three HTTP paths');
	check(\array_filter($httpFiles, \is_file(...)) === $httpFiles, 'HTTP cache files exist after warm');
});

test('the next boot reads a warm cache and ignores a changed source', static function (): void {
	resetApp();
	(new App(configDir(), Mode::CLI))->warmCache();
	writeSource('v2');

	check(bootMarker(Mode::CLI) === 'v1', 'a warm CLI cache wins over a changed source');
});

test('clearCache() removes only its own mode\'s files, returns them in the warmCache() shape, and keeps var/cache', static function (): void {
	resetApp();
	$cliFiles  = cacheFiles(Mode::CLI);
	$httpFiles = cacheFiles(Mode::HTTP);
	$cli = new App(configDir(), Mode::CLI);
	$cli->warmCache();
	(new App(configDir(), Mode::HTTP))->warmCache();

	check($cli->clearCache() === $cliFiles, 'clearCache() returns the removed paths in the warmCache() shape');
	check(\array_filter($cliFiles, \is_file(...)) === [], 'CLI cache files are gone after clear');
	check(\array_filter($httpFiles, \is_file(...)) === $httpFiles, 'a CLI clear leaves the HTTP cache files alone');
	check(\is_dir(cacheDir()), 'clear keeps var/cache itself');
});

test('after clearCache(), the next boot of that mode rebuilds from sources and the other mode still reads its cache', static function (): void {
	resetApp();
	$cli  = new App(configDir(), Mode::CLI);
	$http = new App(configDir(), Mode::HTTP);
	$cli->warmCache();
	$http->warmCache();
	writeSource('v2');
	$cli->clearCache();

	check(bootMarker(Mode::CLI) === 'v2', 'after clear, the next CLI boot rebuilds from sources');
	check(bootMarker(Mode::HTTP) === 'v1', 'the next HTTP boot still reads its warm cache');
	check($http->clearCache() === cacheFiles(Mode::HTTP), 'HTTP clearCache() reports routes.http.php as dispatch');
	check(bootMarker(Mode::HTTP) === 'v2', 'after clear, the next HTTP boot rebuilds from sources');
});

test('a second clearCache() returns nulls', static function (): void {
	resetApp();
	$cli = new App(configDir(), Mode::CLI);
	$cli->warmCache();
	$cli->clearCache();

	check($cli->clearCache() === NONE, 'a second clear returns nulls');
});

test('clearCache() reports only the files that were present as removed', static function (): void {
	resetApp();
	$files = cacheFiles(Mode::CLI);
	$cli   = new App(configDir(), Mode::CLI);
	$cli->warmCache();
	\unlink($files['dispatch']);

	check($cli->clearCache() === ['cfg' => $files['cfg'], 'dispatch' => null, 'services' => $files['services']], 'only files that were present are reported as removed');
});

test('clearCache(opcacheInvalidate: false) removes the files', static function (): void {
	resetApp();
	$cli = new App(configDir(), Mode::CLI);
	$cli->warmCache(opcacheInvalidate: false);

	check($cli->clearCache(opcacheInvalidate: false) === cacheFiles(Mode::CLI), 'clearCache(opcacheInvalidate: false) removes the files');
});


// ----------------------------------------------------------------
// Fail fast when a file exists but cannot be removed
// ----------------------------------------------------------------

// POSIX refuses unlink() in a directory without write permission; Windows
// refuses to delete a read-only file. Both are applied. A probe file shows
// whether this platform and user can be refused at all (root cannot).
test('clearCache() fails fast when a cache file exists but cannot be removed', static function (): void {
	resetApp();
	$files = cacheFiles(Mode::CLI);
	$cli   = new App(configDir(), Mode::CLI);
	$cli->warmCache();

	$probe = cacheDir() . '/probe.tmp';
	\file_put_contents($probe, '');

	$locked = [...\array_values($files), $probe];
	foreach ($locked as $file) {
		\chmod($file, 0444);
	}
	\chmod(cacheDir(), 0555);

	try {
		requires(!@\unlink($probe), 'this user can remove a read-only file from a read-only directory (e.g. root)');

		$e = expectThrows(\RuntimeException::class, static fn() => $cli->clearCache(), 'clearCache() fails fast when a cache file cannot be removed');
		check(\str_contains($e->getMessage(), $files['cfg']), 'the exception names the file');
		\clearstatcache();
		check(\is_file($files['cfg']), 'the file that could not be removed is still there');
	} finally {
		\chmod(cacheDir(), 0775);
		foreach ($locked as $file) {
			if (\is_file($file)) {
				\chmod($file, 0644);
			}
		}
	}
});

done();
