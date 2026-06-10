<?php
declare(strict_types=1);
/*
 * SPDX-License-Identifier: MIT
 * Copyright (C) 2012-present Lars Grove Mortensen
 *
 * CitOmni Kernel - Common service map scaffold.
 * Source:  https://github.com/citomni/kernel
 * License: See the LICENSE file for full terms.
 */

/**
 * COMMON APPLICATION SERVICE MAP
 * ------------------------------------------------------------------
 * Deterministic app-level service wiring by id, shared by HTTP and CLI.
 *
 * This file is the app-owned common service map. It is loaded in both HTTP and CLI
 * modes, then mode-specific service maps may override it by id.
 *
 * Contract:
 *   - This file is loaded in both HTTP and CLI mode.
 *   - Keys are service ids accessed as $this->app->{id}.
 *   - Values may be:
 *       1) FQCN string
 *       2) ['class' => FQCN, 'options' => [...]]
 *   - Services are resolved as singletons per request/process.
 *   - Service constructors must follow:
 *       new Service(App $app, array $options = []).
 *
 * Effective HTTP precedence (services, left wins via PHP array union +):
 *   1) App HTTP services: /config/services_http.php
 *   2) App common services: /config/services.php
 *   3) Provider #N MAP_HTTP
 *   4) Provider #N MAP_COMMON
 *   5) Earlier provider service maps, same mode/common pattern
 *   6) Vendor HTTP baseline: MAP_HTTP from citomni/http
 *
 * Effective CLI precedence (services, left wins via PHP array union +):
 *   1) App CLI services: /config/services_cli.php
 *   2) App common services: /config/services.php
 *   3) Provider #N MAP_CLI
 *   4) Provider #N MAP_COMMON
 *   5) Earlier provider service maps, same mode/common pattern
 *   6) Vendor CLI baseline: MAP_CLI from citomni/cli
 *
 * Policy:
 *   - Keep this file minimal.
 *   - Only declare app services shared by both HTTP and CLI, or deliberate common overrides.
 *   - Put HTTP-only services in /config/services_http.php.
 *   - Put CLI-only services in /config/services_cli.php.
 *   - Do not mirror vendor or provider service maps here.
 *   - Keep definitions explicit and deterministic.
 *   - Pure data only. No logic, closures, factories, or side effects.
 *
 * Notes:
 *   - Order inside this array does not matter for resolution.
 *   - Duplicate keys in this file follow normal PHP array rules.
 *   - Invalid service classes or definitions should fail fast by design.
 */
return [

	/*
	|--------------------------------------------------------------------------
	| Example: App-owned common service
	|--------------------------------------------------------------------------
	*/
	// 'myService' => \App\Service\MyService::class,

	/*
	|--------------------------------------------------------------------------
	| Example: App-owned common service with options
	|--------------------------------------------------------------------------
	*/
	// 'myServiceWithOptions' => [
	// 	'class' => \App\Service\MyService::class,
	// 	'options' => [
	// 		'example' => true,
	// 	],
	// ],

];
