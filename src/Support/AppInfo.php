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

namespace CitOmni\Kernel\Support;

use CitOmni\Kernel\App;


/**
 * AppInfo: Transport-independent introspection of the current CitOmni App and PHP runtime.
 *
 * AppInfo builds one authoritative, explicitly-sourced structured snapshot describing the
 * running CitOmni application together with the effective PHP process. It is the shared data
 * source for HTTP and CLI application-information adapters, so both transports observe
 * identical data collection and identical secret-masking behavior.
 *
 * The snapshot separates two intentionally distinct concepts:
 * - Runtime truth: values actually in effect for this process (the active cfg held by this
 *   App instance, the effective PHP timezone/charset/ICU locale, and the dispatch maps held
 *   by this App instance).
 * - Configuration projection: fresh cfg builds via App::buildConfig() for known environments.
 *   They describe what buildConfig() would produce now - not what another already-running
 *   dev/stage/prod process currently has loaded.
 *
 * Behavior:
 * - Active `cfg` comes from $app->cfg->toArray() and represents the configuration held by
 *   this App instance, even when it was materialized from a cfg cache and the source files
 *   have since changed. `cfg` is never replaced by a fresh buildConfig() result.
 * - Optional `cfg_by_env` values are fresh projections from App::buildConfig('dev'|'stage'|'prod').
 * - `runtime` reports effective PHP values (timezone, charset, ICU locale) read from the live
 *   process, deliberately independent of their configured cfg counterparts, so that a
 *   configuration/runtime mismatch remains detectable.
 * - Secret masking is owned here and applied uniformly to `cfg` and (when included) `cfg_by_env`.
 *   A secret-like key redacts its scalar value; a secret-like container key redacts all scalar
 *   descendants while preserving the nested key structure. Authorization to request unredacted
 *   output is NOT owned here; the caller decides whether it may pass $unredacted = true.
 *
 * Notes:
 * - App-aware Support helper, not a service-map singleton; instantiated explicitly by callers.
 * - No presentation, no authorization, no persistence, no SQL, no network IO, no subprocesses,
 *   no filesystem discovery, no logging, and no transport output. The only config-file loading
 *   is whatever App::buildConfig() encapsulates when environment projections are requested.
 * - Reads only public App contracts: $app->cfg, $app->routes, $app->commands, $app->buildConfig().
 *   No reflection, no private App state access, and no mutation of App, cfg, routes, or commands.
 * - The snapshot is stable in structure and ordering (e.g. packages are sorted by name), but it
 *   is not value-deterministic across calls: clock, memory, and elapsed-time fields reflect the
 *   moment of the call by design.
 * - Failures from required Kernel contracts (Cfg, App::buildConfig()) are allowed to propagate;
 *   a broken configuration build is meaningful diagnostic failure, not an empty snapshot.
 *
 * Typical usage:
 *   $appInfo  = new AppInfo($this->app);
 *   $snapshot = $appInfo->snapshot();
 *
 *   // Privileged caller, having already authorized the request itself:
 *   $full = $appInfo->snapshot(unredacted: true, includeEnvironmentConfigs: true);
 */
final class AppInfo {

	/**
	 * Sentinel written in place of a masked secret cfg value.
	 */
	private const REDACTED = '__redacted__';

	/**
	 * Splits a raw cfg key into snake_case boundaries at camelCase transitions before it is
	 * lowercased, so segment-based secret matching also sees camelCase keys.
	 *
	 * - (?<=[a-z0-9])(?=[A-Z])      : fooBar   -> foo_Bar   (word/acronym boundary)
	 * - (?<=[A-Z])(?=[A-Z][a-z])    : APIToken -> API_Token (trailing word after an acronym)
	 */
	private const CAMEL_BOUNDARY_PATTERN = '~(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])~';

