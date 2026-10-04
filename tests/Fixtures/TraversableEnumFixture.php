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

namespace CitOmni\Kernel\Tests\Fixtures;

/**
 * Enum that is also Traversable (via IteratorAggregate).
 *
 * Proves that Arr checks \UnitEnum before \Traversable: a case must be kept
 * as a leaf value, never expanded through iterator_to_array().
 *
 * Behavior:
 * - getIterator() always throws, so any accidental iteration surfaces as a
 *   \LogicException instead of a silently converted array.
 */
enum TraversableEnumFixture implements \IteratorAggregate {
	case First;
	case Second;

	/**
	 * Refuse iteration; the regression scripts treat any call as a bug.
	 *
	 * @return never
	 * @throws \LogicException Always.
	 */
	public function getIterator(): never {
		throw new \LogicException('TraversableEnumFixture::' . $this->name . ' must not be iterated.');
	}
}
