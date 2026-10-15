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

namespace CitOmni\Kernel\Tests\Arr;

use CitOmni\Kernel\Arr;
use CitOmni\Kernel\Tests\Fixtures\BackedEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\PureEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\TraversableEnumFixture;

/*
 * Isolated suite for \CitOmni\Kernel\Arr: enum cases survive normalizeConfig()
 * as the same instance, an enum root is rejected, other value types keep their
 * conversion, mergeAssocLastWins() treats enums like scalars, and the
 * var_export() format used by App::writeCacheAtomically() round-trips them.
 *
 * Usage:
 *   php tests/arr/run.php
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
 * A normalized cfg with enum cases at several depths, as App caches it.
 *
 * @return array<string, mixed>
 */
function cachedCfg(): array {
	return Arr::normalizeConfig([
		'image' => [
			'format' => BackedEnumFixture::Avif,
			'profiles' => ['thumb' => (object)['fit' => PureEnumFixture::Cover, 'width' => 320]],
		],
		'mode' => TraversableEnumFixture::Second,
		'formats' => [BackedEnumFixture::Webp, BackedEnumFixture::Avif],
	]);
}


/**
 * Render a cfg array the way App::writeCacheAtomically() writes a cache file.
 *
 * @param  array<string, mixed>  $cfg  Normalized cfg.
 * @return string  PHP source of the cache file.
 */
function cacheSource(array $cfg): string {
	return "<?php\nreturn " . \var_export($cfg, true) . ";\n";
}


// ----------------------------------------------------------------
// Fixture sanity
// ----------------------------------------------------------------

// The ordering checks below only prove something if this enum is Traversable.
test('TraversableEnumFixture is Traversable', static function (): void {
	check(TraversableEnumFixture::First instanceof \Traversable, 'TraversableEnumFixture::First is not Traversable');
});


// ----------------------------------------------------------------
// Enum preservation
// ----------------------------------------------------------------

test('normalizeConfig() keeps nested backed and pure enum cases as the same instance', static function (): void {
	$n = Arr::normalizeConfig(['image' => ['profile' => ['format' => BackedEnumFixture::Webp]]]);
	check($n['image']['profile']['format'] === BackedEnumFixture::Webp, 'nested backed enum is preserved');

	$n = Arr::normalizeConfig(['image' => ['fit' => PureEnumFixture::Cover]]);
	check($n['image']['fit'] === PureEnumFixture::Cover, 'nested pure enum is preserved');
});

// The fixture's getIterator() throws, so iteration would fail the case with a LogicException.
test('normalizeConfig() keeps an IteratorAggregate enum case without iterating it', static function (): void {
	$n = Arr::normalizeConfig(['mode' => TraversableEnumFixture::Second]);
	check($n['mode'] === TraversableEnumFixture::Second, 'IteratorAggregate enum is preserved, not iterated');
});

test('normalizeConfig() keeps enum cases inside a list, stdClass, and ArrayIterator', static function (): void {
	$n = Arr::normalizeConfig(['formats' => [BackedEnumFixture::Avif, BackedEnumFixture::Webp]]);
	check($n['formats'] === [BackedEnumFixture::Avif, BackedEnumFixture::Webp], 'enums inside a list are preserved');

	$n = Arr::normalizeConfig(['obj' => (object)['format' => BackedEnumFixture::Avif]]);
	check($n['obj'] === ['format' => BackedEnumFixture::Avif], 'enum inside stdClass is preserved');

	$n = Arr::normalizeConfig(['it' => new \ArrayIterator(['fit' => PureEnumFixture::Contain, 'mode' => TraversableEnumFixture::First])]);
	check($n['it'] === ['fit' => PureEnumFixture::Contain, 'mode' => TraversableEnumFixture::First], 'enums inside ArrayIterator are preserved');
});

// Same rule when the container is the root (top-level object and Traversable paths).
test('normalizeConfig() keeps enum cases inside a root stdClass and a root ArrayIterator', static function (): void {
	$n = Arr::normalizeConfig((object)['fit' => PureEnumFixture::Cover]);
	check($n === ['fit' => PureEnumFixture::Cover], 'enum inside root stdClass is preserved');

	$n = Arr::normalizeConfig(new \ArrayIterator(['format' => BackedEnumFixture::Webp]));
	check($n === ['format' => BackedEnumFixture::Webp], 'enum inside root ArrayIterator is preserved');
});


// ----------------------------------------------------------------
// Enum root is rejected
// ----------------------------------------------------------------

test('normalizeConfig() rejects an enum root and names the case', static function (): void {
	foreach ([BackedEnumFixture::Webp, PureEnumFixture::Cover, TraversableEnumFixture::First] as $case) {
		$label = $case::class . '::' . $case->name;
		$e = expectThrows(\RuntimeException::class, static fn() => Arr::normalizeConfig($case), 'enum root is rejected: ' . $label);
		check(\str_contains($e->getMessage(), $label), 'enum root message names the case: ' . $label);
	}
});


// ----------------------------------------------------------------
// Other values keep their conversion
// ----------------------------------------------------------------

