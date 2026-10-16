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

use CitOmni\Kernel\App;
use CitOmni\Kernel\Arr;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Tests\Fixtures\BackedEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\PureEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\TraversableEnumFixture;

/*
 * Isolated suite for \CitOmni\Kernel\Cfg and App::$cfg.
 *
 * Every behavior statement (B1-B22) of the Cfg access reference,
 * reference/cfg-access.md in citomni/docs, has a case named after it, and so
 * have the code forms of rules R2, R5 and R6. A case that fails means that the
 * reference and the code disagree. The enum cases guard 582ec5e.
 *
 * Every Cfg is built the way App builds it: an array normalized with the real
 * Arr::normalizeConfig(), wrapped in a Cfg.
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
 * A Cfg with one node holding every kind of value the reference distinguishes.
 *
 * @return \CitOmni\Kernel\Cfg
 */
function kindsCfg(): Cfg {
	return new Cfg(Arr::normalizeConfig([
		'pkg' => [
			'map'          => ['a' => 1, 'b' => 2],
			'list'         => ['x', 'y'],
			'gapped'       => [10 => 'ten', 20 => 'twenty'],
			'unordered'    => [1 => 'b', 0 => 'a'],
			'empty'        => [],
			'text'         => 'text',
			'empty_string' => '',
			'zero'         => 0,
			'float'        => 1.5,
			'false_value'  => false,
			'null_value'   => null,
			'deep'         => ['inner' => ['leaf' => 'v']],
			'profiles'     => ['main' => ['host' => 'h']],
		],
	]));
}


/**
 * Check that a read returns the expected value.
 *
 * A read that throws fails the check under its own message, which names the
 * rule, instead of under the exception alone. The suite's error handler turns
 * every PHP diagnostic into an exception, so a read that raises one fails too.
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


/**
 * Check that a callback raises a PHP diagnostic of a given severity and text.
 *
 * @param  int       $severity  Expected E_* constant.
 * @param  string    $text      Text the diagnostic message must contain.
 * @param  callable  $callback  Code that must raise the diagnostic.
 * @param  string    $message   Names what was checked.
 * @return void
 * @throws \CheckFailed  When no such diagnostic is raised.
 */
function expectDiagnostic(int $severity, string $text, callable $callback, string $message): void {
	$e = expectThrows(\ErrorException::class, $callback, $message);
	check($e->getSeverity() === $severity && \str_contains($e->getMessage(), $text), $message . ' (got severity ' . $e->getSeverity() . ': ' . $e->getMessage() . ')');
}


// ----------------------------------------------------------------
// B1: the root
// ----------------------------------------------------------------

test('B1: App exposes cfg as a public readonly property of type Cfg', static function (): void {
	$property = new \ReflectionProperty(App::class, 'cfg');
	check($property->isPublic(), 'App::$cfg is public');
	check($property->isReadOnly(), 'App::$cfg is readonly');
	check((string)$property->getType() === Cfg::class, 'App::$cfg is typed Cfg');
});


// ----------------------------------------------------------------
// B2-B7: what a read returns
// ----------------------------------------------------------------

test('B2: a map is returned as a Cfg node, with property and array syntax', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->map instanceof Cfg, 'property syntax');
	check($cfg['pkg']['map'] instanceof Cfg, 'array syntax');
});

test('B3: a list is returned as the plain array', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->list === ['x', 'y'], 'property syntax');
	check($cfg['pkg']['list'] === ['x', 'y'], 'array syntax');
});

test('B4: integer keys that are not 0, 1, 2, ... in order make a map, read by key with either syntax', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->gapped instanceof Cfg, 'keys 10 and 20 make a node');
	check($cfg->pkg->unordered instanceof Cfg, 'keys 1 and 0 make a node');
	check($cfg->pkg->gapped->{'10'} === 'ten', 'property syntax with a numeric key');
	check($cfg['pkg']['gapped'][20] === 'twenty', 'array syntax with an int key');
});

