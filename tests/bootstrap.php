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
 * Shared bootstrap for citomni/kernel regression scripts.
 *
 * Run a script directly, e.g. `php tests/arr_test.php`, or all of them
 * with `composer test`. Scripts exit non-zero on the first failed check.
 *
 * Provides:
 * - A PSR-4 autoloader for src/ and tests/. The kernel has no dependencies,
 *   so vendor/autoload.php is neither needed nor used.
 * - fail(), check(), expectThrows(), tempDir(), and done() helpers.
 */

/** Report a fatal setup problem on STDERR and exit non-zero. */
function fail(string $message): never {
	\fwrite(\STDERR, $message . "\n");
	exit(1);
}

if (\PHP_VERSION_ID < 80500) {
	fail('PHP 8.5 or newer is required.');
}

\spl_autoload_register(static function (string $class): void {
	$map = [
		'CitOmni\\Kernel\\Tests\\' => __DIR__ . '/',
		'CitOmni\\Kernel\\' => __DIR__ . '/../src/',
	];

	foreach ($map as $prefix => $directory) {
		if (\str_starts_with($class, $prefix)) {
			$file = $directory . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';

			if (\is_file($file)) {
				require $file;
			}

			return;
		}
	}
});

$checks = 0;


/** Check a condition independently of zend.assertions. */
function check(bool $condition, string $message): void {
	global $checks;
	++$checks;

	if (!$condition) {
		throw new \RuntimeException('FAILED: ' . $message);
	}
}


/**
 * Check that a callback throws a given exception class.
 *
 * @param class-string<\Throwable> $class Expected exception class.
 * @return \Throwable The caught exception.
 */
function expectThrows(string $class, callable $callback, string $message): \Throwable {
	try {
		$callback();
	} catch (\Throwable $e) {
		check($e instanceof $class, $message . ' (expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage() . ')');
		return $e;
	}

	check(false, $message . ' (expected ' . $class . ', nothing thrown)');
	throw new \LogicException('unreachable');
}


/**
 * Create an empty temporary directory, removed at shutdown.
 */
function tempDir(): string {
	$dir = \sys_get_temp_dir() . '/citomni-kernel-test-' . \bin2hex(\random_bytes(6));

	if (!\mkdir($dir, 0777, true)) {
		fail('Unable to create temp dir: ' . $dir);
	}

	\register_shutdown_function(static function () use ($dir): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($iterator as $entry) {
			$entry->isDir() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
		}

		@\rmdir($dir);
	});

	return $dir;
}


/** Print the summary line for a script. */
function done(string $name): void {
	global $checks;
	echo $name . ': ' . $checks . " checks passed.\n";
}