	/**
	 * Secret-like key segments, matched on whole segments only (delimited by start/end or one
	 * of [_-.], after camelCase normalization). Operates on the lowercased key, so no /i flag.
	 *
	 * The trailing s? covers simple plurals (tokens, salts, api_keys, credentials) without
	 * widening the match to unrelated substrings. Whole-segment anchoring is what keeps
	 * author/compass/bypass/passenger_count out of scope.
	 */
	private const SECRET_KEY_PATTERN = '~(?:^|[_\-.])(?:secret|token|password|passphrase|pass|api[_-]?key|salt|private|credential|signature|auth|bearer)s?(?:$|[_\-.])~';

	/**
	 * Explicit secret-container key segments that propagate masking to every scalar descendant.
	 *
	 * This intentionally stays narrower than SECRET_KEY_PATTERN so configuration domains such as
	 * password, auth, token, signature, and private can remain diagnostically useful when arrays.
	 * Whole-segment matching still covers names such as api_secrets and smtp_credentials.
	 */
	private const SECRET_CONTAINER_PATTERN = '~(?:^|[_\-.])(?:secret|credential)s?(?:$|[_\-.])~';

	/**
	 * Credential-bearing URI userinfo (scheme://userinfo@host); redacted regardless of key name.
	 */
	private const CREDENTIAL_URI_PATTERN = '~^\w+://[^/\s]+@~';

	/**
	 * Established non-secret leaf keys preserved even though their names match SECRET_KEY_PATTERN,
	 * and preserved even inside a secret container (these keys are known non-credential metadata).
	 * Matched against the RAW lowercased key only - not camelCase-normalized variants - so the
	 * exemption stays limited to the exact established keys.
	 *
	 * - header_signature: configuration metadata, not a credential.
	 * - secret_file:      a file path/pointer, not the secret file contents (never read here).
	 */
	private const MASK_KEY_ALLOWLIST = [
		'header_signature' => true,
		'secret_file'      => true,
	];


	public function __construct(private readonly App $app) {
	}


	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Build a stable, explicitly-sourced structured snapshot of the current App and PHP runtime.
	 *
	 * Returns one associative array with these top-level keys:
	 * - citomni:    ['environment' => string|null] - CITOMNI_ENVIRONMENT, or null when undefined.
	 * - app:        ['name']                       - app identity from cfg.identity.app_name.
	 * - runtime:    Effective PHP process values (hostname, datetime_local, datetime_utc,
	 *               php_version, timezone, default_charset, icu_locale). Read from the process,
	 *               not from cfg. datetime_local and datetime_utc share one observation instant.
	 * - metrics:    Cheap local metrics (time_s, memory_usage_current_kb, memory_usage_peak_kb,
	 *               included_files_count, routes_count, commands_count). time_s is null when
	 *               CITOMNI_START_NS is undefined.
	 * - opcache:    ['enabled','validate_timestamps'] - from local INI only, tri-state. Each is
	 *               null when its directive is unavailable. 'enabled' reflects the active SAPI:
	 *               under the CLI SAPI it follows opcache.enable_cli, and is null when the master
	 *               switch is on but the CLI switch cannot be read.
	 * - packages:   Installed Composer packages keyed by name, each ['version','ref'],
	 *               deterministically sorted by package name. Empty array when
	 *               Composer\InstalledVersions is unavailable.
	 * - cfg:        Active App configuration ($app->cfg->toArray()); secret-masked unless $unredacted.
	 * - routes:     The route dispatch map held by this App instance ($app->routes).
	 * - commands:   The command dispatch map held by this App instance ($app->commands).
	 * - cfg_by_env: Present only when $includeEnvironmentConfigs is true. Fresh projections for
	 *               'dev', 'stage', and 'prod' via App::buildConfig($env); masked unless
	 *               $unredacted. Omitted entirely otherwise (never null or a placeholder).
	 *
	 * Behavior:
	 * - Active `cfg` is runtime truth and is never substituted by a fresh buildConfig() result.
	 * - Secret masking applies identically to `cfg` and, when included, `cfg_by_env`.
	 * - $unredacted only toggles masking; it never grants authorization (the caller decides).
	 *
	 * Notes:
	 * - No transport output, no authorization, no App/cfg mutation.
	 * - Failures from App::buildConfig() (environment projections) propagate to the caller.
	 *
	 * @param  bool  $unredacted                 Skip secret masking of cfg values when true.
	 * @param  bool  $includeEnvironmentConfigs  Include fresh dev/stage/prod cfg projections when true.
	 * @return array<string,mixed>               The structured application/runtime snapshot.
	 * @throws \RuntimeException                 Propagated from App::buildConfig() when an environment cfg cannot be built.
	 */
	public function snapshot(bool $unredacted = false, bool $includeEnvironmentConfigs = false): array {

		// -- 1. Active runtime configuration (masked unless unredacted) ---
		$cfg = $this->app->cfg->toArray();
		if (!$unredacted) {
			$cfg = $this->maskConfig($cfg);
		}

		// -- 2. Assemble the base snapshot --------------------------------
		$snapshot = [
			'citomni' => [
				'environment' => \defined('CITOMNI_ENVIRONMENT') ? (string)\CITOMNI_ENVIRONMENT : null,
			],
			'app' => [
				'name' => $this->app->cfg->identity->app_name ?? null,
			],
			'runtime'  => $this->collectRuntime(),
			'metrics'  => $this->collectMetrics(),
			'opcache'  => $this->collectOpcache(),
			'packages' => $this->collectPackages(),
			'cfg'      => $cfg,
			'routes'   => $this->app->routes,
			'commands' => $this->app->commands,
		];

		// -- 3. Optional fresh cfg projections for known environments -----
		if ($includeEnvironmentConfigs) {
			$snapshot['cfg_by_env'] = [
				'dev'   => $this->projectEnvironmentConfig('dev', $unredacted),
				'stage' => $this->projectEnvironmentConfig('stage', $unredacted),
				'prod'  => $this->projectEnvironmentConfig('prod', $unredacted),
			];
		}

		return $snapshot;
	}


