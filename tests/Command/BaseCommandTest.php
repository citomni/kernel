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

namespace CitOmni\Kernel\Tests\Command;

use PHPUnit\Framework\TestCase;

class BaseCommandTest extends TestCase {
	public function testClassExists(): void {
		self::assertTrue(\class_exists(\CitOmni\Kernel\Command\BaseCommand::class));
	}
}