test('B5: an empty array is returned as an empty Cfg node, not as []', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->empty instanceof Cfg, 'property syntax returns a node');
	check($cfg['pkg']['empty'] instanceof Cfg, 'array syntax returns a node');
	check(\count($cfg->pkg->empty) === 0, 'the node is empty');
});

test('B6: values that are not arrays are returned unchanged', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->text === 'text', 'string');
	check($cfg->pkg->empty_string === '', 'empty string');
	check($cfg->pkg->zero === 0, 'int');
	check($cfg->pkg->float === 1.5, 'float');
	check($cfg->pkg->false_value === false, 'bool');
	check($cfg->pkg->null_value === null, 'null');
	check($cfg['pkg']['null_value'] === null, 'null, array syntax');
});

test('B6: __get() returns enum cases unchanged and does not wrap an IteratorAggregate case', static function (): void {
	$cfg = enumCfg();
	check($cfg->image->format === BackedEnumFixture::Webp, '__get(): backed enum is returned unchanged');
	check($cfg->image->fit === PureEnumFixture::Cover, '__get(): pure enum is returned unchanged');
	check($cfg->mode === TraversableEnumFixture::First, '__get(): IteratorAggregate enum is returned unchanged, not wrapped');
});

test('B6: offsetGet() returns enum cases unchanged and does not wrap an IteratorAggregate case', static function (): void {
	$cfg = enumCfg();
	check($cfg['image']['format'] === BackedEnumFixture::Webp, 'offsetGet(): backed enum is returned unchanged');
	check($cfg['image']['fit'] === PureEnumFixture::Cover, 'offsetGet(): pure enum is returned unchanged');
	check($cfg['mode'] === TraversableEnumFixture::First, 'offsetGet(): IteratorAggregate enum is returned unchanged, not wrapped');
});

test('B7: repeated reads of the same key return the same node, with either syntax', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg === $cfg->pkg, 'property syntax twice');
	check($cfg->pkg === $cfg['pkg'], 'property and array syntax');
	check($cfg->pkg->map === $cfg['pkg']['map'], 'nested map');
	check($cfg->pkg->empty === $cfg['pkg']['empty'], 'empty node');
});


// ----------------------------------------------------------------
// B8-B9: missing keys
// ----------------------------------------------------------------

test('B8: an unknown key throws OutOfBoundsException at the root and in a nested node', static function (): void {
	$cfg = enumCfg();
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->missing, '__get(): unknown root key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->image->missing, '__get(): unknown nested key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg['missing'], 'offsetGet(): unknown root key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg['image']['missing'], 'offsetGet(): unknown nested key');
});

test('B8: without ??, a missing intermediate node throws before its leaf is read', static function (): void {
	$rules = rulesCfg();
	expectThrows(\OutOfBoundsException::class, static fn() => $rules->vendor_feature->enabled, 'missing intermediate node, property syntax');
	expectThrows(\OutOfBoundsException::class, static fn() => $rules['vendor_feature']['enabled'], 'missing intermediate node, array syntax');
});

test('B8: the exception names the first missing key, at any depth', static function (): void {
	$cfg = kindsCfg();
	$expected = "Unknown cfg key: 'missing'";
	check(expectThrows(\OutOfBoundsException::class, static fn() => $cfg->missing, 'depth 1')->getMessage() === $expected, 'depth 1 message');
	check(expectThrows(\OutOfBoundsException::class, static fn() => $cfg->pkg->missing->leaf, 'depth 2')->getMessage() === $expected, 'depth 2 message');
	check(expectThrows(\OutOfBoundsException::class, static fn() => $cfg->pkg->deep->missing->x->y, 'depth 3')->getMessage() === $expected, 'depth 3 message');
	check(expectThrows(\OutOfBoundsException::class, static fn() => $cfg['pkg']['missing']['leaf'], 'array syntax')->getMessage() === $expected, 'array syntax message');
});

