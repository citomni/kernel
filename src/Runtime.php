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

namespace CitOmni\Kernel;

/**
 * Process-global runtime configuration derived from application config.
 *
 * Sets timezone, default charset, and ICU locale based on cfg values.
 * This is not part of App because these are process-global PHP runtime
 * mutations, not application state. Each Kernel (Http, Cli) calls
 * configure() explicitly after constructing the App instance.
 *
 * Behavior:
 * - Timezone: sets date_default_timezone_set() from cfg.locale.timezone (default: 'UTC').
 * - Charset: sets ini default_charset from cfg.locale.charset (default: 'UTF-8').
 * - ICU locale: sets \Locale::setDefault() from cfg.locale.icu_locale (default: 'en_US')
 *   only when the intl extension is available. This is best-effort configuration,
 *   not requirement enforcement. Mode-specific Kernels are responsible for asserting
 *   hard intl requirements (Http\Kernel requires intl; Cli\Kernel does not).
 *
 * Notes:
 * - All three settings are idempotent: calling configure() multiple times with
 *   the same cfg produces the same result with no additional side effects.
 * - Fail-fast on invalid timezone or charset values.
 *
 * Typical usage:
 *   $app = new App($configDir, Mode::HTTP);
 *   Runtime::configure($app->cfg);
 *
 * @throws \RuntimeException  On invalid timezone, failed charset, or invalid ICU locale.
 */
final class Runtime {

	/**
	 * Apply process-global runtime settings from application config.
	 *
	 * Behavior:
	 * - Timezone and charset are enforced (fail-fast on invalid values).
	 * - ICU locale is best-effort: set when intl is available, skipped otherwise.
	 *   If the calling Kernel requires intl, it must assert that independently.
	 *
	 * @param  Cfg  $cfg  The merged application configuration.
	 * @return void
	 * @throws \RuntimeException  On invalid timezone, failed charset, or invalid ICU locale.
	 */
	public static function configure(Cfg $cfg): void {

		// -- 1. Timezone -----------------------------------------------
		$tz = (string)($cfg->locale->timezone ?? 'UTC');
		if (!@date_default_timezone_set($tz)) {
			throw new \RuntimeException('Invalid timezone: ' . $tz);
		}

		// -- 2. Default charset ----------------------------------------
		$charset = (string)($cfg->locale->charset ?? 'UTF-8');
		if (!@ini_set('default_charset', $charset) || \ini_get('default_charset') !== $charset) {
			throw new \RuntimeException('Failed to set default charset to ' . $charset);
		}

		// -- 3. ICU locale (best-effort; skip if intl not available) -----
		if (\class_exists(\Locale::class)) {
			$icu = (string)($cfg->locale->icu_locale ?? 'en_US');
			try {
				\Locale::setDefault($icu);
			} catch (\Throwable $e) {
				throw new \RuntimeException('Invalid ICU locale: ' . $icu);
			}
		}
	}
}