	// ----------------------------------------------------------------
	// Snapshot collectors
	// ----------------------------------------------------------------

	/**
	 * Collect effective PHP runtime values as observed on the live process.
	 *
	 * These are deliberately the effective runtime values, not the configured cfg values:
	 * timezone via date_default_timezone_get(), charset via ini_get('default_charset'), and
	 * ICU locale via Locale::getDefault(). The configured counterparts remain available in cfg.
	 *
	 * datetime_local and datetime_utc are formatted from a single captured timestamp so they
	 * describe the same observation instant even if the wall clock crosses a second boundary.
	 *
	 * @return array<string,string|null>
	 */
	private function collectRuntime(): array {
		$hostname  = \gethostname();
		$charset   = \ini_get('default_charset');
		$timestamp = \time();

		return [
			'hostname'        => ($hostname === false || $hostname === '') ? null : $hostname,
			'datetime_local'  => \date('c', $timestamp),
			'datetime_utc'    => \gmdate('c', $timestamp),
			'php_version'     => \PHP_VERSION,
			'timezone'        => \date_default_timezone_get(),
			'default_charset' => ($charset === false || $charset === '') ? null : $charset,
			'icu_locale'      => \class_exists(\Locale::class) ? \Locale::getDefault() : null,
		];
	}


	/**
	 * Collect cheap, transport-independent runtime metrics.
	 *
	 * time_s is derived from the monotonic CITOMNI_START_NS marker via hrtime(true) and rounded
	 * to millisecond precision. When CITOMNI_START_NS is undefined it is null rather than a
	 * fabricated zero-duration request.
	 *
	 * @return array<string,int|float|null>
	 */
	private function collectMetrics(): array {
		$timeS = null;
		if (\defined('CITOMNI_START_NS')) {
			$elapsedNs = \hrtime(true) - (int)\CITOMNI_START_NS;
			$timeS     = \round($elapsedNs / 1_000_000_000, 3);
		}

		return [
			'time_s'                  => $timeS,
			'memory_usage_current_kb' => (int)\round(\memory_get_usage() / 1024),
			'memory_usage_peak_kb'    => (int)\round(\memory_get_peak_usage() / 1024),
			'included_files_count'    => \count(\get_included_files()),
			'routes_count'            => \count($this->app->routes),
			'commands_count'          => \count($this->app->commands),
		];
	}