test('B9: past a value that is not a node, plain PHP applies and Cfg throws nothing', static function (): void {
	$cfg = kindsCfg();
	expectDiagnostic(\E_WARNING, 'Attempt to read property "x" on string', static fn() => $cfg->pkg->text->x, 'property read on a scalar');
	expectDiagnostic(\E_WARNING, 'Attempt to read property "x" on null', static fn() => $cfg->pkg->null_value->x, 'property read on null');
	expectDiagnostic(\E_WARNING, 'Undefined array key 5', static fn() => $cfg->pkg->list[5], 'missing list index');
	check((@$cfg->pkg->text->x) === null, 'the result is null when the error handler returns, scalar');
	check((@$cfg->pkg->list[5]) === null, 'the result is null when the error handler returns, list index');
});


// ----------------------------------------------------------------
// B10-B14: guards
// ----------------------------------------------------------------

// ?? asks __isset() or offsetExists() before every read, so no missing node throws.
test('B10: ?? returns its default for a missing node at any depth and for null', static function (): void {
	$rules = rulesCfg();
	checkRead(static fn() => $rules->vendor_feature->enabled ?? 'default', 'default', '?? through a missing root node');
	checkRead(static fn() => $rules->vendor_feature->nested->enabled ?? 'default', 'default', '?? through two missing nodes');
	checkRead(static fn() => $rules->http->missing ?? 'default', 'default', '?? for a missing leaf under an existing node');
	checkRead(static fn() => $rules->http->base_url ?? 'default', 'default', '?? for a leaf whose value is null');
	checkRead(static fn() => $rules->http->trust_proxy ?? false, true, '?? returns an existing value');
	checkRead(static fn() => $rules['vendor_feature']['enabled'] ?? 'default', 'default', '?? through a missing root node, array syntax');
	checkRead(static fn() => $rules['http']['base_url'] ?? 'default', 'default', '?? for a leaf whose value is null, array syntax');
});

// Every read below would fail under the suite's error handler if PHP raised a diagnostic.
test('B10: ?? raises no diagnostic past a scalar, null or a missing list index, and keeps false, 0, empty string and an empty node', static function (): void {
	$cfg = kindsCfg();
	checkRead(static fn() => $cfg->pkg->text->x ?? 'default', 'default', 'past a scalar, property syntax');
	checkRead(static fn() => $cfg['pkg']['text']['x'] ?? 'default', 'default', 'past a string, array syntax');
	checkRead(static fn() => $cfg['pkg']['zero']['x'] ?? 'default', 'default', 'past an int, array syntax');
	checkRead(static fn() => $cfg->pkg->null_value->x ?? 'default', 'default', 'past null');
	checkRead(static fn() => $cfg->pkg->list[5] ?? 'default', 'default', 'missing list index');
	checkRead(static fn() => $cfg->pkg->false_value ?? 'default', false, 'false is kept');
	checkRead(static fn() => $cfg->pkg->zero ?? 'default', 0, '0 is kept');
	checkRead(static fn() => $cfg->pkg->empty_string ?? 'default', '', 'empty string is kept');
	checkRead(static fn() => ($cfg->pkg->empty ?? 'default') instanceof Cfg, true, 'an empty node is kept');
});

// isset() differs from PHP's own for arrays: A key that holds null counts as set.
test('B11: isset() reports whether a key exists, also when its value is null', static function (): void {
	$rules = rulesCfg();
	check(isset($rules->http), 'isset() of an existing node');
	check(!isset($rules->vendor_feature), 'isset() of a missing node');
	check(!isset($rules->vendor_feature->enabled), 'isset() through a missing node is false, without throwing');
	check(isset($rules->http->base_url), 'isset() is true for a key whose value is null');
	check(isset($rules['http']['base_url']), 'isset() is true for a key whose value is null, array syntax');
});

