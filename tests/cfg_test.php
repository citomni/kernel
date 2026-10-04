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
 * Regression checks for Cfg: enum cases come back unchanged through property
 * access, array access, and toArray(), and unknown keys still fail fast.
 * The wrapper is built the way App builds it: a normalized array in a Cfg.
 */

require __DIR__ . '/bootstrap.php';

use CitOmni\Kernel\Arr;
use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Tests\Fixtures\BackedEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\PureEnumFixture;
use CitOmni\Kernel\Tests\Fixtures\TraversableEnumFixture;

$cfg = new Cfg(Arr::normalizeConfig([
	'image' => [
		'format' => BackedEnumFixture::Webp,
		'fit' => PureEnumFixture::Cover,
	],
	'mode' => TraversableEnumFixture::First,
]));

// -- __get() ----------------------------------------------------------------

check($cfg->image->format === BackedEnumFixture::Webp, '__get(): backed enum is returned unchanged');
check($cfg->image->fit === PureEnumFixture::Cover, '__get(): pure enum is returned unchanged');
check($cfg->mode === TraversableEnumFixture::First, '__get(): IteratorAggregate enum is returned unchanged, not wrapped');

// -- offsetGet() ------------------------------------------------------------

check($cfg['image']['format'] === BackedEnumFixture::Webp, 'offsetGet(): backed enum is returned unchanged');
check($cfg['image']['fit'] === PureEnumFixture::Cover, 'offsetGet(): pure enum is returned unchanged');
check($cfg['mode'] === TraversableEnumFixture::First, 'offsetGet(): IteratorAggregate enum is returned unchanged, not wrapped');

// -- toArray() --------------------------------------------------------------

check($cfg->toArray()['image']['format'] === BackedEnumFixture::Webp, 'toArray(): root holds the same instance');
check($cfg->toArray()['mode'] === TraversableEnumFixture::First, 'toArray(): root holds the same IteratorAggregate instance');
check($cfg->image->toArray() === ['format' => BackedEnumFixture::Webp, 'fit' => PureEnumFixture::Cover], 'toArray(): nested node holds the same instances');

// -- Unknown keys still fail fast -------------------------------------------

expectThrows(\OutOfBoundsException::class, static fn() => $cfg->missing, '__get(): unknown root key');
expectThrows(\OutOfBoundsException::class, static fn() => $cfg->image->missing, '__get(): unknown nested key');
expectThrows(\OutOfBoundsException::class, static fn() => $cfg['missing'], 'offsetGet(): unknown root key');
expectThrows(\OutOfBoundsException::class, static fn() => $cfg['image']['missing'], 'offsetGet(): unknown nested key');

done('cfg_test');
