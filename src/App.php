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

use CitOmni\Kernel\Cfg;
use CitOmni\Kernel\Arr;

/**
 * App: Deterministic application kernel for config, dispatch maps, and service assembly.
 *
 * Responsibilities:
 *
 * CONFIG
 * - Build the final configuration with a predictable merge order ("last wins"):
 *   1) Vendor baseline (\CitOmni\{Http|Cli}\Boot\Registry::CFG_{HTTP|CLI})
 *   2) Provider cfg overlays (listed in /config/providers.php; CFG_HTTP | CFG_CLI)
 *   3) App base cfg (/config/citomni_{http|cli}_cfg.php)
 *   4) Env overlay (/config/citomni_{http|cli}_cfg.{ENV}.php) [optional]
 * - Expose the merged configuration as $this->cfg, which is a deep, read-only Cfg wrapper.
 *
 * DISPATCH MAPS (routes and commands)
 * - HTTP mode builds a route table exposed as $this->routes (keyed by path).
 *   Merge order: vendor ROUTES_HTTP -> provider ROUTES_HTTP -> app base -> env overlay.
 * - CLI mode builds a command table exposed as $this->commands (keyed by command name).
 *   Merge order: vendor COMMANDS_CLI -> provider COMMANDS_CLI -> app base -> env overlay.
 * - Both use the same deterministic "last wins" merge via buildDispatchMap().
 *   Non-array return values from any source fail fast. Empty arrays are skipped
 *   instead of wiping previously merged entries.
 * - In HTTP mode, $this->commands is []. In CLI mode, $this->routes is [].
 *   This is a semantic separation: each mode carries only its own dispatch
 *   vocabulary. The overhead difference is negligible.
 *
 * SERVICES
 * - Build the final service map with deterministic precedence (PHP array union; left side wins):
 *   1) Vendor baseline (\CitOmni\{Http|Cli}\Boot\Registry::MAP_{HTTP|CLI})
 *   2) Provider MAP_{HTTP|CLI} constants from classes listed in /config/providers.php (overrides vendor)
 *   3) /config/services.php (overrides everything else)
 *
 *   Effective precedence is:
 *   - app > provider > vendor
 *
 *   The final map is stored internally. Access is via $this->app->serviceId,
 *   e.g. $this->app->log, $this->app->request, etc.
 *
 * CACHING
 * - Prefer precompiled caches to reduce runtime work:
 *   <appRoot>/var/cache/cfg.{http|cli}.php
 *   <appRoot>/var/cache/routes.http.php        (HTTP only)
 *   <appRoot>/var/cache/commands.cli.php        (CLI only)
 *   <appRoot>/var/cache/services.{http|cli}.php
 *
 *   Each cache file must be side-effect-free and return a plain array.
 *   The constructor will consume these if present; otherwise it rebuilds.
 *   Non-array returns from cache files are treated as cache-miss (fallback to build).
 *
 * MODE CONTRACT
 * - Mode::HTTP requires citomni/http (specifically \CitOmni\Http\Boot\Registry).
 * - Mode::CLI requires citomni/cli (specifically \CitOmni\Cli\Boot\Registry).
 * - Mode::HTTP does NOT require citomni/cli, and Mode::CLI does NOT require citomni/http.
 *   Each mode only references its own adapter package's Registry class.
 *
 * CAPABILITY HELPERS
 * - Provide zero-I/O helpers for feature discovery:
 *   hasService(), hasAnyService(), hasPackage(), hasNamespace(), vardumpServices()
 *
 * Collaborators:
 * - \CitOmni\Http\Boot\Registry  (CFG_HTTP, ROUTES_HTTP, MAP_HTTP)
 * - \CitOmni\Cli\Boot\Registry   (CFG_CLI, COMMANDS_CLI, MAP_CLI)
 * - Provider classes listed in /config/providers.php
 * - PHP OPcache (optional) for atomic cache updates
 * - Constants: CITOMNI_APP_PATH (required), CITOMNI_ENVIRONMENT (optional, e.g. "dev", "stage", "prod")
 *
 * Error handling:
 * - Fail fast on:
 *   - Missing or invalid config dir
 *   - Malformed providers.php
 *   - Missing provider classes referenced in providers.php
 *   - Non-array return types from cfg, dispatch, or services sources
 *   - Non-array provider MAP/dispatch constants
 *   - Cache write or move failures in warmCache()
 * - This class does not catch exceptions; errors bubble to the global handler.
 *
 * Typical usage:
 *
 *   // HTTP boot (recommended)
 *   define('CITOMNI_APP_PATH', __DIR__);          // app root (no trailing slash)
 *   require __DIR__ . '/vendor/autoload.php';
 *   \CitOmni\Http\Kernel::run(__DIR__ . '/public');
 *
 *   // CLI boot (recommended)
 *   define('CITOMNI_APP_PATH', __DIR__);
 *   require __DIR__ . '/vendor/autoload.php';
 *   \CitOmni\Cli\Kernel::run(__DIR__ . '/config', $argv);
 *
 *   // Manual construction (tests / scripts)
 *   $app = new \CitOmni\Kernel\App(__DIR__ . '/config', \CitOmni\Kernel\Mode::HTTP);
 *   $baseUrl = $app->cfg->http->base_url ?? null; // deep, read-only cfg
 *   $view    = $app->view;                        // lazy-resolved service from map
 *
 * Examples:
 *
 *   // Access nested cfg (read-only wrapper):
 *   $tz = $app->cfg->locale->timezone ?? 'UTC';
 *
 *   // Access dispatch maps directly:
 *   $homeCtrl = $app->routes['/']['controller'] ?? null;   // HTTP
 *   $cmdDef   = $app->commands['cache:warm'] ?? null;      // CLI
 *
 *   // Capability checks (zero I/O):
 *   if ($app->hasPackage('citomni/auth')) {
 *       // auth UI available
 *   }
 *   if ($app->hasNamespace('\CitOmni\Infrastructure')) {
 *       // enable infra tools
 *   }
 *
 *   // Warm caches for current env:
 *   $written = $app->warmCache();
 *
 *   // Warm caches targeting prod (e.g. from a dev deploy script):
 *   $written = $app->warmCache(env: 'prod');
 *
 * Failure modes:
 *
 *   // 1) Non-existent /config dir:
 *   new \CitOmni\Kernel\App('/bad/path/config', \CitOmni\Kernel\Mode::HTTP);
 *   // => \RuntimeException
 *
 *   // 2) Malformed providers.php:
 *   // returns non-array or references a missing class
 *   // => \RuntimeException
 *
 *   // 3) Dispatch or services source returns non-array:
 *   // => \RuntimeException (fail-fast, not silently ignored)
 *
 * Standalone (minimal CLI):
 *
 *   define('CITOMNI_APP_PATH', __DIR__);
 *   require __DIR__ . '/vendor/autoload.php';
 *   $app = new \CitOmni\Kernel\App(__DIR__ . '/config', \CitOmni\Kernel\Mode::CLI);
 *   $app->warmCache(); // compile cfg, commands, and services caches for CLI mode
 */
