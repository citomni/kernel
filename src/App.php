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
 * App: Deterministic application kernel for config & service assembly.
 *
 * Responsibilities:
 * - Build the final configuration with a predictable merge order ("last wins"):
 *   1) Vendor baseline (\CitOmni\{Http|Cli}\Boot\Config::CFG)
 *   2) Provider CFGs (listed in /config/providers.php; CFG_HTTP|CFG_CLI)
 *   3) App base cfg (/config/citomni_{http|cli}_cfg.php)
 *   4) Env overlay   (/config/citomni_{http|cli}_cfg.{ENV}.php) [optional]
 *   Notes:
 *   - Associative arrays are merged per key; numeric lists are replaced.
 *   - $this->cfg exposes a deep, read-only wrapper; known RAW_ARRAY_KEYS (e.g. 'routes')
 *     are returned as raw arrays for speed and familiarity.
 *
 * - Build the final services map with deterministic precedence (array-union):
 *   app + provider + vendor
 *   1) Vendor Services::MAP (mode-specific)
 *   2) Provider MAP_{HTTP|CLI} overrides
 *   3) /config/services.php overrides win last
 *
 * - Prefer precompiled caches when present to reduce runtime work:
 *   <appRoot>/var/cache/cfg.{http|cli}.php
 *   <appRoot>/var/cache/services.{http|cli}.php
 *   (Each cache file must be a side-effect-free "return [ ... ];")
 *
 * - Provide capability helpers without I/O:
 *   hasService(), hasAnyService(), hasPackage(), hasNamespace(), vardumpServices()
 *
 * Collaborators:
 * - \CitOmni\Http\Boot\Config::CFG, \CitOmni\Cli\Boot\Config::CFG   (read baseline)
 * - Provider classes listed in /config/providers.php                 (read CFG_* and MAP_*)
 * - PHP OPcache (optional, for per-file invalidation after cache writes)
 * - Global constants: CITOMNI_APP_PATH (required), CITOMNI_ENVIRONMENT (optional)
 *
 * Configuration keys:
 * - routes (array)             - Application routes; returned as a raw array (RAW_ARRAY_KEYS).
 * - (generic merged keys)      - App does not require specific keys itself; it merges vendor,
 *                                provider, and app-level configuration deterministically.
 *
 * Error handling:
 * - Fail fast on:
 *   - Missing/invalid config dir (constructor)
 *   - Invalid provider class or malformed return types in config/service maps
 *   - Cache write/move failures
 * - This class does not catch exceptions; errors bubble to the global handler.
 *
 * Typical usage:
 *
 *   // Boot via HTTP Kernel (recommended in web apps)
 *   define('CITOMNI_APP_PATH', __DIR__);                    // app root (no trailing slash)
 *   require __DIR__ . '/vendor/autoload.php';
 *   \CitOmni\Http\Kernel::run(__DIR__ . '/public');
 *
 *   // Direct construction (e.g., CLI tooling, tests)
 *   $app = new \CitOmni\Kernel\App(__DIR__ . '/config', \CitOmni\Kernel\Mode::HTTP);
 *   $baseUrl = $app->cfg->http->base_url ?? null;           // deep, read-only cfg
 *   $view    = $app->view;                                   // lazy service: id => instance
 *
 * Examples:
 *
 *   // Access nested cfg:
 *   $tz = $app->cfg->locale->timezone ?? 'UTC';
 *
 *   // Note: routes are a raw array
 *   $homeCtrl = $app->cfg->routes['/']['controller'] ?? null;
 *
 *   // Capability checks:
 *   if ($app->hasPackage('citomni/auth')) { // render login UI // }
 *   if ($app->hasNamespace('\CitOmni\Infrastructure')) { // enable DB tools // }
 *
 *   // Warm caches (deploy step or admin webhook)
 *   $written = $app->warmCache(overwrite: true, opcacheInvalidate: true);
 *
 * Failure:
 *
 *   // 1) Non-existent /config dir
 *   new \CitOmni\Kernel\App('/bad/path/config', \CitOmni\Kernel\Mode::HTTP);
 *   // => \RuntimeException (bubbles to global handler)
 *
 *   // 2) Malformed providers.php return type
 *   // providers.php returns a string instead of array
 *   // => \RuntimeException ("providers.php must return an array of FQCN strings.")
 *
 * Standalone (minimal):
 *
 *   define('CITOMNI_APP_PATH', __DIR__);
 *   require __DIR__ . '/vendor/autoload.php';
 *   $app = new \CitOmni\Kernel\App(__DIR__ . '/config', \CitOmni\Kernel\Mode::CLI);
 *   $app->warmCache(); // compile cfg/services caches for CLI mode
 */
final class App {

	/** Public read-only configuration. */
	public readonly Cfg $cfg;

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
	 * @var array<string,bool>
	 */
	private array $packageMemo = [];


	/**
	 * Application bootstrap.
	 *
	 * Initializes the application with a given configuration directory and mode (HTTP|CLI).
	 * This constructor prefers precompiled cache artifacts (if present) to minimize
	 * runtime overhead. If a cache file is missing or invalid, it falls back to the
	 * normal build pipeline.
	 *
	 * Behavior overview:
	 * - Determines cache file suffix from mode: "http" or "cli".
	 * - Attempts to load compiled configuration from: <appRoot>/var/cache/cfg.{suffix}.php
	 *   - The cache file MUST return a plain array (no side effects).
	 *   - If it returns an object, it is cast to array.
	 *   - If the returned type is not an array, we fall back to buildConfig().
	 * - Wraps the merged configuration in a deep, read-only Cfg wrapper:
	 *   - Allows property-chaining like: $this->app->cfg->http->base_url
	 *   - Nested associative arrays are exposed as Cfg nodes; lists remain arrays.
	 * - Attempts to load compiled services map from: <appRoot>/var/cache/services.{suffix}.php
	 *   - The cache file MUST return a plain array of service definitions.
	 *   - If missing/invalid, falls back to buildServices().
	 *
	 * Notes:
	 * - Cache files are expected to be generated by a build/deploy step (e.g., a CLI
	 *   command "cache:warm") and written atomically. This constructor does not
	 *   write caches; it only consumes them when present.
	 * - Cache scripts should contain no side effects beyond `return [ ... ];` to keep
	 *   OPcache stable and memory usage low.
	 * - When OPcache is enabled with validate_timestamps=0 in production, remember to
	 *   call opcache_reset() as part of the deploy step after refreshing cache files.
	 * - $configDir should be an absolute path to the /config directory of the app.
	 * - No exceptions are caught here by design; any underlying failures are allowed
	 *   to propagate to the global error handler.
	 *
	 * @param string $configDir Absolute path to the application's /config directory.
	 * @param Mode   $mode      Execution mode (Mode::HTTP or Mode::CLI) determining cache suffix and boot semantics.
	 * @return void
	 */
	public function __construct(string $configDir, Mode $mode) {
		
		$cfgDir = \rtrim($configDir, \DIRECTORY_SEPARATOR);
		$cfgDirReal = \realpath($cfgDir);
		if ($cfgDirReal === false) {
			throw new \RuntimeException("Config directory not found: {$cfgDir}");
		}
		
		$this->configDir = $cfgDirReal;
		// $this->appRoot   = \dirname($this->configDir);
		// $this->appRoot   = CITOMNI_APP_PATH;
		$this->mode      = $mode;

		// Resolve cache file names per mode.
		$suffix   = ($mode === Mode::HTTP) ? 'http' : 'cli';
		$cacheDir = CITOMNI_APP_PATH . '/var/cache';
		$cfgCache = $cacheDir . '/cfg.' . $suffix . '.php';
		$svcCache = $cacheDir . '/services.' . $suffix . '.php';

		// 1) Load configuration (prefer compiled cache).
		if (\is_file($cfgCache)) {
			$cfgArray = require $cfgCache; // must return array (no side effects)
			if (\is_object($cfgArray)) {
				$cfgArray = (array)$cfgArray;
			}
			if (!\is_array($cfgArray)) {
			// Fallback if cache returned unexpected type
				$cfgArray = $this->buildConfig();
			}
		} else {
			$cfgArray = $this->buildConfig();
		}

		// 2) Wrap cfg in deep, read-only adapter (property chaining).
		$this->cfg = new Cfg($cfgArray);

		// 3) Build services map (prefer compiled cache).
		if (\is_file($svcCache)) {
			$services = require $svcCache; // must return array
			$this->services = \is_array($services) ? $services : $this->buildServices();
		} else {
			$this->services = $this->buildServices();
		}
	}


	/**
	 * Magic accessor for application services.
	 *
	 * Enables property-style access:
	 *   $this->app->log   -> resolves "log" service
	 *   $this->app->cfg   -> special-case access to configuration wrapper
	 *
	 * Resolution strategy:
	 * 1. **cfg special case** - Direct access to the App's configuration wrapper
	 *    without needing a mapping.
	 * 2. **Cached instance** - If service already constructed, return it from
	 *    $this->instances cache.
	 * 3. **Service definition lookup** - Must exist in $this->services
	 *    (populated by buildServices()).
	 *    - If definition is a string: treated as FQCN, instantiated with `new $class($this)`.
	 *    - If definition is an array: must contain 'class' (string) and may
	 *      contain 'options' (array). Instantiated with `new $class($this, $options)`.
	 *    - Otherwise: throws RuntimeException (invalid definition).
	 *
	 * Instances are cached in $this->instances to enforce singleton-like
	 * behavior within the App lifecycle.
	 *
	 * @param string $id Service identifier (e.g. "log", "request", "response").
	 * @return object Resolved service instance.
	 * @throws \RuntimeException If the service is unknown or its definition is invalid.
	 */
	public function __get(string $id): object {
		if ($id === 'cfg') {
			// Special-case: allow $this->app->cfg without being declared in services map
			/** @var object */
			return $this->cfg;
		}

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
			// Simple definition: class name only
			$class = $def;
			$instance = new $class($this);
		} elseif (\is_array($def) && isset($def['class']) && \is_string($def['class'])) {
			// Advanced definition: class + optional options array
			$class   = $def['class'];
			$options = $def['options'] ?? [];
			$instance = new $class($this, $options);
		} else {
			throw new \RuntimeException("Invalid service definition for '{$id}'");
		}

		// Cache and return the created instance
		return $this->instances[$id] = $instance;
	}


	/** Absolute path to the application root. */
	public function getAppRoot(): string {
		return CITOMNI_APP_PATH;
		// return $this->appRoot;
	}

	/** Absolute path to the /config directory. */
	public function getConfigDir(): string {
		return $this->configDir;
	}


	/**
	 * Build runtime configuration:
	 *   1) Mode baseline (vendor): \CitOmni\Http\Boot\Config::CFG | \CitOmni\Cli\Boot\Config::CFG
	 *   2) Providers (whitelist in /config/providers.php): merge CFG_HTTP|CFG_CLI constants
	 *   3) App base cfg: /config/citomni_{http|cli}_cfg.php
	 *   4) App env overlay: /config/citomni_{http|cli}_cfg.{ENV}.php <- last wins
	 *
	 * @return array<string,mixed>
	 */
	private function buildConfig(): array {
		
		// echo __METHOD__ . " was running \n";
		
		$mode = $this->mode;

		// 1) Mode baseline (no I/O)
		$base = match ($mode) {
			Mode::HTTP => \CitOmni\Http\Boot\Config::CFG,
			Mode::CLI  => \CitOmni\Cli\Boot\Config::CFG,
		};
		$cfg = Arr::normalizeConfig($base);

		// 2) Providers whitelist (I/O: 1 include)
		$providersFile = $this->configDir . '/providers.php';
		$providers = \is_file($providersFile) ? require $providersFile : [];
		if (!\is_array($providers)) {
			throw new \RuntimeException('providers.php must return an array of FQCN strings.');
		}

		// 2a) Fold provider CFG constants (CFG_HTTP|CFG_CLI) - last wins per key
		$constName = ($mode === Mode::HTTP) ? 'CFG_HTTP' : 'CFG_CLI';
		foreach ($providers as $fqcn) {
			if (!\is_string($fqcn) || $fqcn === '') {
				throw new \RuntimeException('Invalid provider FQCN in providers.php');
			}
			if (!\class_exists($fqcn)) {
				throw new \RuntimeException("Provider class not found: {$fqcn}");
			}
			$constFq = $fqcn . '::' . $constName;
			if (\defined($constFq)) {
				/** @var array<string,mixed>|object $pv */
				$pv = \constant($constFq);
				$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($pv));
			}
		}

		// 3) App base cfg (I/O: 1 include) - last wins
		$appBaseFile = $this->configDir . ($mode === Mode::HTTP ? '/citomni_http_cfg.php' : '/citomni_cli_cfg.php');
		if (\is_file($appBaseFile)) {
			/** @var array<string,mixed>|object $appCfg */
			$appCfg = require $appBaseFile;
			$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($appCfg));
		}

		// 4) App env overlay (I/O: 1 include) - last wins
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';
		$appEnvFile = $this->configDir . ($mode === Mode::HTTP
			? "/citomni_http_cfg.{$env}.php"
			: "/citomni_cli_cfg.{$env}.php"
		);
		if (\is_file($appEnvFile)) {
			/** @var array<string,mixed>|object $envCfg */
			$envCfg = require $appEnvFile;
			$cfg = Arr::mergeAssocLastWins($cfg, Arr::normalizeConfig($envCfg));
		}

		return $cfg;
	}


	/**
	 * Build the final **services map** for the application.
	 *
	 * Merge order (deterministic "last-wins"):
	 * 1. **Mode baseline** - static vendor defaults from CitOmni\Http\Boot\Services::MAP
	 *    or CitOmni\Cli\Boot\Services::MAP depending on runtime mode.
	 * 2. **Providers** - external providers listed in /config/providers.php.
	 *    Each provider class may define a MAP_HTTP or MAP_CLI constant.
	 *    If present, those entries override vendor baseline for same IDs.
	 * 3. **App overrides** - application-specific service definitions
	 *    from /config/services.php. These always win last.
	 * Merge order via array union (left side wins on key collision):
	 *    app + provider + vendor
	 *
	 * This produces the authoritative service map used by App to
	 * resolve services via $this->app->id -> instance.
	 *
	 * @return array<string,mixed> Final merged service map.
	 * @throws \RuntimeException If any step returns an invalid type
	 *                           (non-array map, missing provider class, etc.).
	 */
	private function buildServices(): array {
		
		// echo __METHOD__ . " was running \n"; // Debug: remove/disable in production
		
		$mode = $this->mode;

		// 1) Start from vendor baseline map depending on runtime mode
		$map = match ($mode) {
			Mode::HTTP => \CitOmni\Http\Boot\Services::MAP,
			Mode::CLI  => \CitOmni\Cli\Boot\Services::MAP,
		};
		if (!\is_array($map)) {
			throw new \RuntimeException('Vendor Services::MAP must be an array.');
		}

		// 2) Load providers (optional) from config/providers.php
		$providersFile = $this->configDir . '/providers.php';
		$providers = \file_exists($providersFile) ? require $providersFile : [];
		if (!\is_array($providers)) {
			throw new \RuntimeException('providers.php must return an array of FQCN strings.');
		}

		foreach ($providers as $fqcn) {
			// Ensure provider class exists
			if (!\class_exists($fqcn)) {
				throw new \RuntimeException("Provider class not found: {$fqcn}");
			}

			// Each provider may define MAP_HTTP or MAP_CLI depending on mode
			$const = $mode === Mode::HTTP ? 'MAP_HTTP' : 'MAP_CLI';
			$constFq = $fqcn . '::' . $const;

			if (\defined($constFq)) {
				/** @var array<string,mixed> $pvmap */
				$pvmap = \constant($constFq);

				// Provider overrides vendor baseline for duplicate IDs
				$map = $pvmap + $map;
			}
		}

		// 3) Load app-level overrides from config/services.php (if exists)
		$appMapFile = $this->configDir . '/services.php';
		if (\file_exists($appMapFile)) {
			/** @var array<string,mixed> $appMap */
			$appMap = require $appMapFile;
			if (!\is_array($appMap)) {
				throw new \RuntimeException('services.php must return an array.');
			}

			// App overrides everything else (wins last)
			$map = $appMap + $map;
		}

		return $map;
	}










