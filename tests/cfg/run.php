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

namespace CitOmni\Kernel\Tests\Cfg;

use CitOmni\Kernel\Arr;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Tests\Fixtures\BackedEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\PureEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\TraversableEnumFixture;

/*
 * Isolated suite for \CitOmni\Kernel\Cfg: enum cases come back unchanged
 * through property access, array access, and toArray(), and unknown keys still
 * fail fast. The wrapper is built the way App builds it: a normalized array in
 * a Cfg.
 *
 * It also pins the three read rules that guides and code rely on:
 * 1) A read without ?? throws OutOfBoundsException when any node is missing.
 * 2) ?? returns its default for a missing node at any depth, and for null.
 * 3) isset() reports whether a key exists, also when its value is null.
 *
 * Usage:
 *   php tests/cfg/run.php
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


/**
 * A Cfg holding enum cases at the root and in a nested node.
 *
 * @return \CitOmni\Kernel\Cfg
 */
function enumCfg(): Cfg {
	return new Cfg(Arr::normalizeConfig([
		'image' => [
			'format' => BackedEnumFixture::Webp,
			'fit' => PureEnumFixture::Cover,
		],
		'mode' => TraversableEnumFixture::First,
	]));
}


/**
 * A Cfg for the read rules: one existing node with a true leaf and a null leaf.
 *
 * @return \CitOmni\Kernel\Cfg
 */
function rulesCfg(): Cfg {
	return new Cfg(Arr::normalizeConfig([
		'http' => [
			'trust_proxy' => true,
			'base_url'    => null,
		],
	]));
}


/**
 * Check that a read returns the expected value.
 *
 * A read that throws fails the check under its own message, which names the
 * rule, instead of under the exception alone.
 *
 * @param  callable  $read      Performs the read and returns its result.
 * @param  mixed     $expected  Compared with ===.
 * @param  string    $message   Names the rule the read checks.
 * @return void
 * @throws \CheckFailed  When the read throws or returns something else.
 */
function checkRead(callable $read, mixed $expected, string $message): void {
	try {
		$actual = $read();
	} catch (\Throwable $e) {
		throw new \CheckFailed($message . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')');
	}

	check($actual === $expected, $message);
}


// ----------------------------------------------------------------
// Enum cases come back unchanged
// ----------------------------------------------------------------

test('__get() returns enum cases unchanged and does not wrap an IteratorAggregate case', static function (): void {
	$cfg = enumCfg();
	check($cfg->image->format === BackedEnumFixture::Webp, '__get(): backed enum is returned unchanged');
	check($cfg->image->fit === PureEnumFixture::Cover, '__get(): pure enum is returned unchanged');
	check($cfg->mode === TraversableEnumFixture::First, '__get(): IteratorAggregate enum is returned unchanged, not wrapped');
});

test('offsetGet() returns enum cases unchanged and does not wrap an IteratorAggregate case', static function (): void {
	$cfg = enumCfg();
	check($cfg['image']['format'] === BackedEnumFixture::Webp, 'offsetGet(): backed enum is returned unchanged');
	check($cfg['image']['fit'] === PureEnumFixture::Cover, 'offsetGet(): pure enum is returned unchanged');
	check($cfg['mode'] === TraversableEnumFixture::First, 'offsetGet(): IteratorAggregate enum is returned unchanged, not wrapped');
});

test('toArray() holds the same enum instances at the root and in a nested node', static function (): void {
	$cfg = enumCfg();
	check($cfg->toArray()['image']['format'] === BackedEnumFixture::Webp, 'toArray(): root holds the same instance');
	check($cfg->toArray()['mode'] === TraversableEnumFixture::First, 'toArray(): root holds the same IteratorAggregate instance');
	check($cfg->image->toArray() === ['format' => BackedEnumFixture::Webp, 'fit' => PureEnumFixture::Cover], 'toArray(): nested node holds the same instances');
});


// ----------------------------------------------------------------
// Unknown keys still fail fast
// ----------------------------------------------------------------

test('an unknown key throws OutOfBoundsException at the root and in a nested node', static function (): void {
	$cfg = enumCfg();
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->missing, '__get(): unknown root key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->image->missing, '__get(): unknown nested key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg['missing'], 'offsetGet(): unknown root key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg['image']['missing'], 'offsetGet(): unknown nested key');
});


// ----------------------------------------------------------------
// Read rules: without ??, with ?? and with isset()
// ----------------------------------------------------------------

test('Rule 1: without ??, a missing intermediate node throws before its leaf is read', static function (): void {
	$rules = rulesCfg();
	expectThrows(\OutOfBoundsException::class, static fn() => $rules->vendor_feature->enabled, 'Rule 1: missing intermediate node, property syntax');
	expectThrows(\OutOfBoundsException::class, static fn() => $rules['vendor_feature']['enabled'], 'Rule 1: missing intermediate node, array syntax');
});

// ?? asks __isset() or offsetExists() before every read, so no missing node throws.
test('Rule 2: ?? returns its default for a missing node at any depth and for null', static function (): void {
	$rules = rulesCfg();
	checkRead(static fn() => $rules->vendor_feature->enabled ?? 'default', 'default', 'Rule 2: ?? through a missing root node');
	checkRead(static fn() => $rules->vendor_feature->nested->enabled ?? 'default', 'default', 'Rule 2: ?? through two missing nodes');
	checkRead(static fn() => $rules->http->missing ?? 'default', 'default', 'Rule 2: ?? for a missing leaf under an existing node');
	checkRead(static fn() => $rules->http->base_url ?? 'default', 'default', 'Rule 2: ?? for a leaf whose value is null');
	checkRead(static fn() => $rules->http->trust_proxy ?? false, true, 'Rule 2: ?? returns an existing value');
	checkRead(static fn() => $rules['vendor_feature']['enabled'] ?? 'default', 'default', 'Rule 2: ?? through a missing root node, array syntax');
	checkRead(static fn() => $rules['http']['base_url'] ?? 'default', 'default', 'Rule 2: ?? for a leaf whose value is null, array syntax');
});

// isset() differs from PHP's own for arrays: A key that holds null counts as set.
test('Rule 3: isset() reports whether a key exists, also when its value is null', static function (): void {
	$rules = rulesCfg();
	check(isset($rules->http), 'Rule 3: isset() of an existing node');
	check(!isset($rules->vendor_feature), 'Rule 3: isset() of a missing node');
	check(!isset($rules->vendor_feature->enabled), 'Rule 3: isset() through a missing node is false, without throwing');
	check(isset($rules->http->base_url), 'Rule 3: isset() is true for a key whose value is null');
	check(isset($rules['http']['base_url']), 'Rule 3: isset() is true for a key whose value is null, array syntax');
});

done();