final class App {

	/** Public read-only configuration. */
	public readonly Cfg $cfg;

	/** Public read-only HTTP route table (plain array). Empty in CLI mode. */
	public readonly array $routes;

	/** Public read-only CLI command table (plain array). Empty in HTTP mode. */
	public readonly array $commands;

	/** Absolute config dir (.../config). */
	private string $configDir;

	/** Current delivery mode. */
	private Mode $mode;

	/**
	 * Authoritative services definition: id => FQCN|string|array{class:string,options?:array}.
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Per-run singleton instances cache.
	 * @var array<string, object>
	 */
	private array $instances = [];

	/**
	 * Memoization for hasPackage() per App instance.
	 * @var array<string, bool>
	 */
	private array $packageMemo = [];

	/**
	 * Memoization for loadProviders() - avoids redundant I/O and validation.
	 * @var ?string[]
	 */
	private ?array $providersMemo = null;


	/**
	 * Application bootstrap.
	 *
	 * Initializes the application with a given configuration directory and mode (HTTP|CLI).
	 * This constructor prefers precompiled cache artifacts (if present) to minimize
	 * runtime overhead. If a cache file is missing or invalid, it falls back to the
	 * normal build pipeline.
	 *
	 * Behavior:
	 * - Resolves mode-specific cache file paths via cacheFilePaths().
	 * - For each artifact (cfg, dispatch map, services), attempts to load a compiled
	 *   cache via loadCacheArray(). Cache files must return a plain array - any other
	 *   return type is treated as cache-miss and triggers a rebuild.
	 * - Wraps the merged configuration in a deep, read-only Cfg wrapper.
	 * - Loads the mode-appropriate dispatch map:
	 *   HTTP: routes from routes.http.php cache or buildRoutes()
	 *   CLI: commands from commands.cli.php cache or buildCommands()
	 *   The inactive dispatch map is set to an empty array.
	 * - Loads the services map from cache or buildServices().
	 *
	 * Notes:
	 * - Cache files are expected to be generated by warmCache() and written atomically.
	 *   This constructor does not write caches; it only consumes them when present.
	 * - Cache scripts should contain no side effects beyond `return [ ... ];` to keep
	 *   OPcache stable and memory usage low.
	 * - When OPcache is enabled with validate_timestamps=0 in production, remember to
	 *   call opcache_reset() as part of the deploy step after refreshing cache files.
	 * - No exceptions are caught here by design; any underlying failures are allowed
	 *   to propagate to the global error handler.
	 *
	 * @param  string  $configDir  Absolute path to the application's /config directory.
	 * @param  Mode    $mode       Execution mode (Mode::HTTP or Mode::CLI).
	 */
	public function __construct(string $configDir, Mode $mode) {

		$cfgDir = \rtrim($configDir, \DIRECTORY_SEPARATOR);
		$cfgDirReal = \realpath($cfgDir);
		if ($cfgDirReal === false) {
			throw new \RuntimeException("Config directory not found: {$cfgDir}");
		}

		$this->configDir = $cfgDirReal;
		$this->mode      = $mode;

		$paths = $this->cacheFilePaths();

		// -- 1. Load configuration (prefer compiled cache) ----------------
		$this->cfg = new Cfg($this->loadCacheArray($paths['cfg']) ?? $this->buildConfig());

		// -- 2. Load dispatch map (prefer compiled cache) -----------------
		$dispatchArray = $this->loadCacheArray($paths['dispatch']);

		if ($mode === Mode::HTTP) {
			$this->routes   = $dispatchArray ?? $this->buildRoutes();
			$this->commands = [];
		} else {
			$this->routes   = [];
			$this->commands = $dispatchArray ?? $this->buildCommands();
		}

		// -- 3. Load services map (prefer compiled cache) -----------------
		$this->services = $this->loadCacheArray($paths['services']) ?? $this->buildServices();
	}