test('B12: empty() is true for a missing path and for false, 0 and null, without throwing', static function (): void {
	$cfg = kindsCfg();
	checkRead(static fn() => empty($cfg->pkg->deep->missing->x), true, 'missing path');
	checkRead(static fn() => empty($cfg['pkg']['missing']['x']), true, 'missing path, array syntax');
	checkRead(static fn() => empty($cfg->pkg->false_value), true, 'false');
	checkRead(static fn() => empty($cfg->pkg->zero), true, '0');
	checkRead(static fn() => empty($cfg->pkg->null_value), true, 'null');
	checkRead(static fn() => empty($cfg->pkg->text), false, 'a non-empty string');
});

test('B13: ?? guards only its left operand', static function (): void {
	$cfg = kindsCfg();
	$none = [];
	$set  = ['key' => ''];
	expectThrows(\OutOfBoundsException::class, static fn() => $none['key'] ?? $cfg->pkg->missing, 'a direct read on the right throws when the left operand is missing');
	checkRead(static fn() => $set['key'] ?? $cfg->pkg->missing, '', 'a set left operand wins, an empty string included, and the right operand is not evaluated');
});

test('B14: ?-> guards no key', static function (): void {
	$cfg = kindsCfg();
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->pkg?->missing, '?-> before a missing key');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg?->missing?->leaf, '?-> on every step of a missing path');
});


// ----------------------------------------------------------------
// B15-B17: converting a node
// ----------------------------------------------------------------

test('B15: toArray() returns the stored array, nested maps as arrays, and [] for an empty node', static function (): void {
	$cfg = kindsCfg();
	check($cfg->pkg->deep->toArray() === ['inner' => ['leaf' => 'v']], 'nested map is an array');
	check(\is_array($cfg->pkg->toArray()['map']), 'a map below the node is an array');
	check($cfg->pkg->empty->toArray() === [], 'empty node');
});

test('B15: toArray() holds the same enum instances at the root and in a nested node', static function (): void {
	$cfg = enumCfg();
	check($cfg->toArray()['image']['format'] === BackedEnumFixture::Webp, 'toArray(): root holds the same instance');
	check($cfg->toArray()['mode'] === TraversableEnumFixture::First, 'toArray(): root holds the same IteratorAggregate instance');
	check($cfg->image->toArray() === ['format' => BackedEnumFixture::Webp, 'fit' => PureEnumFixture::Cover], 'toArray(): nested node holds the same instances');
});

test('B16: foreach yields the stored values and count() counts the keys of the node', static function (): void {
	$cfg = kindsCfg();
	$seen = [];
	foreach ($cfg->pkg->deep as $key => $value) {
		$seen[$key] = $value;
	}
	check($seen === ['inner' => ['leaf' => 'v']], 'foreach yields nested maps as arrays');
	check(\count($cfg->pkg->map) === 2, 'count() of a map');
	check(\count($cfg->pkg->empty) === 0, 'count() of an empty node');
});

test('B17: a node is not an array: (array) does not yield its data and array functions throw TypeError', static function (): void {
	$cfg = kindsCfg();
	check((array)$cfg->pkg->empty !== [], '(array) on an empty node is not []');
	expectThrows(\TypeError::class, static fn() => \in_array('x', $cfg->pkg->empty, true), '\in_array() on a node');
	expectThrows(\TypeError::class, static fn() => \array_map('strval', $cfg->pkg->empty), '\array_map() on a node');
});


// ----------------------------------------------------------------
// B18-B22: writes
// ----------------------------------------------------------------

test('B18: assigning App::$cfg after construction throws Error', static function (): void {
	$app = (new \ReflectionClass(App::class))->newInstanceWithoutConstructor();
	$first = kindsCfg();
	$other = kindsCfg();

	// Initialize the property from App's own scope, as the constructor does.
	(fn() => $this->cfg = $first)->call($app);

	expectThrows(\Error::class, static function () use ($app, $other): void {
		$app->cfg = $other;
	}, 'assignment from outside App');
	expectThrows(\Error::class, function () use ($app, $other): void {
		(fn() => $this->cfg = $other)->call($app);
	}, 'assignment from inside App');
	check($app->cfg === $first, 'the first Cfg stays');
});

