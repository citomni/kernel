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
 * Pure (non-backed) enum used as a cfg value in regression scripts.
 *
 * Before the fix, get_object_vars() flattened a case of this enum into
 * ['name' => ...].
 */
enum PureEnumFixture {
	case Cover;
	case Contain;
}