	// ----------------------------------------------------------------
	// Service resolution
	// ----------------------------------------------------------------

	/**
	 * Magic accessor for lazily resolved application services.
	 *
	 * Enables property-style access for services registered in the service map:
	 *   $this->app->log
	 *   $this->app->router
	 *   $this->app->response
	 *
	 * Note:
	 * - Declared public properties such as $this->cfg are resolved directly by PHP
	 *   and do not pass through __get().
	 *
	 * Resolution strategy:
	 * 1. **Cached instance** - If the service was already constructed, return it
	 *    from $this->instances.
	 * 2. **Service definition lookup** - The service must exist in $this->services.
	 *    - String definition: treated as FQCN and instantiated as `new $class($this)`.
	 *    - Array definition: must contain 'class' and may contain 'options';
	 *      instantiated as `new $class($this, $options)`.
	 * 3. **Failure** - Throw RuntimeException for unknown or invalid definitions.
	 *
	 * @param  string  $id  Service identifier.
	 * @return object  Resolved service instance.
	 * @throws \RuntimeException  If the service is unknown or its definition is invalid.
	 */
	public function __get(string $id): object {

		// 1) Return cached instance if already constructed
		if (isset($this->instances[$id])) {
			return $this->instances[$id];
		}

		// 2) Fail-fast if service ID not defined in services map
		if (!isset($this->services[$id])) {
			throw new \RuntimeException("Unknown app component: app->{$id}");
		}

		$def = $this->services[$id];

		// 3) Build new instance based on service definition
		if (\is_string($def)) {
			$class = $def;
			$instance = new $class($this);
		} elseif (\is_array($def) && isset($def['class']) && \is_string($def['class'])) {
			$class   = $def['class'];
			$options = $def['options'] ?? [];
			$instance = new $class($this, $options);
		} else {
			throw new \RuntimeException("Invalid service definition for '{$id}'");
		}

		return $this->instances[$id] = $instance;
	}


	// ----------------------------------------------------------------
	// Path accessors
	// ----------------------------------------------------------------

	/** Absolute path to the application root. */
	public function getAppRoot(): string {
		return CITOMNI_APP_PATH;
	}

	/** Absolute path to the /config directory. */
	public function getConfigDir(): string {
		return $this->configDir;
	}


	// ----------------------------------------------------------------
	// Config builder
	// ----------------------------------------------------------------

	/**
	 * Build runtime configuration (deterministic, fail-fast).
	 *
	 * Merge order ("last wins" for associative keys):
	 *   1) Mode baseline (vendor): \CitOmni\Http\Boot\Registry::CFG_HTTP | \CitOmni\Cli\Boot\Registry::CFG_CLI
	 *   2) Providers (listed in /config/providers.php): merge CFG_HTTP|CFG_CLI constants
	 *   3) App base cfg: /config/citomni_{http|cli}_cfg.php
	 *   4) App env overlay: /config/citomni_{http|cli}_cfg.{ENV}.php  ← last wins
	 *
	 * Behavior:
	 * - Pure read path: includes local config files and merges them; no cache writes, no side effects.
	 * - Fail fast on invalid provider list entries or missing provider classes.
	 * - Normalizes each included structure before merging (consistent array/object handling).
	 * - If $env is null, the effective environment is taken from CITOMNI_ENVIRONMENT (default "prod").
	 *   Otherwise, the provided $env ("dev"|"stage"|"prod") is used to synthesize that env's config.
	 *
	 * Notes:
	 * - Intended for boot and dev-only introspection. At runtime, prefer $this->cfg for reads.
	 * - Merge is associative, recursive, and deterministic (providers order as listed in providers.php).
	 *
	 * Typical usage:
	 *   // Normal boot (uses CITOMNI_ENVIRONMENT):
	 *   $cfg = $this->buildConfig();
	 *
	 *   // From dev, synthesize prod without exposing prod endpoints:
	 *   $prodCfg = $this->buildConfig('prod');
	 *
	 * @param  string|null  $env  Environment selector: 'dev'|'stage'|'prod' or null to read from CITOMNI_ENVIRONMENT.
	 * @return array<string, mixed>  Fully merged configuration for the chosen environment.
	 * @throws \RuntimeException  If providers.php is invalid or a listed provider class cannot be found.
	 */
	public function buildConfig(?string $env = null): array {
		$mode = $this->mode;

		// -- 1. Mode baseline (normalize once) ----------------------------
		$base = match ($mode) {
			Mode::HTTP => \CitOmni\Http\Boot\Registry::CFG_HTTP,
			Mode::CLI  => \CitOmni\Cli\Boot\Registry::CFG_CLI,
		};
		$cfg = Arr::normalizeConfig($base);

		// -- 2. Provider overlays (fail-fast, last wins) ------------------
		// Cfg sources are normalized via Arr::normalizeConfig() without a
		// preceding array typecheck. This is intentional: cfg historically
		// accepts arrays, stdClass, and Traversable (e.g. JSON-decoded
		// objects). normalizeConfig() handles the conversion. Dispatch and
		// services sources are stricter (array-only with fail-fast) because
		// they have always been plain arrays by convention.
		$providers = $this->loadProviders();

		$constName = ($mode === Mode::HTTP) ? 'CFG_HTTP' : 'CFG_CLI';
		foreach ($providers as $fqcn) {
			$constFq = $fqcn . '::' . $constName;
			if (\defined($constFq)) {
				$pv  = \constant($constFq);
				$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($pv));
			}
		}