test('B19: array-syntax writes and unsets throw LogicException and change nothing', static function (): void {
	$cfg = kindsCfg();
	$write = expectThrows(\LogicException::class, static function () use ($cfg): void {
		$cfg['pkg'] = 'changed';
	}, 'write');
	$unset = expectThrows(\LogicException::class, static function () use ($cfg): void {
		unset($cfg['pkg']);
	}, 'unset');
	check($write->getMessage() === 'Cfg is read-only.' && $unset->getMessage() === 'Cfg is read-only.', 'message');
	check($cfg['pkg'] instanceof Cfg, 'the node stays');
});

test('B20: changing a list obtained through a read changes nothing and raises a notice', static function (): void {
	$cfg = kindsCfg();
	expectDiagnostic(\E_NOTICE, 'Indirect modification of overloaded property', static function () use ($cfg): void {
		$cfg->pkg->list[] = 'z';
	}, 'append to a list');
	check($cfg->pkg->list === ['x', 'y'], 'the list is unchanged');
});

test('B21: property-syntax writes throw LogicException and change nothing', static function (): void {
	$cfg = kindsCfg();
	$existing = expectThrows(\LogicException::class, static function () use ($cfg): void {
		$cfg->pkg->text = 'changed';
	}, 'write to an existing key');
	$new = expectThrows(\LogicException::class, static function () use ($cfg): void {
		$cfg->pkg->fresh = 'value';
	}, 'write to a new key');
	expectThrows(\LogicException::class, static function () use ($cfg): void {
		$cfg->pkg = 'changed';
	}, 'write to a key that holds a node');
	check($existing->getMessage() === 'Cfg is read-only.' && $new->getMessage() === 'Cfg is read-only.', 'message');
	check($cfg->pkg->text === 'text', 'property syntax still reads the stored value');
	check($cfg['pkg']['text'] === 'text', 'array syntax still reads the stored value');
	check(!isset($cfg->pkg->fresh), 'no new key appears');
	check($cfg->pkg instanceof Cfg, 'the node stays');
});

test('B22: property-syntax unset throws LogicException and changes nothing', static function (): void {
	$cfg = kindsCfg();
	$e = expectThrows(\LogicException::class, static function () use ($cfg): void {
		unset($cfg->pkg->text);
	}, 'unset of an existing key');
	check($e->getMessage() === 'Cfg is read-only.', 'message');
	check($cfg->pkg->text === 'text', 'the key stays readable');
});


// ----------------------------------------------------------------
// Code forms of the rules
// ----------------------------------------------------------------

test('R2: an option over an own path wins when set and falls through to the cfg read otherwise', static function (): void {
	$cfg = kindsCfg();
	$none = [];
	$set  = ['a' => 9];
	checkRead(static fn() => $set['a'] ?? $cfg->pkg->map->a, 9, 'a set option wins');
	checkRead(static fn() => $none['a'] ?? $cfg->pkg->map->a, 1, 'a missing option reads the own path');
	expectThrows(\OutOfBoundsException::class, static fn() => $none['a'] ?? $cfg->pkg->map->missing, 'a missing own path throws');
});

test('R5: a runtime-chosen key: the map read throws when the map is missing, the lookup yields null for an unknown key', static function (): void {
	$cfg = kindsCfg();
	$profiles = $cfg->pkg->profiles;
	checkRead(static fn() => ($profiles->{'main'} ?? null) instanceof Cfg, true, 'a known key yields its node');
	checkRead(static fn() => $profiles->{'unknown'} ?? null, null, 'an unknown key yields null');
	expectThrows(\OutOfBoundsException::class, static fn() => $cfg->pkg->missing_profiles, 'a missing map throws');
});

test('R6: instanceof Cfg ? toArray() : value yields [] for an empty list and the list otherwise', static function (): void {
	$cfg = kindsCfg();
	$asList = static fn(mixed $value): mixed => $value instanceof Cfg ? $value->toArray() : $value;
	check($asList($cfg->pkg->empty) === [], 'empty list');
	check($asList($cfg->pkg->list) === ['x', 'y'], 'list');
});

done();