	/**
	 * Collect cheap OPcache diagnostics from local INI only, as a tri-state.
	 *
	 * 'enabled' reflects whether OPcache is actually active for THIS process:
	 *   - null  : cannot be determined (opcache.enable is unavailable, i.e. extension not loaded;
	 *             or, under CLI, the master switch is on but opcache.enable_cli cannot be read).
	 *   - false : the directive is present but OPcache is not enabled for this process (master
	 *             switch off, or CLI switch off under the CLI SAPI).
	 *   - true  : enabled for this process.
	 *
	 * Under the CLI SAPI, OPcache additionally requires opcache.enable_cli, so a host with
	 * opcache.enable=1 and opcache.enable_cli=0 correctly reports false in CLI. PHP_SAPI is
	 * process ground truth (like the timezone/charset/ICU values), NOT CitOmni Mode inference -
	 * this deliberately does not consult App mode.
	 *
	 * 'validate_timestamps' is null when its directive is unavailable, rather than coercing a
	 * missing directive into a misleading boolean.
	 *
	 * @return array<string,bool|null>
	 */
	private function collectOpcache(): array {
		$enable   = \ini_get('opcache.enable');
		$validate = \ini_get('opcache.validate_timestamps');

		if ($enable === false) {
			$enabled = null;
		} elseif (!(bool)\filter_var($enable, \FILTER_VALIDATE_BOOL)) {
			// Master switch off: effective result is false regardless of the CLI switch.
			$enabled = false;
		} elseif (\PHP_SAPI === 'cli') {
			// Master on, but the CLI SAPI only uses OPcache when opcache.enable_cli is on.
			$enableCli = \ini_get('opcache.enable_cli');
			$enabled   = $enableCli === false
				? null
				: (bool)\filter_var($enableCli, \FILTER_VALIDATE_BOOL);
		} else {
			$enabled = true;
		}

		return [
			'enabled'             => $enabled,
			'validate_timestamps' => $validate === false ? null : ($validate !== '0'),
		];
	}


	/**
	 * Inventory installed Composer packages through the runtime API only.
	 *
	 * Returns an associative array keyed by package name, each with 'version' and 'ref',
	 * deterministically sorted by package name. Returns an empty array when
	 * Composer\InstalledVersions is unavailable. Never runs Composer, parses composer.lock,
	 * scans vendor/, or reads composer.json files.
	 *
	 * @return array<string,array{version:string,ref:string|null}>
	 */
	private function collectPackages(): array {
		if (!\class_exists(\Composer\InstalledVersions::class)) {
			return [];
		}

		$packages = [];
		foreach (\Composer\InstalledVersions::getInstalledPackages() as $package) {
			$packages[$package] = [
				'version' => \Composer\InstalledVersions::getPrettyVersion($package) ?? '',
				'ref'     => \Composer\InstalledVersions::getReference($package) ?? null,
			];
		}
		\ksort($packages);

		return $packages;
	}


	/**
	 * Freshly build a cfg projection for one known environment and apply the masking policy.
	 *
	 * Any failure from App::buildConfig() propagates unchanged (fail-fast diagnostics).
	 *
	 * @param  string  $env         The target environment ('dev', 'stage', or 'prod').
	 * @param  bool    $unredacted  Skip secret masking when true.
	 * @return array<string,mixed>
	 * @throws \RuntimeException    Propagated from App::buildConfig() when the cfg cannot be built.
	 */
	private function projectEnvironmentConfig(string $env, bool $unredacted): array {
		$cfg = $this->app->buildConfig($env);

		return $unredacted ? $cfg : $this->maskConfig($cfg);
	}