		// -- 3. App base cfg (I/O: 1 include) ----------------------------
		$appBaseFile = $this->configDir . ($mode === Mode::HTTP ? '/citomni_http_cfg.php' : '/citomni_cli_cfg.php');
		if (\is_file($appBaseFile)) {
			$appCfg = require $appBaseFile;
			$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($appCfg));
		}

		// -- 4. App env overlay (I/O: 1 include) -------------------------
		$useEnv = $env ?? (\defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod');
		$appEnvFile = $this->configDir . ($mode === Mode::HTTP
			? "/citomni_http_cfg.{$useEnv}.php"
			: "/citomni_cli_cfg.{$useEnv}.php"
		);
		if (\is_file($appEnvFile)) {
			$envCfg = require $appEnvFile;
			$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($envCfg));
		}

		return $cfg;
	}


	// ----------------------------------------------------------------
	// Dispatch map builders (routes + commands)
	// ----------------------------------------------------------------

	/**
	 * Build the HTTP route table (deterministic, fail-fast).
	 *
	 * Merge order ("last wins" for associative keys):
	 *   1) Vendor baseline: \CitOmni\Http\Boot\Registry::ROUTES_HTTP
	 *   2) Providers listed in /config/providers.php: ROUTES_HTTP
	 *   3) App base routes: /config/citomni_http_routes.php
	 *   4) App env overlay: /config/citomni_http_routes.{ENV}.php
	 *
	 * Notes:
	 * - Structure returned here MUST be an array shaped exactly as Router expects
	 *   (e.g. ['/path' => [...], 'regex' => [ ... ] ]).
	 * - Pure read path; no cache writes and no side effects.
	 *
	 * @param  string|null  $env  Environment selector or null to use CITOMNI_ENVIRONMENT.
	 * @return array<string, mixed>  Fully merged route table.
	 * @throws \RuntimeException  If providers.php is invalid, provider class missing, or a source returns non-array.
	 */
	private function buildRoutes(?string $env = null): array {
		return $this->buildDispatchMap(
			\CitOmni\Http\Boot\Registry::ROUTES_HTTP,
			'ROUTES_HTTP',
			'citomni_http_routes',
			$env
		);
	}

	/**
	 * Build the CLI command table (deterministic, fail-fast).
	 *
	 * Merge order ("last wins" for associative keys):
	 *   1) Vendor baseline: \CitOmni\Cli\Boot\Registry::COMMANDS_CLI
	 *   2) Providers listed in /config/providers.php: COMMANDS_CLI
	 *   3) App base commands: /config/citomni_cli_commands.php
	 *   4) App env overlay: /config/citomni_cli_commands.{ENV}.php
	 *
	 * Notes:
	 * - Structure returned here MUST be an array shaped as Runner expects
	 *   (e.g. ['cache:warm' => ['command' => FQCN, ...], ...]).
	 * - Pure read path; no cache writes and no side effects.
	 *
	 * @param  string|null  $env  Environment selector or null to use CITOMNI_ENVIRONMENT.
	 * @return array<string, mixed>  Fully merged command table.
	 * @throws \RuntimeException  If providers.php is invalid, provider class missing, or a source returns non-array.
	 */
	private function buildCommands(?string $env = null): array {
		return $this->buildDispatchMap(
			\CitOmni\Cli\Boot\Registry::COMMANDS_CLI,
			'COMMANDS_CLI',
			'citomni_cli_commands',
			$env
		);
	}

	/**
	 * Shared dispatch map builder for routes (HTTP) and commands (CLI).
	 *
	 * Both dispatch maps follow the same deterministic merge pipeline:
	 *   1) Vendor baseline (passed in)
	 *   2) Provider constants (by $registryConst name)
	 *   3) App base file (/config/{$filePrefix}.php)
	 *   4) App env overlay (/config/{$filePrefix}.{ENV}.php)
	 *
	 * Merge rule is "last wins" per associative key. Empty arrays are skipped
	 * instead of wiping previously merged entries.
	 *
	 * Behavior:
	 * - Fail-fast on non-array returns from provider constants, app base, and env overlay files.
	 * - If $env is null, the effective environment is taken from CITOMNI_ENVIRONMENT (default "prod").
	 *
	 * @param  array<string, mixed>  $vendorBaseline  Baseline entries from the vendor Registry constant.
	 * @param  string                $registryConst   Constant name to read from providers (e.g. 'ROUTES_HTTP', 'COMMANDS_CLI').
	 * @param  string                $filePrefix      Config file prefix (e.g. 'citomni_http_routes', 'citomni_cli_commands').
	 * @param  string|null           $env             Environment selector or null to use CITOMNI_ENVIRONMENT.
	 * @return array<string, mixed>  Fully merged dispatch map.
	 * @throws \RuntimeException  If providers.php is invalid, provider class missing, or a source returns non-array.
	 */
	private function buildDispatchMap(array $vendorBaseline, string $registryConst, string $filePrefix, ?string $env = null): array {

		// -- 1. Vendor baseline ----------------------------------------
		$map = [];
		if ($vendorBaseline !== []) {
			$map = Arr::mergeAssocLastWins($map, Arr::normalizeConfig($vendorBaseline));
		}

		// -- 2. Provider overlays (fail-fast on non-array) -------------
		$providers = $this->loadProviders();

		foreach ($providers as $fqcn) {
			$constFq = $fqcn . '::' . $registryConst;
			if (\defined($constFq)) {
				$pvEntries = \constant($constFq);
				if (!\is_array($pvEntries)) {
					throw new \RuntimeException("Provider {$fqcn}::{$registryConst} must be an array.");
				}
				if ($pvEntries !== []) {
					$map = Arr::mergeAssocLastWins(
						$map,
						Arr::normalizeConfig($pvEntries)
					);
				}
			}
		}

		// -- 3. App base file (fail-fast on non-array) -----------------
		$appBaseFile = $this->configDir . '/' . $filePrefix . '.php';
		if (\is_file($appBaseFile)) {
			$appEntries = require $appBaseFile;
			if (!\is_array($appEntries)) {
				throw new \RuntimeException("Dispatch map file must return an array: {$appBaseFile}");
			}
			if ($appEntries !== []) {
				$map = Arr::mergeAssocLastWins(
					$map,
					Arr::normalizeConfig($appEntries)
				);
			}
		}

		// -- 4. App env overlay (fail-fast on non-array) ---------------
		$useEnv = $env ?? (\defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod');
		$appEnvFile = $this->configDir . '/' . $filePrefix . '.' . $useEnv . '.php';
		if (\is_file($appEnvFile)) {
			$appEnvEntries = require $appEnvFile;
			if (!\is_array($appEnvEntries)) {
				throw new \RuntimeException("Dispatch map file must return an array: {$appEnvFile}");
			}
			if ($appEnvEntries !== []) {
				$map = Arr::mergeAssocLastWins(
					$map,
					Arr::normalizeConfig($appEnvEntries)
				);
			}
		}

		return $map;
	}


	// ----------------------------------------------------------------
	// Services builder
	// ----------------------------------------------------------------

	/**
	 * Build the final services map for the application.
	 *
	 * Merge precedence (deterministic PHP array union; left side wins on key collision):
	 * 1. **Mode baseline** - static vendor defaults from
	 *    \CitOmni\Http\Boot\Registry::MAP_HTTP or
	 *    \CitOmni\Cli\Boot\Registry::MAP_CLI depending on runtime mode.
	 * 2. **Providers** - external providers listed in /config/providers.php.
	 *    Each provider class may define a MAP_HTTP or MAP_CLI constant.
	 *    If present, those entries override vendor baseline for same IDs.
	 * 3. **App overrides** - application-specific service definitions
	 *    from /config/services.php. These always win.
	 *
	 * Merge order via array union (left side wins on key collision):
	 *    app + provider + vendor
	 *
	 * Notes:
	 * - The services map has no env overlay. Service wiring is identical across environments.
	 *   Environment-specific behavior is controlled via cfg values, not service identity.
	 *
	 * @return array<string, mixed>  Final merged service map.
	 * @throws \RuntimeException  If vendor baseline, provider, or app map is not an array.
	 */
	private function buildServices(): array {
		$mode = $this->mode;

		// 1) Vendor baseline
		$map = match ($mode) {
			Mode::HTTP => \CitOmni\Http\Boot\Registry::MAP_HTTP,
			Mode::CLI  => \CitOmni\Cli\Boot\Registry::MAP_CLI,
		};
		if (!\is_array($map)) {
			throw new \RuntimeException('Vendor Registry::MAP_{HTTP|CLI} must be an array.');
		}

		// 2) Provider overlays (union: provider wins over vendor)
		$providers = $this->loadProviders();
		$const = ($mode === Mode::HTTP) ? 'MAP_HTTP' : 'MAP_CLI';

		foreach ($providers as $fqcn) {
			$constFq = $fqcn . '::' . $const;
			if (\defined($constFq)) {
				$pvmap = \constant($constFq);
				if (!\is_array($pvmap)) {
					throw new \RuntimeException("Provider {$fqcn}::{$const} must be an array.");
				}
				$map = $pvmap + $map;
			}
		}

		// 3) App overrides (union: app wins over everything)
		$appMapFile = $this->configDir . '/services.php';
		if (\is_file($appMapFile)) {
			$appMap = require $appMapFile;
			if (!\is_array($appMap)) {
				throw new \RuntimeException('services.php must return an array.');
			}
			$map = $appMap + $map;
		}

		return $map;
	}


	// ----------------------------------------------------------------
	// Provider loader (shared by config, dispatch, and services builders)
	// ----------------------------------------------------------------

	/**
	 * Load and validate the provider list from /config/providers.php.
	 *
	 * Each entry must be a non-empty FQCN string pointing to an existing class.
	 * Returns an empty array if the file does not exist. Memoized per App instance
	 * to avoid redundant I/O and validation across buildConfig(), buildDispatchMap(),
	 * and buildServices().
	 *
	 * @return string[]  Validated list of provider FQCNs.
	 * @throws \RuntimeException  If the file returns a non-array or contains invalid entries.
	 */
	private function loadProviders(): array {
		if ($this->providersMemo !== null) {
			return $this->providersMemo;
		}

		$providersFile = $this->configDir . '/providers.php';
		$providers = \is_file($providersFile) ? require $providersFile : [];
		if (!\is_array($providers)) {
			throw new \RuntimeException('providers.php must return an array of FQCN strings.');
		}

		foreach ($providers as $fqcn) {
			if (!\is_string($fqcn) || $fqcn === '') {
				throw new \RuntimeException('Invalid provider FQCN in providers.php');
			}
			if (!\class_exists($fqcn)) {
				throw new \RuntimeException("Provider class not found: {$fqcn}");
			}
		}

		return $this->providersMemo = $providers;
	}


	// ----------------------------------------------------------------
	// Cache loading and warming
	// ----------------------------------------------------------------

	/**
	 * Attempt to load a cached array from a PHP file.
	 *
	 * Cache files must return a plain array. Any other return type is treated as
	 * a cache-miss (returns null), triggering a rebuild from sources.
	 *
	 * This is a deliberate kernel principle: corrupt or stale cache is a miss,
	 * not a fatal error. Cache files are generated artifacts, not authored source.
	 * If a deploy fails halfway, a file is truncated, or opcache serves stale
	 * bytecode, the app rebuilds from sources and continues. Fail-fast applies
	 * to sources (providers.php, cfg files, Registry constants) - not to cache.
	 *
	 * @param  string  $file  Absolute path to the cache file.
	 * @return ?array  The cached array, or null on miss (file absent or non-array return).
	 */
	private function loadCacheArray(string $file): ?array {
		if (!\is_file($file)) {
			return null;
		}
		$data = require $file;
		return \is_array($data) ? $data : null;
	}

	/**
	 * Resolve the three cache file paths for the current mode.
	 *
	 * Returns a fixed-shape array with keys 'cfg', 'dispatch', and 'services'.
	 * Dispatch file names are mode-specific: routes.http.php for HTTP,
	 * commands.cli.php for CLI.
	 *
	 * @return array{cfg: string, dispatch: string, services: string}
	 */
	private function cacheFilePaths(): array {
		$suffix = ($this->mode === Mode::HTTP) ? 'http' : 'cli';
		$dir    = CITOMNI_APP_PATH . '/var/cache';

		return [
			'cfg'      => $dir . '/cfg.' . $suffix . '.php',
			'dispatch' => ($this->mode === Mode::HTTP)
				? $dir . '/routes.http.php'
				: $dir . '/commands.cli.php',
			'services' => $dir . '/services.' . $suffix . '.php',
		];
	}

	/**
	 * Warm (compile) caches for the current mode (HTTP or CLI) and write them atomically.
	 *
	 * This produces three cache artifacts under <appRoot>/var/cache:
	 *   HTTP mode: cfg.http.php, routes.http.php, services.http.php
	 *   CLI mode:  cfg.cli.php, commands.cli.php, services.cli.php
	 *
	 * Behavior:
	 * - Uses the same builders as runtime (buildConfig(), buildRoutes()/buildCommands(),
	 *   buildServices()), so the cache output matches exactly what the constructor
	 *   would compute without cache.
	 * - If $env is provided, it is propagated to buildConfig() and the dispatch builder,
	 *   allowing deterministic cache generation for a target environment from any
	 *   environment (e.g. warming prod caches from a dev deploy script).
	 * - Each cache file is a tiny PHP script that does `return [ ... ];` with no side effects.
	 * - Files are written atomically via a temp file + rename. If $overwrite=false and a file
	 *   already exists, that file is skipped.
	 * - If $opcacheInvalidate=true and opcache_invalidate() exists, we invalidate each file
	 *   after replacing it to avoid stale bytecode in prod.
	 *
	 * Notes:
	 * - The services map has no env overlay, so $env does not affect buildServices(). It is
	 *   included in this method's signature for coherence: one call warms everything for one target.
	 * - The App constructor will automatically consume these cache files if present.
	 * - Callers must ensure <appRoot>/var/cache is writable in the current environment.
	 * - No exceptions are caught here; failures bubble up.
	 *
	 * BREAKING CHANGE (v2): Return array keys changed from {cfg, routes, services}
	 * to {cfg, dispatch, services}. The 'dispatch' key covers routes.http.php in HTTP
	 * mode and commands.cli.php in CLI mode.
	 *
	 * @param  bool         $overwrite          Overwrite existing cache files if they exist.
	 * @param  bool         $opcacheInvalidate  Invalidate OPcache for written files (when available).
	 * @param  string|null  $env                Environment to build for, or null to use CITOMNI_ENVIRONMENT.
	 * @return array{cfg: ?string, dispatch: ?string, services: ?string}  Absolute paths written (null = skipped).
	 */
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true, ?string $env = null): array {
		$paths    = $this->cacheFilePaths();
		$cacheDir = \dirname($paths['cfg']);

		// Ensure cache dir exists
		if (!\is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) {
			throw new \RuntimeException("Unable to create cache directory: {$cacheDir}");
		}

		// Build arrays (env propagated to cfg and dispatch builders)
		$cfgArray      = $this->buildConfig($env);
		$dispatchArray = ($this->mode === Mode::HTTP) ? $this->buildRoutes($env) : $this->buildCommands($env);
		$svcArray      = $this->buildServices();

		// Write caches atomically
		return [
			'cfg'      => $this->writeCacheAtomically($paths['cfg'],      $cfgArray,      $overwrite, $opcacheInvalidate),
			'dispatch' => $this->writeCacheAtomically($paths['dispatch'], $dispatchArray, $overwrite, $opcacheInvalidate),
			'services' => $this->writeCacheAtomically($paths['services'], $svcArray,      $overwrite, $opcacheInvalidate),
		];
	}


	/**
	 * Atomically write a PHP file that returns the given array.
	 *
	 * @param  string                    $target             Absolute target path.
	 * @param  array<string|int, mixed>  $data               Array to be exported.
	 * @param  bool                      $overwrite          If false and target exists, skip write.
	 * @param  bool                      $opcacheInvalidate  Invalidate OPcache for $target after move (when available).
	 * @return ?string  The written file path, or null if skipped.
	 */
	private function writeCacheAtomically(string $target, array $data, bool $overwrite, bool $opcacheInvalidate): ?string {
		if (!$overwrite && \is_file($target)) {
			return null;
		}

		$dir = \dirname($target);
		if (!\is_dir($dir) && !@mkdir($dir, 0775, true)) {
			throw new \RuntimeException("Unable to create directory: {$dir}");
		}

		$tmp = $target . '.' . \bin2hex(\random_bytes(6)) . '.tmp';
		$code = "<?php\nreturn " . \var_export($data, true) . ";\n";

		if (\file_put_contents($tmp, $code, \LOCK_EX) === false) {
			throw new \RuntimeException("Failed writing cache tmp: {$tmp}");
		}
		@\chmod($tmp, 0644);

		if (!@\rename($tmp, $target)) {
			@\unlink($tmp);
			throw new \RuntimeException("Failed moving cache into place: {$target}");
		}

		if ($opcacheInvalidate && \function_exists('opcache_invalidate')) {
			@\opcache_invalidate($target, true);
		}

		return $target;
	}


	// ----------------------------------------------------------------
	// Service and package discovery helpers
	// ----------------------------------------------------------------

	/**
	 * O(1) existence check for a known service id from the merged services map.
	 *
	 * @param  string  $id  Service identifier (e.g. 'auth', 'db').
	 * @return bool  True if the id is present in the service map.
	 */
	public function hasService(string $id): bool {
		return isset($this->services[$id]);
	}

	/**
	 * True if at least one of the given service ids exists.
	 *
	 * @param  string  ...$ids  One or more service identifiers.
	 * @return bool  True if at least one id exists in the service map.
	 */
	public function hasAnyService(string ...$ids): bool {
		foreach ($ids as $id) {
			if (isset($this->services[$id])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lazy, zero-I/O check: does "vendor/package" exist among services, routes, or commands?
	 *
	 * Compares the requested slug to the first two namespace segments of known
	 * classes (lowercased), e.g. \CitOmni\Auth\* => "citomni/auth".
	 *
	 * Scan order: services -> routes (HTTP) -> commands (CLI).
	 * Memoized per App instance. No autoload, no disk I/O.
	 *
	 * @param  string  $slug  Composer-style slug (e.g. "citomni/auth").
	 * @return bool  True if any registered class belongs to the package.
	 */
	public function hasPackage(string $slug): bool {
		$slug = \strtolower(\trim($slug));
		if ($slug === '') {
			return false;
		}
		if (isset($this->packageMemo[$slug])) {
			return $this->packageMemo[$slug];
		}

		// 1) Scan services
		foreach ($this->services as $def) {
			$class = \is_string($def) ? $def : (\is_array($def) ? (string)($def['class'] ?? '') : '');
			if ($class !== '' && $this->fqcnToPackageSlug($class) === $slug) {
				return $this->packageMemo[$slug] = true;
			}
		}

		// 2) Scan dispatch maps (routes and commands share the same structure for this purpose)
		if ($this->scanDispatchMapForSlug($this->routes, $slug)
			|| $this->scanDispatchMapForSlug($this->commands, $slug)) {
			return $this->packageMemo[$slug] = true;
		}

		return $this->packageMemo[$slug] = false;
	}

	/**
	 * True if any service or dispatch map class starts with the given namespace prefix.
	 *
	 * @param  string  $prefix  Namespace prefix, with or without leading/trailing backslashes.
	 * @return bool  True if a service, route controller, or command class starts with that prefix.
	 */
	public function hasNamespace(string $prefix): bool {
		$prefix = '\\' . \ltrim($prefix, '\\');
		if (!\str_ends_with($prefix, '\\')) {
			$prefix .= '\\';
		}

		// Scan services
		foreach ($this->services as $def) {
			$class = \is_string($def) ? $def : (\is_array($def) ? (string)($def['class'] ?? '') : '');
			if ($class !== '' && \str_starts_with($class, $prefix)) {
				return true;
			}
		}

		// Scan dispatch maps
		if ($this->scanDispatchMapForPrefix($this->routes, $prefix, 'controller')
			|| $this->scanDispatchMapForPrefix($this->commands, $prefix, 'command')) {
			return true;
		}

		return false;
	}


	// ----------------------------------------------------------------
	// Dispatch map scanning helpers (shared by hasPackage / hasNamespace)
	// ----------------------------------------------------------------

	/**
	 * Scan a dispatch map for a class belonging to the given package slug.
	 *
	 * Checks the class key ('controller' for routes, 'command' for commands)
	 * in both flat entries and the 'regex' sub-array (HTTP routes only).
	 *
	 * @param  array<string, mixed>  $map   The dispatch map to scan.
	 * @param  string                $slug  Composer-style package slug.
	 * @return bool  True if any entry's class matches the slug.
	 */
	private function scanDispatchMapForSlug(array $map, string $slug): bool {
		foreach ($map as $key => $entry) {
			if (!\is_array($entry)) {
				continue;
			}

			// Check both 'controller' (routes) and 'command' (commands) keys
			$class = $entry['controller'] ?? ($entry['command'] ?? null);
			if (\is_string($class) && $this->fqcnToPackageSlug($class) === $slug) {
				return true;
			}
		}

		// Known coupling: 'regex' is a Router-internal format. App scans it
		// here for package discovery. Accepted compromise - stable format,
		// and Router cannot expose a helper without being instantiated.
		// Roadmap: Router should own dispatch-class extraction long-term.
		if (isset($map['regex']) && \is_array($map['regex'])) {
			foreach ($map['regex'] as $regexDef) {
				if (\is_array($regexDef)) {
					$ctrl = $regexDef['controller'] ?? null;
					if (\is_string($ctrl) && $this->fqcnToPackageSlug($ctrl) === $slug) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Scan a dispatch map for a class starting with the given namespace prefix.
	 *
	 * @param  array<string, mixed>  $map       The dispatch map to scan.
	 * @param  string                $prefix    Normalized namespace prefix (leading + trailing backslash).
	 * @param  string                $classKey  Key holding the class FQCN ('controller' or 'command').
	 * @return bool  True if any entry's class matches the prefix.
	 */
	private function scanDispatchMapForPrefix(array $map, string $prefix, string $classKey): bool {
		foreach ($map as $key => $entry) {
			if (\is_array($entry) && isset($entry[$classKey]) && \is_string($entry[$classKey])) {
				if (\str_starts_with($entry[$classKey], $prefix)) {
					return true;
				}
			}
		}

		// Regex group scanning - same known coupling as scanDispatchMapForSlug().
		if ($classKey === 'controller' && isset($map['regex']) && \is_array($map['regex'])) {
			foreach ($map['regex'] as $regexDef) {
				if (\is_array($regexDef) && isset($regexDef[$classKey]) && \is_string($regexDef[$classKey])) {
					if (\str_starts_with($regexDef[$classKey], $prefix)) {
						return true;
					}
				}
			}
		}

		return false;
	}


	// ----------------------------------------------------------------
	// Internal string helpers
	// ----------------------------------------------------------------

	/**
	 * Map FQCN -> "vendor/package" using the first two namespace parts.
	 * Example: "\CitOmni\Auth\Controller\X" -> "citomni/auth"
	 *
	 * @param  string  $fqcn  Fully-qualified class name.
	 * @return string  Slug or empty string if malformed.
	 */
	private function fqcnToPackageSlug(string $fqcn): string {
		$parts = \explode('\\', \ltrim($fqcn, '\\'));
		if (\count($parts) < 2) {
			return '';
		}
		return \strtolower($parts[0]) . '/' . \strtolower($parts[1]);
	}

	// ----------------------------------------------------------------
	// Diagnostics (dev only)
	// ----------------------------------------------------------------

	/**
	 * Dumps the merged service map (IDs -> definitions).
	 *
	 * Allowed when CITOMNI_ENVIRONMENT === 'dev'. Throws a RuntimeException in any
	 * other environment to prevent accidental leakage.
	 *
	 * @internal Temporary residence in App. Will migrate to a dedicated Debug
	 *           utility. Do not depend on this method remaining in App's public API.
	 *
	 * @return void
	 * @throws \RuntimeException  When the environment is not 'dev'.
	 */
	public function vardumpServices(): void {
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';
		if ($env !== 'dev') {
			throw new \RuntimeException('vardumpServices() is only allowed in the dev environment.');
		}

		\var_dump($this->services);
	}

	/**
	 * Output a lightweight memory/time checkpoint.
	 *
	 * Behavior:
	 * - No-op when CITOMNI_ENVIRONMENT !== 'dev'.
	 * - Otherwise outputs either an HTML comment (default) or an HTTP header
	 *   when $asHeader is true.
	 *
	 * Metrics reported: used/peak (emalloc), real/realPeak (OS-allocated),
	 * files (included count), elapsed ms since first call.
	 *
	 * Typical usage:
	 *   $this->app->memoryMarker('router:start');
	 *   $this->app->memoryMarker('router:end', true); // as HTTP header
	 *
	 * Notes:
	 * - This is a dev aid; it does not log or persist data.
	 *
	 * @internal Temporary residence in App. Will migrate to a dedicated Debug
	 *           utility that is mode-aware (HTML comments are CLI-incoherent).
	 *           Do not depend on this method remaining in App's public API.
	 *
	 * @param  string  $label     Marker name for this checkpoint.
	 * @param  bool    $asHeader  If true, output as HTTP header instead of HTML comment.
	 * @return void
	 */
	public function memoryMarker(string $label, bool $asHeader = false): void {
		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
			return;
		}

		static $t0 = null;
		if ($t0 === null) {
			$t0 = \microtime(true);
		}

		$used     = \memory_get_usage(false);
		$peak     = \memory_get_peak_usage(false);
		$real     = \memory_get_usage(true);
		$realPeak = \memory_get_peak_usage(true);
		$files    = \count(\get_included_files());
		$ms       = (int)\round((\microtime(true) - $t0) * 1000);

		$line = \sprintf(
			'%s | used=%d | peak=%d | real=%d | realPeak=%d | files=%d | %dms',
			$label,
			$used,
			$peak,
			$real,
			$realPeak,
			$files,
			$ms
		);

		if ($asHeader && !\headers_sent()) {
			\header('X-CitOmni-MemMark: ' . $line);
		} else {
			echo "<!-- {$line} -->\n";
		}
	}
}
