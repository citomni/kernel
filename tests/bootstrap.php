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
 * Shared bootstrap for the suites (tests/<suite>/run.php). Not a suite of its
 * own; tests/run.php only collects run.php and database.php.
 *
 * A suite installs its CLI guard and its error handler, then requires this file
 * instead of a Composer bootstrap. Every suite runs in its own process.
 *
 * Provides:
 * - A PSR-4 autoloader for src/ and tests/. The kernel has no dependencies,
 *   so vendor/autoload.php is neither needed nor used.
 * - The case runner: test() runs one named case and reports PASS, FAIL, or
 *   SKIP; done() prints the totals and ends the suite with its exit code.
 * - Assertions: check(), expectThrows(), and requires().
 * - tempDir() and removeTree() for the files a suite writes.
 *
 * Notes:
 * - Files a suite writes live below one run directory,
 *   citomni_kernel_<suite>_test_<random> under sys_get_temp_dir(), created on
 *   first use and removed at shutdown. Nothing else is ever removed.
 */

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

// The run directory of this suite; the suite is the directory of the script PHP started.
\define('KERNEL_TEST_RUN_DIR', \sys_get_temp_dir() . '/citomni_kernel_' . \basename(\dirname(\get_included_files()[0])) . '_test_' . \bin2hex(\random_bytes(6)));

$passed  = 0;
$failed  = 0;
$skipped = 0;


/** A failed check: the case fails with the check's message. */
final class CheckFailed extends \RuntimeException {
}


/** A case the platform cannot run: it is reported as SKIP with the reason. */
final class CaseSkipped extends \RuntimeException {
}


// ----------------------------------------------------------------
// Case runner
// ----------------------------------------------------------------

/**
 * Run one case and report it.
 *
 * Behavior:
 * - PASS <name> on stdout when the case returns.
 * - SKIP <name>: <reason> on stdout when the case calls requires() with a
 *   condition that does not hold.
 * - FAIL <name> - <reason> on stderr when a check fails or anything else is
 *   thrown. The suite goes on with the next case either way.
 *
 * @param  string    $name  Case name, shown in the report.
 * @param  \Closure  $case  The case; it sets up everything it needs itself.
 * @return void
 */
function test(string $name, \Closure $case): void {
	global $passed, $failed, $skipped;

	try {
		$case();
	} catch (CaseSkipped $e) {
		++$skipped;
		\fwrite(\STDOUT, "SKIP {$name}: {$e->getMessage()}\n");
		return;
	} catch (CheckFailed $e) {
		++$failed;
		\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e->getMessage()) . "\n");
		return;
	} catch (\Throwable $e) {
		++$failed;
		\fwrite(\STDERR, "FAIL {$name} - " . oneLine($e::class . ': ' . $e->getMessage() . ' at ' . \basename($e->getFile()) . ':' . $e->getLine()) . "\n");
		return;
	}

	++$passed;
	\fwrite(\STDOUT, "PASS {$name}\n");
}


/**
 * Print the totals line and end the suite.
 *
 * @return never  Exits with 0 when no case failed, otherwise 1.
 */
function done(): never {
	global $passed, $failed, $skipped;

	\fwrite(\STDOUT, "{$passed} passed, {$failed} failed" . ($skipped > 0 ? ", {$skipped} skipped" : '') . "\n");
	exit($failed === 0 ? 0 : 1);
}


/**
 * Collapse line breaks, so a FAIL line stays on one line.
 *
 * @param  string  $text  Message that may span several lines.
 * @return string
 */
function oneLine(string $text): string {
	return \trim((string)\preg_replace('/\s*\R\s*/', ' | ', $text));
}


// ----------------------------------------------------------------
// Assertions
// ----------------------------------------------------------------

/**
 * Fail the current case with $message unless $condition holds.
 *
 * @param  bool    $condition  Checked independently of zend.assertions.
 * @param  string  $message    Names what was checked.
 * @return void
 * @throws \CheckFailed  When $condition is false.
 */
function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new CheckFailed($message);
	}
}


/**
 * Check that a callback throws a given exception class.
 *
 * @param  class-string<\Throwable>  $class     Expected class (or parent class).
 * @param  callable                  $callback  Code that must throw.
 * @param  string                    $message   Names what was checked.
 * @return \Throwable  The caught exception.
 * @throws \CheckFailed  When nothing is thrown, or something that is not a $class.
 */
function expectThrows(string $class, callable $callback, string $message): \Throwable {
	try {
		$callback();
	} catch (\Throwable $e) {
		check($e instanceof $class, $message . ' (expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage() . ')');
		return $e;
	}

	throw new CheckFailed($message . ' (expected ' . $class . ', nothing thrown)');
}


/**
 * Skip the current case unless the platform can run it.
 *
 * Call it before the case's first check, so a skipped case has checked nothing.
 *
 * @param  bool    $condition  Whether the platform can run the case.
 * @param  string  $reason     Why the case cannot run, shown after SKIP.
 * @return void
 * @throws \CaseSkipped  When $condition is false.
 */
function requires(bool $condition, string $reason): void {
	if (!$condition) {
		throw new CaseSkipped($reason);
	}
}


// ----------------------------------------------------------------
// Files
// ----------------------------------------------------------------

/**
 * Create an empty directory below the run directory.
 *
 * The run directory is created on first use and removed at shutdown.
 *
 * @return string  Path of the new directory.
 */
function tempDir(): string {
	static $count = 0;

	if ($count === 0) {
		\mkdir(\KERNEL_TEST_RUN_DIR, 0777, true);
		\register_shutdown_function(static function (): void {
			removeTree(\KERNEL_TEST_RUN_DIR);
		});
	}

	$dir = \KERNEL_TEST_RUN_DIR . '/' . ++$count;
	\mkdir($dir);

	return $dir;
}


/**
 * Remove the run directory or a directory below it, with everything in it.
 *
 * Write permission is restored first, because a case may make files and
 * directories read-only on purpose. A directory that does not exist is left alone.
 *
 * @param  string  $dir  The run directory or a path below it.
 * @return void
 * @throws \LogicException  When $dir is outside the run directory.
 */
function removeTree(string $dir): void {
	if ($dir !== \KERNEL_TEST_RUN_DIR && !\str_starts_with($dir, \KERNEL_TEST_RUN_DIR . '/')) {
		throw new \LogicException('Refusing to remove a directory outside the run directory: ' . $dir);
	}

	if (!\is_dir($dir)) {
		return;
	}

	@\chmod($dir, 0777);

	$entries = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($entries as $entry) {
		if (!$entry->isLink()) {
			@\chmod($entry->getPathname(), $entry->isDir() ? 0777 : 0666);
		}
	}

	$entries = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		\RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ($entries as $entry) {
		$entry->isDir() && !$entry->isLink() ? @\rmdir($entry->getPathname()) : @\unlink($entry->getPathname());
	}

	@\rmdir($dir);
}