	// ----------------------------------------------------------------
	// Secret masking
	// ----------------------------------------------------------------

	/**
	 * Recursively mask secret-like cfg values while preserving structure and scalar types.
	 *
	 * Masking uses two deliberately different key rules:
	 * - Explicit secret containers (secret/secrets/credential/credentials as whole segments) propagate
	 *   to every scalar descendant while preserving the nested key structure.
	 * - Ordinary secret-like leaf names redact only string values. Non-string scalar metadata such as
	 *   token_ttl, token_bytes, mask_tokens, or password_changed remains diagnostically visible.
	 * - Allowlisted metadata keys are matched on the raw lowercased scalar key and remain visible even
	 *   inside an inherited secret container. The exemption never cancels inheritance for an array.
	 * - Credential-bearing URI userinfo strings (scheme://userinfo@host) are redacted regardless of key.
	 *
	 * Key classification fails closed: any PCRE failure is treated as a secret match, so a masking-path
	 * error cannot reduce masking.
	 *
	 * Operates on a copy and does not mutate the source array.
	 *
	 * @param  array<mixed>  $config
	 * @param  bool          $inheritedSecret  True when an ancestor is an explicit secret container.
	 * @return array<mixed>
	 */
	private function maskConfig(array $config, bool $inheritedSecret = false): array {
		$out = [];
		foreach ($config as $key => $value) {
			$keyString = (string)$key;

			if (\is_array($value)) {
				$secretContainer = $inheritedSecret
					|| $this->keyMatchesPattern($keyString, self::SECRET_CONTAINER_PATTERN);

				$out[$key] = $this->maskConfig($value, $secretContainer);
				continue;
			}

			// Exact metadata exemptions apply only to scalar values. They never open an inherited subtree.
			if ($this->isAllowlistedKey($keyString)) {
				$out[$key] = $value;
				continue;
			}

			// Explicit secret containers are authoritative and redact every scalar descendant.
			if ($inheritedSecret) {
				$out[$key] = self::REDACTED;
				continue;
			}

			// URI userinfo carries credentials regardless of the cfg key. PCRE errors fail closed.
			if (\is_string($value) && \preg_match(self::CREDENTIAL_URI_PATTERN, $value) !== 0) {
				$out[$key] = self::REDACTED;
				continue;
			}

			// Heuristic key-name masking is string-only; non-string config metadata stays visible.
			$out[$key] = \is_string($value)
				&& $this->keyMatchesPattern($keyString, self::SECRET_KEY_PATTERN)
					? self::REDACTED
					: $value;
		}

		return $out;
	}


	/**
	 * Check whether a scalar key is an exact established non-secret metadata key.
	 *
	 * Matching uses only the raw lowercased key; camelCase variants do not inherit the exemption.
	 *
	 * @param  string  $key  The raw cfg key.
	 * @return bool
	 */
	private function isAllowlistedKey(string $key): bool {
		return isset(self::MASK_KEY_ALLOWLIST[\strtolower($key)]);
	}


	/**
	 * Match a cfg key against one internal whole-segment masking pattern.
	 *
	 * camelCase and acronym boundaries are normalized before lowercasing so authToken, APIKey, and
	 * similar spellings participate in the same segment rules as snake_case keys. Any PCRE failure
	 * fails closed and therefore returns true.
	 *
	 * @param  string  $key      The raw cfg key.
	 * @param  string  $pattern  One of the internal secret-key patterns.
	 * @return bool
	 */
	private function keyMatchesPattern(string $key, string $pattern): bool {
		$normalized = \preg_replace(self::CAMEL_BOUNDARY_PATTERN, '_', $key);
		if ($normalized === null) {
			return true;
		}

		return \preg_match($pattern, \strtolower($normalized)) !== 0;
	}

}