test('normalizeConfig() turns stdClass, Traversable, and DTOs into arrays (deep)', static function (): void {
	$n = Arr::normalizeConfig(['db' => (object)['host' => 'localhost', 'opts' => (object)['port' => 3306]]]);
	check($n === ['db' => ['host' => 'localhost', 'opts' => ['port' => 3306]]], 'stdClass becomes an array (deep)');

	$generator = (static function (): \Generator {
		yield 'a' => 1;
		yield 'b' => new \ArrayObject(['c' => 2]);
	})();
	$n = Arr::normalizeConfig(['list' => new \ArrayObject([1, 2, 3]), 'gen' => $generator]);
	check($n === ['list' => [1, 2, 3], 'gen' => ['a' => 1, 'b' => ['c' => 2]]], 'Traversable becomes an array (deep)');

	$dto = new class {
		public string $host = 'localhost';
		public int $port = 3306;
		public array $tags = ['a', 'b'];
		private string $secret = 'hidden';
	};
	$n = Arr::normalizeConfig(['dto' => $dto]);
	check($n === ['dto' => ['host' => 'localhost', 'port' => 3306, 'tags' => ['a', 'b']]], 'DTO becomes an array of its public properties');
});

test('normalizeConfig() leaves scalars, lists, and empty arrays unchanged', static function (): void {
	$scalars = ['s' => 'x', 'i' => 1, 'f' => 1.5, 't' => true, 'b' => false, 'n' => null, 'list' => [1, 'two', 3.0], 'empty' => []];
	check(Arr::normalizeConfig($scalars) === $scalars, 'scalars, lists, and empty arrays are unchanged');
});

test('normalizeConfig() rejects a scalar root', static function (): void {
	foreach (['oops', 42, 1.5, true, null] as $root) {
		expectThrows(\RuntimeException::class, static fn() => Arr::normalizeConfig($root), 'scalar root is rejected: ' . \var_export($root, true));
	}
});


// ----------------------------------------------------------------
// Merge: enums behave like scalars
// ----------------------------------------------------------------

test('mergeAssocLastWins() replaces enum cases and arrays like scalars', static function (): void {
	$m = Arr::mergeAssocLastWins(['image' => ['fit' => ['mode' => 'cover']]], ['image' => ['fit' => PureEnumFixture::Contain]]);
	check($m === ['image' => ['fit' => PureEnumFixture::Contain]], 'merge: enum replaces an array');

	$m = Arr::mergeAssocLastWins(['image' => ['fit' => PureEnumFixture::Contain]], ['image' => ['fit' => ['mode' => 'cover']]]);
	check($m === ['image' => ['fit' => ['mode' => 'cover']]], 'merge: array replaces an enum');

	$m = Arr::mergeAssocLastWins(['image' => ['format' => BackedEnumFixture::Webp, 'quality' => 82]], ['image' => ['format' => BackedEnumFixture::Avif]]);
	check($m === ['image' => ['format' => BackedEnumFixture::Avif, 'quality' => 82]], 'merge: enum replaces an enum, siblings are kept');

	$m = Arr::mergeAssocLastWins(['mode' => TraversableEnumFixture::First], ['mode' => PureEnumFixture::Cover]);
	check($m === ['mode' => PureEnumFixture::Cover], 'merge: enum of another type replaces an enum');
});

// The pipeline App::buildConfig() runs: normalize each layer, then merge it in.
test('layered normalizeConfig() and mergeAssocLastWins() keep enum cases', static function (): void {
	$cfg = Arr::normalizeConfig(['image' => ['format' => BackedEnumFixture::Webp, 'fit' => PureEnumFixture::Cover]]);
	$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig((object)['image' => (object)['fit' => PureEnumFixture::Contain]]));
	$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig(['image' => ['quality' => 70]]));
	check($cfg === ['image' => ['format' => BackedEnumFixture::Webp, 'fit' => PureEnumFixture::Contain, 'quality' => 70]], 'layered normalize + merge keeps enums');
});


// ----------------------------------------------------------------
// Cache round-trip (App::writeCacheAtomically() format)
// ----------------------------------------------------------------

test('var_export() writes enum cases as class-constant references', static function (): void {
	$code = cacheSource(cachedCfg());
	check(\str_contains($code, BackedEnumFixture::class . '::Avif') && !\str_contains($code, '__set_state'), 'var_export() writes enum cases as class-constant references');
});

test('a cache file round-trips enum cases as the same instances', static function (): void {
	$cfg  = cachedCfg();
	$file = tempDir() . '/cfg.http.php';
	\file_put_contents($file, cacheSource($cfg));
	$loaded = require $file;

	check($loaded['image']['format'] === BackedEnumFixture::Avif, 'cache round-trip: backed enum');
	check($loaded['image']['profiles']['thumb']['fit'] === PureEnumFixture::Cover, 'cache round-trip: pure enum');
	check($loaded['mode'] === TraversableEnumFixture::Second, 'cache round-trip: IteratorAggregate enum');
	check($loaded['formats'] === [BackedEnumFixture::Webp, BackedEnumFixture::Avif], 'cache round-trip: enums inside a list');
	check($loaded === $cfg, 'cache round-trip: whole array is identical');
});

done();
