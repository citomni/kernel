<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2012-2025 Lars Grove Mortensen
 *
 * CitOmni Kernel - Deterministic core for high-performance CitOmni apps.
 * Source:  https://github.com/citomni/kernel
 * License: See the LICENSE file for full terms.
 */

namespace CitOmni\Kernel\Tests\Command;

use PHPUnit\Framework\TestCase;

class BaseCommandTest extends TestCase {
	public function testClassExists(): void {
		self::assertTrue(\class_exists(\CitOmni\Kernel\Command\BaseCommand::class));
	}
}
