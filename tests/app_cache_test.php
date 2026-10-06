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

/*
 * Regression checks for App::clearCache(): it removes the three cache files
 * warmCache() writes for the App's own mode, returns the same shape as
 * warmCache(), returns nulls when nothing is cached, leaves the other mode's
 * files alone, and fails fast when a file exists but cannot be removed.
 * A fresh App after each step shows what the next boot reads.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Kernel\App;
use CitOmni\Kernel\Mode;
use CitOmni\Kernel\Tests\Fixtures\RegistryFixture;

// App reads its vendor baselines from the mode packages' Registry classes.
// The kernel depends on neither package, so both names point at one fixture.
\class_alias(RegistryFixture::class, 'CitOmni\Http\Boot\Registry');
\class_alias(RegistryFixture::class, 'CitOmni\Cli\Boot\Registry');

$appRoot = tempDir();
\define('CITOMNI_APP_PATH', $appRoot);

$configDir = $appRoot . '/config';
if (!\mkdir($configDir)) {
	fail('Unable to create config dir: ' . $configDir);
}

$source   = $configDir . '/citomni_cfg.php';
$cacheDir = $appRoot . '/var/cache';

$cliFiles = [
	'cfg'      => $cacheDir . '/cfg.cli.php',
	'dispatch' => $cacheDir . '/commands.cli.php',
	'services' => $cacheDir . '/services.cli.php',
];
$httpFiles = [
	'cfg'      => $cacheDir . '/cfg.http.php',
	'dispatch' => $cacheDir . '/routes.http.php',
	'services' => $cacheDir . '/services.http.php',
];
$none = ['cfg' => null, 'dispatch' => null, 'services' => null];


/** Write the app's common cfg source with a marker value. */
function writeSource(string $file, string $marker): void {
	if (\file_put_contents($file, "<?php\nreturn ['marker' => " . \var_export($marker, true) . "];\n") === false) {
		fail('Unable to write cfg source: ' . $file);
	}

	// Rewritten within the same second: keep a CLI with opcache.enable_cli=1
	// from serving the previous version.
	if (\function_exists('opcache_invalidate')) {
		\opcache_invalidate($file, true);
	}
}

// -- Clear without cache files ------------------------------------------------

writeSource($source, 'v1');
$cli = new App($configDir, Mode::CLI);

check($cli->clearCache() === $none, 'clear without cache files returns nulls');
check(!\is_dir($cacheDir), 'clear without cache files does not create var/cache');

// -- Warm both modes ----------------------------------------------------------

check($cli->warmCache() === $cliFiles, 'CLI warmCache() returns the three CLI paths');
check(\array_filter($cliFiles, \is_file(...)) === $cliFiles, 'CLI cache files exist after warm');

$http = new App($configDir, Mode::HTTP);
check($http->warmCache() === $httpFiles, 'HTTP warmCache() returns the three HTTP paths');
check(\array_filter($httpFiles, \is_file(...)) === $httpFiles, 'HTTP cache files exist after warm');

// While the cache is warm, the next boot reads it and ignores the changed source.
writeSource($source, 'v2');
check((new App($configDir, Mode::CLI))->cfg->marker === 'v1', 'a warm CLI cache wins over a changed source');

// -- Clear one mode -----------------------------------------------------------

check($cli->clearCache() === $cliFiles, 'clearCache() returns the removed paths in the warmCache() shape');
check(\array_filter($cliFiles, \is_file(...)) === [], 'CLI cache files are gone after clear');
check(\array_filter($httpFiles, \is_file(...)) === $httpFiles, 'a CLI clear leaves the HTTP cache files alone');
check(\is_dir($cacheDir), 'clear keeps var/cache itself');

check((new App($configDir, Mode::CLI))->cfg->marker === 'v2', 'after clear, the next CLI boot rebuilds from sources');
check((new App($configDir, Mode::HTTP))->cfg->marker === 'v1', 'the next HTTP boot still reads its warm cache');

check($cli->clearCache() === $none, 'a second clear returns nulls');

check($http->clearCache() === $httpFiles, 'HTTP clearCache() reports routes.http.php as dispatch');
check((new App($configDir, Mode::HTTP))->cfg->marker === 'v2', 'after clear, the next HTTP boot rebuilds from sources');

// -- Partial cache ------------------------------------------------------------

$cli->warmCache();
if (!\unlink($cliFiles['dispatch'])) {
	fail('Unable to remove ' . $cliFiles['dispatch']);
}
check($cli->clearCache() === ['cfg' => $cliFiles['cfg'], 'dispatch' => null, 'services' => $cliFiles['services']], 'only files that were present are reported as removed');

// -- Without OPcache invalidation ---------------------------------------------

$cli->warmCache(opcacheInvalidate: false);
check($cli->clearCache(opcacheInvalidate: false) === $cliFiles, 'clearCache(opcacheInvalidate: false) removes the files');

// -- Fail fast when a file exists but cannot be removed -----------------------

// POSIX refuses unlink() in a directory without write permission; Windows
// refuses to delete a read-only file. Both are applied. A probe file shows
// whether this platform and user can be refused at all (root cannot).
$cli->warmCache();
$probe = $cacheDir . '/probe.tmp';
if (\file_put_contents($probe, '') === false) {
	fail('Unable to write probe file: ' . $probe);
}

$locked = [...\array_values($cliFiles), $probe];
foreach ($locked as $file) {
	\chmod($file, 0444);
}
\chmod($cacheDir, 0555);

try {
	if (@\unlink($probe)) {
		echo "app_cache_test: fail-fast check skipped; this user can remove a read-only file from a read-only directory (e.g. root).\n";
	} else {
		$e = expectThrows(\RuntimeException::class, static fn() => $cli->clearCache(), 'clearCache() fails fast when a cache file cannot be removed');
		check(\str_contains($e->getMessage(), $cliFiles['cfg']), 'the exception names the file');
		\clearstatcache();
		check(\is_file($cliFiles['cfg']), 'the file that could not be removed is still there');
	}
} finally {
	\chmod($cacheDir, 0775);
	foreach ($locked as $file) {
		if (\is_file($file)) {
			\chmod($file, 0644);
		}
	}
}

done('app_cache_test');