/*
 *---------------------------------------------------------------
 * CONFIG/SERVICE CACHE - Deterministic, atomic, prod-grade
 *---------------------------------------------------------------
 * PURPOSE
 * Compile CitOmni's merged configuration and service map into tiny PHP
 * files for zero-overhead runtime. The App constructor will prefer these
 * artifacts when present, avoiding repeated merge work on every request.
 *
 * WHAT IT DOES
 * - warmCache(bool $overwrite=true, bool $opcacheInvalidate=true)
 *     • Builds the *exact* same arrays as runtime (no special paths)
 *     • Writes two artifacts under <appRoot>/var/cache:
 *         - cfg.{http|cli}.php        (merged configuration)
 *         - services.{http|cli}.php   (final service map)
 *     • Returns absolute paths written (null = skipped when overwrite=false)
 *     • Optionally invalidates OPcache for the written files
 *
 * - writeCacheAtomically(string $target, array $data, bool $overwrite, bool $opcacheInvalidate)
 *     • Emits a minimal PHP file: "<?php\nreturn <var_export array>;\n"
 *     • Writes to a random .tmp and renames into place (atomic on the FS)
 *     • Sets 0644 permissions and (optionally) opcache_invalidate($target, true)
 *
 * WHEN TO RUN
 * - On deploy (HTTP/CLI) or via a webhook/CLI task before you flip traffic
 * - Any time providers.php / app config / services map changes
 * - Safe to call multiple times; use $overwrite=false to skip existing files
 *
 * BEHAVIOR & GUARANTEES
 * - Deterministic "last-wins" merge: vendor -> providers.php -> app (+ env overlay)
 * - Maintenance flag is intentionally *not* baked into cfg cache. It is enforced
 *   at runtime so you can toggle maintenance without regenerating caches.
 * - No hidden side effects in cache files-each simply returns an array
 * - Errors are not swallowed; failures bubble to the global error handler
 *
 * PRODUCTION NOTES
 * - With OPcache in strict mode (opcache.validate_timestamps=0), prefer
 *   $opcacheInvalidate=true to avoid stale bytecode after atomic replace.
 * - Ensure <appRoot>/var/cache is writable by the PHP user in the target env.
 * - Caches are mode-scoped: HTTP and CLI have separate files and can be warmed
 *   independently (suffix is chosen automatically by current App mode).
 *
 * USAGE (EXAMPLES)
 *   // HTTP (e.g., from an admin webhook)
 *   $result = $this->app->warmCache(); // overwrite+invalidate by default
 *
 *   // CLI (deploy script)
 *   $result = $this->app->warmCache(overwrite: true, opcacheInvalidate: true);
 *
 * SAFETY
 * - Do not include secrets or dynamic environment probes in cache emitters.
 * - Cache contents are PHP; place var/cache outside public web root or ensure
 *   your web server denies direct access to *.php under var/.
 *
 * TL;DR
 * Warm once per change, run fast forever. Deterministic arrays, atomic writes,
 * and optional OPcache invalidation make this cache path production-safe.
 */


	/**
	 * Warm (compile) caches for the current mode (HTTP or CLI) and write them atomically.
	 *
	 * This produces two cache artifacts under <appRoot>/var/cache:
	 *   - cfg.{suffix}.php       (merged configuration WITHOUT maintenance key)
	 *   - services.{suffix}.php  (final services map)
	 *
	 * Where {suffix} is "http" or "cli" based on $this->mode.
	 *
	 * Behavior:
	 * - Uses the same builders as runtime (buildConfig(), buildServices()) so output
	 *   matches what the constructor would compute when no cache is present.
	 * - Files are written atomically: a unique .tmp file is created and then renamed
	 *   into place. If $overwrite=false and a target exists, that file is skipped.
	 * - If $opcacheInvalidate=true, opcache_invalidate() is called for each written
	 *   file (when available) to avoid stale bytecode in prod without a full reset.
	 *
	 * Notes:
	 * - The App constructor will automatically consume these cache files (if present)
	 *   instead of rebuilding at runtime.
	 * - Expect the cache files to contain only `return [ ... ];` and no side effects.
	 * - Callers should ensure <appRoot>/var/cache is writable in the current env.
	 * - No exceptions are caught here; failures propagate to the global error handler.
	 *
	 * Typical usage:
	 *   // HTTP controller (webhook):
	 *   $result = $this->app->warmCache();               // overwrite + invalidate OPcache
	 *
	 *   // CLI command:
	 *   $result = $this->app->warmCache(overwrite: true, opcacheInvalidate: true);
	 *
	 * @param bool $overwrite           Overwrite existing cache files if they exist.
	 * @param bool $opcacheInvalidate   Invalidate OPcache for written files (when available).
	 * @return array{cfg:?string,services:?string} Absolute paths that were written (null = skipped).
	 */
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true): array {
		$suffix   = ($this->mode === Mode::HTTP) ? 'http' : 'cli';
		$cacheDir = CITOMNI_APP_PATH . '/var/cache';
		$cfgFile  = $cacheDir . '/cfg.' . $suffix . '.php';
		$svcFile  = $cacheDir . '/services.' . $suffix . '.php';

		// Ensure cache directory exists.
		if (!\is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true)) {
			throw new \RuntimeException("Unable to create cache directory: {$cacheDir}");
		}

		// 1) Build arrays for current mode (without maintenance).
		$cfgArray = $this->buildConfig();   // merged vendor -> providers -> app (+ env overlay if supported)
		$svcArray = $this->buildServices(); // final service map

		// 2) Write atomically.
		$writtenCfg = $this->writeCacheAtomically($cfgFile, $cfgArray, $overwrite, $opcacheInvalidate);
		$writtenSvc = $this->writeCacheAtomically($svcFile, $svcArray, $overwrite, $opcacheInvalidate);

		return ['cfg' => $writtenCfg, 'services' => $writtenSvc];
	}


	/**
	 * Atomically write a PHP file that returns the given array.
	 *
	 * @param string $target              Absolute target path.
	 * @param array<string|int,mixed> $data   Array to be exported.
	 * @param bool $overwrite             If false and target exists, skip write.
	 * @param bool $opcacheInvalidate     Invalidate OPcache for $target after move (when available).
	 * @return ?string                    The written file path, or null if skipped.
	 */
	private function writeCacheAtomically(string $target, array $data, bool $overwrite, bool $opcacheInvalidate): ?string {
		if (!$overwrite && \is_file($target)) {
			return null; // Skip
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

		// Atomic move into place.
		if (!@\rename($tmp, $target)) {
			@\unlink($tmp);
			throw new \RuntimeException("Failed moving cache into place: {$target}");
		}

		// Best-effort OPcache invalidation of the updated file.
		if ($opcacheInvalidate && \function_exists('opcache_invalidate')) {
			@\opcache_invalidate($target, true);
		}

		return $target;
	}
	
	
	
	
	
	
	
/*
 *---------------------------------------------------------------
 * SERVICE / PACKAGE DISCOVERY HELPERS - Installation-aware sugar
 *---------------------------------------------------------------
 * PURPOSE
 * These helpers let UI and routing code *detect capabilities* without
 * Composer lookups, autoload side-effects, or extra boot-time registries.
 * They work purely off the already merged, in-memory service map and cfg.
 *
 * WHAT YOU GET
 * - hasService(string $id): O(1) presence check for a service id (e.g. 'auth', 'db').
 * - hasPackage(string $slug): Lazy, zero-I/O test for "vendor/package".
 *     - Computes a slug from known FQCNs (first two namespace parts, lowercased)
 *       and compares to the requested $slug.
 *     - Scans service classes; falls back to route controllers
 *     - Memoized per App instance (first call O(N), then O(1))
 * - hasNamespace(string $prefix): True if any service/route class starts with the prefix.
 * - hasAnyService(string ...$ids): True if at least one of the given ids exists.
 * - vardumpServices(): DEV-ONLY var_dump of the merged service map; throws in non-dev.
 *
 * WHY THIS EXISTS
 * - Keep "provider-aware" UI/flows deterministic and fast
 * - No runtime file I/O, no class_exists autoloading, no Composer introspection
 * - Plays nicely with CitOmni's deterministic last-wins merge and compiled caches
 *
 * USAGE EXAMPLES
 *   if ($this->app->hasService('auth')) { / show login/logout / }
 *   if ($this->app->hasPackage('citomni/auth')) { / render Auth UI / }
 *   if ($this->app->hasNamespace('\CitOmni\Auth')) { / coarse provider gate / }
 *
 * PERFORMANCE
 * - First call does an O(N) scan over tiny in-memory arrays (services/routes);
 *   subsequent calls hit a per-instance memo (O(1)). No disk I/O.
 *
 * SAFETY NOTES
 * - This is *capability discovery*, not authorization. Always apply RBAC
 *   for user-level access control in controllers/services.
 * - vardumpServices() is guarded by CITOMNI_ENVIRONMENT === 'dev' and will
 *   throw a RuntimeException in any other environment.
 *
 * PRIVATE HELPERS (internal)
 * - slugToNamespacePrefix('vendor/package') -> '\Vendor\Package\'
 * - studly('citomni-auth') -> 'CitOmniAuth'
 *
 */
	
	/**
	 * O(1) existence check for a known service id from the merged services map.
	 *
	 * @param string $id Service identifier (e.g. 'auth', 'db').
	 * @return bool True if the id is present in the service map.
	 */
	public function hasService(string $id): bool {
		return isset($this->services[$id]);
	}

	/**
	 * Lazy, zero-I/O check: does "vendor/package" exist among services/routes?
	 * Compares the requested slug to the first two namespace segments of
	 * known classes (lowercased), e.g. \CitOmni\Auth\* => "citomni/auth".
	 *
	 * Memoized per App instance. No autoload, no disk I/O.
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

		// 2) Scan route controllers (cheap; from merged cfg)
		$routes = $this->cfg->toArray()['routes'] ?? [];
		if (\is_array($routes)) {
			foreach ($routes as $route) {
				$ctrl = \is_array($route) ? ($route['controller'] ?? null) : null;
				if (\is_string($ctrl) && $this->fqcnToPackageSlug($ctrl) === $slug) {
					return $this->packageMemo[$slug] = true;
				}
			}
		}

		return $this->packageMemo[$slug] = false;
	}


	/**
	 * Optional sugar: Directly test for any class under a given namespace prefix.
	 *
	 * @param string $prefix Namespace prefix, with or without leading/trailing backslashes.
	 * @return bool True if a service or route controller starts with that prefix.
	 */
	public function hasNamespace(string $prefix): bool {
		$prefix = '\\' . \ltrim($prefix, '\\');
		if (!\str_ends_with($prefix, '\\')) {
			$prefix .= '\\';
		}
		foreach ($this->services as $def) {
			$class = \is_string($def) ? $def : (\is_array($def) ? (string)($def['class'] ?? '') : '');
			if ($class !== '' && \str_starts_with($class, $prefix)) {
				return true;
			}
		}
		$routes = $this->cfg->toArray()['routes'] ?? [];
		if (\is_array($routes)) {
			foreach ($routes as $route) {
				$ctrl = \is_array($route) ? ($route['controller'] ?? null) : null;
				if (\is_string($ctrl) && \str_starts_with($ctrl, $prefix)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Optional sugar: True if any of the given service ids exist.
	 *
	 * @param string ...$ids One or more service identifiers.
	 * @return bool True if at least one id exists in the service map.
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
	 * Convert a "vendor/package" slug into a "\Vendor\Package\" namespace prefix.
	 * Non-alphanumerics are treated as separators for StudlyCase conversion.
	 *
	 * @param string $slug Composer-style slug (e.g. "citomni/auth", "symfony/http-foundation").
	 * @return string Prefix like "\CitOmni\Auth\" or empty string on malformed input.
	 */
	private function slugToNamespacePrefix(string $slug): string {
		[$v, $p] = \array_pad(\explode('/', $slug, 2), 2, '');
		$vendor  = $this->studly($v);
		$package = $this->studly($p);
		return ($vendor !== '' && $package !== '') ? "\\{$vendor}\\{$package}\\" : '';
	}

	/**
	 * Map FQCN -> "vendor/package" using the first two namespace parts.
	 * Example: "\CitOmni\Auth\Controller\X" -> "citomni/auth"
	 *
	 * @param string $fqcn Fully-qualified class name.
	 * @return string Slug or empty string if malformed.
	 */
	private function fqcnToPackageSlug(string $fqcn): string {
		$parts = \explode('\\', \ltrim($fqcn, '\\'));
		if (\count($parts) < 2) {
			return '';
		}
		return \strtolower($parts[0]) . '/' . \strtolower($parts[1]);
	}

	/**
	 * "citomni-auth" => "CitOmniAuth"
	 * Collapses any non-alphanumerics to word breaks and applies StudlyCase.
	 *
	 * @param string $s Raw vendor/package part.
	 * @return string StudlyCased token (may be empty on invalid input).
	 */
	private function studly(string $s): string {
		$s = \strtolower($s);
		$s = (string)\preg_replace('~[^a-z0-9]+~', ' ', $s);
		return \str_replace(' ', '', \ucwords(\trim($s)));
	}
	
	/**
	 * Debug helper: Dumps the merged service map (IDs -> definitions).
	 *
	 * DEV ONLY:
	 * - Allowed when CITOMNI_ENVIRONMENT === 'dev'.
	 * - Throws a RuntimeException in any other environment to prevent accidental leakage.
	 *
	 * Side effects:
	 * - Produces direct output via var_dump(); do not call from production code.
	 *
	 * @return void
	 * @throws \RuntimeException When the environment is not 'dev'.
	 */
	public function vardumpServices(): void {
		$env = \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : 'prod';
		if ($env !== 'dev') {
			throw new \RuntimeException('vardumpServices() is only allowed in the dev environment.');
		}

		\var_dump($this->services);
	}	






/*
 *---------------------------------------------------------------
 * DIAGNOSTICS (DEV-ONLY) - Memory/time marker
 *---------------------------------------------------------------
 * PURPOSE
 *   Lightweight checkpoint for local profiling and sanity checks.
 *
 * SCOPE
 *   - memoryMarker(string $label, bool $asHeader=false)
 *
 * BEHAVIOR
 *   - No-op outside 'dev' (CITOMNI_ENVIRONMENT !== 'dev')
 *   - Outputs either an HTML comment or an HTTP header
 *
 * NOTES
 *   - Keep it cheap; do not call in hot loops.
 */
 
 
	/**
	 * Output a lightweight memory/time checkpoint.
	 *
	 * Behavior:
	 * - No-op when CITOMNI_ENVIRONMENT !== 'dev'.
	 * - Otherwise outputs either an HTML comment (default) or an HTTP header
	 *   when $asHeader is true.
	 *
	 * Metrics reported:
	 * - used/peak: emalloc memory
	 * - real/realPeak: OS-allocated memory
	 * - files: get_included_files() count
	 * - elapsed ms since first call in the current request
	 *
	 * Typical usage:
	 *   $this->app->memoryMarker('router:start');
	 *   // ... do work ...
	 *   $this->app->memoryMarker('router:end', true); // as HTTP header
	 *
	 * Notes:
	 * - This is a dev aid; it does not log or persist data.
	 * - Short, factual, and cheap. (No, you cannot get negative ms.)
	 *
	 * @param string $label    Marker name for this checkpoint.
	 * @param bool   $asHeader If true, output as HTTP header instead of HTML comment.
	 * @return void
	 */
	public function memoryMarker(string $label, bool $asHeader = false): void {
		if (\defined('CITOMNI_ENVIRONMENT') && \CITOMNI_ENVIRONMENT !== 'dev') {
			return; // no-op outside dev
		}

		// Init start time once per request
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
