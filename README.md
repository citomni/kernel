# CitOmni Kernel

> Ultra-lean application kernel for CitOmni-based apps.  
> PHP 8.2+, PSR-4, deterministic boot, zero runtime "magic".

The **kernel** is the tiniest possible layer that:

* builds a **read-only configuration object** from your app + providers,
* builds a **service registry** (simple map -> lazy singletons),
* exposes one thing you use everywhere: **`$app`**.

It does **not** ship HTTP/CLI controllers, routers, error handlers, etc. Those live in the `citomni/http` and `citomni/cli` packages. The kernel stays infrastructure-only and small.

> **Pillars:** _Deterministic boot_ · _Low overhead_ · _TTFB as a KPI_ · _Green by design_

---

## Why this kernel exists

* **Deterministic boot.** The kernel composes config and services in a *predictable*, "last-wins" order. No namespace scanning, no environment-dependent surprises.
* **Zero magic, low overhead.** Arrays + a read-only config wrapper + a minimal service locator. Lazy singletons per `$app` instance.
* **Mode-aware.** HTTP and CLI have different baselines. The kernel is mode-agnostic and takes a `Mode` enum so each delivery layer owns its concerns.
* **Upgrade-safe apps.** Config/services live in your app, providers opt-in via whitelist. No vendor overrides inside your app code.
* ♻️ **Green by design** - lower memory use and CPU cycles -> less server load, more requests per watt, better scalability, smaller carbon footprint.

---

## Who should choose CitOmni?

* **TTFB is a KPI.** You care about cold-start and p95 response times more than “batteries included.”
* **Resource efficiency matters.** You track memory per request and CPU ms per request; overhead ≈ wasted budget.
* **Determinism > magic.** You want predictable boot order and explicit overrides (no reflection/autowiring surprises).
* **Small, fast, edge-friendly.** Runs well in tiny containers and shared hosting; cache warmers are first-class.
* ♻️ **Green by design.** Fewer CPU cycles and lower memory footprint per request reduce energy use and emissions.

---

### Green by design

CitOmni's "Green by design" claim is empirically validated at the framework level.

**Why it’s greener:** constant-driven merges (no reflection), zero boot scanning, and warmed caches minimize CPU cycles and memory churn per request.
This yields:
* **Lower energy per 1k requests** (less CPU time),
* **Higher requests-per-watt** (better consolidation density),
* **Smaller instances/containers** (lower embodied & operating carbon).

The core runtime achieves near-floor CPU and memory costs per request on commodity shared infrastructure, sustaining hundreds of RPS per worker with extremely low footprint.

See the full test report here:
[CitOmni Docs → /reports/2025-10-02-capacity-and-green-by-design.md](https://github.com/citomni/docs/blob/main/reports/2025-10-02-capacity-and-green-by-design.md)


---
## Installation

Require the kernel from your application:

```bash
composer require citomni/kernel
```

Your app will also require `citomni/http` and/or `citomni/cli` for delivery layers.

**Composer autoload (in your app):**

```json
{
	"autoload": {
		"psr-4": {
			"App\\": "src/"
		}
	},
	"config": {
		"optimize-autoloader": true,
		"apcu-autoloader": true
	},
	"suggest": {
		"ext-apcu": "Speed up Composer class loading in production"
	}
}
```

Run:

```bash
composer dump-autoload -o
```

---

## Required constants & preconditions

Delivery layers (HTTP/CLI) are expected to define a few constants early in the entrypoint so the kernel can resolve paths and caches deterministically:

```php
declare(strict_types=1);

// Environment selection (affects config overlays)
define('CITOMNI_ENVIRONMENT', getenv('APP_ENV') ?: 'dev'); // 'dev' | 'stage' | 'prod'

// App/public roots (HTTP)
define('CITOMNI_PUBLIC_PATH', __DIR__);           // no trailing slash; /public path
define('CITOMNI_APP_PATH', \dirname(__DIR__));    // app root; no trailing slash

require CITOMNI_APP_PATH . '/vendor/autoload.php';
```

> CLI entrypoints typically define `CITOMNI_APP_PATH` and `CITOMNI_ENVIRONMENT`. `CITOMNI_PUBLIC_PATH` is HTTP-only.
> **Security note:** Production builds should disable any dev-only providers and never rely on auto-detection for `http.base_url`.

---

## Concepts & responsibilities

* **Kernel responsibilities**

  * Build **config** by merging: *vendor baseline (by mode) -> providers (whitelist) -> app -> env overlay*.
  * Build **service map** in the same precedence.
  * Expose `$app->cfg` (deep, read-only), `$app->__get('id')` for services, and utility getters.
  * Prefer **compiled caches** when present (`/var/cache/cfg.{http|cli}.php` and `/var/cache/services.{http|cli}.php`) to minimize runtime overhead.

* **Not the kernel's job**

  * HTTP routing, sessions, controllers, templates.
  * CLI command runner, scheduler.
  * Error handlers (installed by the **delivery-layer/vendor packages**: `citomni/http`, `citomni/cli`).
  * Business/domain code.

## Further reading

- **Runtime / Execution Mode Layer** — architectural rationale for HTTP vs CLI, baseline ownership, deterministic config/service merging, and why CitOmni deliberately supports only two execution modes.  
  _Doc:_ [`concepts/runtime-modes.md`](https://github.com/citomni/docs/blob/main/concepts/runtime-modes.md)

- **Provider Packages: Design, Semantics, and Best Practices** — explains how provider packages contribute `MAP_*` and `CFG_*` definitions, routes, precedence rules, and versioning; includes guidance on testing, consistency, and conflict avoidance.  
  _Doc:_ [`concepts/services-and-providers.md`](https://github.com/citomni/docs/blob/main/concepts/services-and-providers.md)

---

## Directory layout (package internals)

```

citomni/kernel/
├─ composer.json
├─ LICENSE
├─ README.md
├─ .gitignore
├─ docs/
│  └─ CONVENTIONS.md
├─ src/
│  ├─ App.php                 # Application kernel (config + services)
│  ├─ Arr.php                 # Deterministic merge helpers
│  ├─ Cfg.php                 # Deep, read-only config wrapper
│  ├─ Mode.php                # Enum: HTTP | CLI
│  ├─ Controller/
│  │  └─ BaseController.php   # Thin abstract base - provides $this->app and a second array arg (route/options config)
│  ├─ Model/
│  │  └─ BaseModel.php        # Thin abstract base - provides $this->app and a second array arg (options/config)
│  ├─ Service/
│  │  └─ BaseService.php      # Thin abstract base - provides $this->app and a second array arg (options/config)
│  └─ Command/
│     └─ BaseCommand.php      # Thin abstract base - provides $this->app and a second array arg (options/config)
└─ tests/                     # Unit/integration tests (see CitOmni Testing: [https://github.com/citomni/testing](https://github.com/citomni/testing))
   └─ Command/
      └─ BaseCommandTest.php
 
```

PSR-4: `"CitOmni\\Kernel\\": "src/"`.

> **Note on these base folders**  
> The `Controller/Model/Service` folders only contain **abstract bases** (infrastructure).  
> No delivery-layer controllers, routers, or error handlers live in the kernel — those belong in the delivery-layer packages (`citomni/http`, `citomni/cli`).  
> All three abstract bases follow the same idea: you get a protected `$this->app` and a second array parameter for per-instance configuration (e.g., `$options` or route config). No hidden magic, just the plumbing.  
> Yes, the names sound grand for thin classes. No, they do not secretly spawn MVC.

---

## API reference

### `CitOmni\Kernel\Mode`

```php
enum Mode: string {
	case HTTP = 'http';
	case CLI  = 'cli';
}
```

Pass this to `App` so the kernel can load the correct vendor baselines and provider constants.

---

### `CitOmni\Kernel\Arr`

Small helpers for deterministic, copy-on-write array merges.

* `Arr::mergeAssocLastWins(array $a, array $b): array`
  Recursive merge for **associative** arrays where **later** entries win per key.
  Numeric arrays (lists) are **replaced** by the later side.

* `Arr::normalizeConfig(mixed $x): array`
  Accepts arrays, objects, and `Traversable`; returns a normalized array (objects and traversables are converted recursively).

---

### `CitOmni\Kernel\Cfg` (deep config wrapper)

A **read-only**, **deep** wrapper that lets you write:

```php
$app->cfg->timezone;
$app->cfg->http->base_url;

// 'routes' is intentionally left as a raw array for performance:
$app->cfg->routes['/']['controller'];
```

Key points:

* Top-level and nested **associative arrays** are wrapped as `Cfg` nodes (object-like).
  Numeric arrays (lists) are returned as **plain arrays**.
* Certain keys are **intentionally left raw** (e.g. `routes`) for performance and ergonomics when large arrays are expected.
* Unknown keys throw `OutOfBoundsException` (fail fast).
* Read-only: attempts to set/unset throw `LogicException`.
* Implements `ArrayAccess`, `IteratorAggregate`, `Countable`.
* `toArray()` returns the underlying array.

> You get ergonomic `->` access where it helps, without paying for wrapping large lists as a heavy object tree.

**Raw keys (performance):**  
Some keys are intentionally returned as **raw arrays** for performance and ergonomics with large lists.  
Currently: `routes`. Example:
```php
$controller = $app->cfg->routes['/']['controller'] ?? null;
```

This list is considered part of the stable API and may be **extended** in minor versions (never silently removed).

---

### `CitOmni\Kernel\App`

The application kernel.

```php
final class App {
	public readonly Cfg $cfg;

	public function __construct(string $configDir, Mode $mode);

	public function __get(string $id): object;        // lazy service singletons

	public function getAppRoot(): string;             // absolute app root
	public function getConfigDir(): string;           // absolute /config

	// Build-time cache helper (may be called from HTTP or CLI)
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true): array;

	// Handy helpers
	public function hasService(string $id): bool;
	public function hasAnyService(string ...$ids): bool;
	public function hasPackage(string $slug): bool;
	public function hasNamespace(string $prefix): bool;
	public function memoryMarker(string $label, bool $asHeader = false): void;
}
```

**Helper examples**
```php
<?php
declare(strict_types=1);

// 1) Check availability (fail fast with something human-readable)
if (!$app->hasService('router')) {
	throw new RuntimeException('Router service missing. (No, routes do not self-assemble.)');
}
$app->router->run();

// 2) Pick the first available cache backend (explicit > magic)
$candidates = ['apcuCache', 'redisCache', 'fileCache'];
$cacheId = null;
foreach ($candidates as $id) {
	if ($app->hasService($id)) { $cacheId = $id; break; }
}

if ($cacheId !== null) {
	$app->{$cacheId}->set('healthcheck', 'ok', ttl: 60);
} else {
	// Still okay; just a bit less fast.
	// (Feature flags are cool; explicit checks are cooler.)
}

// 3) Feature toggle by package slug (services or routes contributed by the package)
if ($app->hasPackage('citomni/auth')) {
	// Example from CitOmni Auth; adjust to your app
	$app->role->enforce('ADMIN'); // Business as usual.
} else {
	// Hide admin UI entirely. Stealth mode, but intentional.
}

// 4) Namespace presence (useful for optional modules/plugins)
if ($app->hasNamespace('\CitOmni\Commerce')) {
	// Wire up commerce bits here
	// e.g., $app->router->addRoutes(...); (pseudo)
} else {
	// Keep the brochure site lean. Your TTFB will thank you.
}

// 5) Lightweight timing/memory markers (dev only)
// Header marker (shows as X-CitOmni-MemMark)
$app->memoryMarker('boot', true);

// ... do work ...

// HTML comment marker (visible in page source in dev; users never see it)
$app->memoryMarker('after-routing');

// Tip: mark boundaries around expensive work;
// resist the urge to benchmark every semicolon.
```

**Construction**

* `__construct($configDir, $mode)` expects the **absolute path to your `/config`** directory and a `Mode` enum (`Mode::HTTP` or `Mode::CLI`).
* If compiled cache files exist, the constructor **prefers** them:

  * Config cache:  `<appRoot>/var/cache/cfg.{http|cli}.php`
  * Services cache: `<appRoot>/var/cache/services.{http|cli}.php`
    Both files must `return [ ... ]` (plain arrays, no side effects). If a cache is missing or invalid, the kernel falls back to the normal build pipeline.

**Config**

* `$app->cfg` is a deep, read-only wrapper over the final **merged** config. See [How configuration is built](#how-configuration-is-built-merge-model).

**Services**

* `$app->__get('id')` returns (and caches) a **singleton** instance per id. Instances are constructed lazily the first time they're requested.
* A service definition is either:

  * a **string** FQCN -> instantiated as `new $class($app)`, or
  * an **array**: `['class' => FQCN, 'options' => [...]]` -> `new $class($app, $options)`.
* Unknown ids throw `RuntimeException` (no magic fallback, no namespace scanning).

**Precedence**

* Service map precedence is: **app overrides provider overrides vendor**.

**Cache warmer**

* `warmCache(overwrite: true, opcacheInvalidate: true): array{cfg:?string,services:?string}`
  Compiles the **current mode's** merged config and services, and writes them **atomically** to:

  * `<appRoot>/var/cache/cfg.{http|cli}.php`
  * `<appRoot>/var/cache/services.{http|cli}.php`
    Returns the written paths, or `null` when a file was skipped (`overwrite=false` and it already existed). Best-effort calls `opcache_invalidate()` for the written files.

---

## How configuration is built (merge model)

### TL;DR (final order)
The final config is built in this deterministic order (**last wins**):
1) **Vendor baseline** (by mode)  
2) **Providers** (in the order listed in `/config/providers.php`)  
3) **App base config** (`/config/citomni_{http|cli}_cfg.php`)  
4) **Env overlay** (`/config/citomni_{http|cli}_cfg.{env}.php`, where `{env}` = `dev|stage|prod`)

> If a compiled cache exists (`var/cache/cfg.{http|cli}.php`), it is used directly (fast path).

### Fast path (compiled cache)
If `<appRoot>/var/cache/cfg.{http|cli}.php` exists and returns an array, the kernel loads it and skips the normal merge.  
Use `$app->warmCache()` to generate it atomically during deploy.

### Normal path (fallback / dev)
When you create `new App($configDir, Mode::HTTP)` (or `Mode::CLI`), the kernel does:

1. **Vendor baseline (by mode)**
   - HTTP: `\CitOmni\Http\Boot\Config::CFG`
   - CLI:  `\CitOmni\Cli\Boot\Config::CFG`

2. **Providers** (whitelisted in `/config/providers.php`, in order)
   - If a provider class defines `CFG_HTTP` / `CFG_CLI`, that array is merged **on top** of the baseline.

3. **App base config (last wins)**
   - HTTP: `/config/citomni_http_cfg.php`
   - CLI:  `/config/citomni_cli_cfg.php`

4. **Environment overlay (final layer)**
   - HTTP: `/config/citomni_http_cfg.{env}.php`
   - CLI:  `/config/citomni_cli_cfg.{env}.php`  
     `{env}` comes from `CITOMNI_ENVIRONMENT`.

### Merge rules
- **Associative arrays** -> recursive merge; **later wins** per key.  
- **Numeric arrays (lists)** -> **replaced** by the later side.  
- **Empty values** (`''`, `0`, `false`, `null`, `[]`) still override earlier values.

> **Precedence in one line:** app overrides provider overrides vendor. No magic, no environment-dependent surprises.

### Provider contract (config + services)
Providers contribute config and service-map entries via **class constants**.  
Only constants that exist are read (missing constants are simply ignored).  
**Order matters:** providers are applied in the order they appear in `/config/providers.php`.

```php
namespace Vendor\Feature\Boot;

final class Services {
	public const MAP_HTTP = [
		'feature' => \Vendor\Feature\Http\Service\FeatureService::class,
	];
	public const CFG_HTTP = [
		'feature' => ['enabled' => true, 'retries' => 2],
	];

	public const MAP_CLI = self::MAP_HTTP;
	public const CFG_CLI = [
		'feature' => ['enabled' => true],
	];
}
```

*(Service map precedence is described in "How services are resolved".)*

### Base URL policy (HTTP layer)

* **dev:** if `http.base_url` is empty, the HTTP kernel auto-detects and defines `CITOMNI_PUBLIC_ROOT_URL`.
* **stage/prod:** **no auto-detect** - set an **absolute** `http.base_url` (e.g. `https://www.example.com`) in the env overlay, otherwise boot fails fast.

> Goal: Predictable URLs in production. No, you cannot get "surprise subpaths" as a feature.

---

## How services are resolved

The final **service map** is built in the same order and precedence.

**Fast path (if available)**

1. Try to **load compiled cache**: `<appRoot>/var/cache/services.{http|cli}.php`.
   If it exists and returns an array, use it.

**Normal path**

1. Vendor baseline map (by mode).
2. Provider maps (`MAP_HTTP`/`MAP_CLI`) in the order listed in `/config/providers.php`.
3. App `/config/services.php` (final overrides).

**Definition shapes**

```php
// simplest
'router' => \CitOmni\Http\Service\Router::class,

// with options (kernel passes them as 2nd ctor arg)
'log' => [
	'class'   => \CitOmni\Http\Service\Log::class,
	'options' => ['dir' => __DIR__ . '/../var/logs', 'level' => 'info'],
],
```

**Instantiation**

* FQCN -> `new $class($app)`
* With options -> `new $class($app, $options)`

> Keep your service constructors on the convention: `__construct(App $app, array $options = [])`.

> **Precedence (services):**  
> `vendor baseline` -> overridden by `providers` (listed order) -> overridden by `app/services.php`.  
> Implemented via array union: `$map = $providerMap + $map; $map = $appMap + $map;`

---

## Folder layout (recommended)

```
app-root/
└─ config/
   ├─ citomni_http_cfg.php            # app HTTP config (base)
   ├─ citomni_http_cfg.dev.php        # dev overlay (optional)
   ├─ citomni_http_cfg.stage.php      # stage overlay (optional)
   ├─ citomni_http_cfg.prod.php       # prod overlay (optional)
   ├─ citomni_cli_cfg.php             # app CLI config (base)
   ├─ citomni_cli_cfg.dev.php         # CLI overlay(s) (optional)
   ├─ providers.php                   # list of provider FQCNs (whitelist)
   ├─ services.php                    # app service map overrides (HTTP & CLI)
   ├─ routes.php                      # HTTP routes (exact, regex, error routes)
   └─ roles.php                       # ROLE_* constants (optional)
```

**Environment selection** is controlled by `CITOMNI_ENVIRONMENT` (`dev|stage|prod`) defined in `/public/index.php` (HTTP) or `/bin/app` (CLI).

*(Optional in production builds)*
`/var/cache/` may contain compiled artifacts generated by `warmCache()`:

* `cfg.http.php`, `services.http.php`
* `cfg.cli.php`,  `services.cli.php`

---

## Usage examples

### Booting for HTTP

Normally you'll use `\CitOmni\Http\Kernel` (from `citomni/http`), which creates the `App` internally and sets runtime defaults.

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

// Pass the public/ directory (or /config; both are supported)
\CitOmni\Http\Kernel::run(__DIR__);
```

> You can also instantiate `App` directly for debugging:
>
> ```php
> $app = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::HTTP);
> ```

**Note:** Direct `new App(..., Mode::HTTP)` is fine for debugging, but the **HTTP kernel** normally sets timezone/charset, defines the base URL in `dev`, and installs the HTTP error handler. For production, prefer `\CitOmni\Http\Kernel::run(__DIR__)`.

### Booting for CLI

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

\CitOmni\Cli\Kernel::run(__DIR__ . '/../config', $argv);
```

### Reading config (deep access)

```php
$tz      = $app->cfg->timezone;
$charset = $app->cfg->charset;

$baseUrl    = $app->cfg->http->base_url;
$trustProxy = (bool)$app->cfg->http->trust_proxy;

// Lists remain arrays (numeric-indexed arrays are not wrapped)
$locales = $app->cfg->locales ?? ['en'];

// 'routes' intentionally left raw
$indexCtrl = $app->cfg->routes['/']['controller'] ?? null;

// Convert any node back to array if needed
$httpArr = $app->cfg->http->toArray();
```

### Declaring & overriding services

**Vendor (HTTP package) might declare:**

```php
final class Services {
	public const MAP = [
		'router'   => \CitOmni\Http\Service\Router::class,
		'request'  => \CitOmni\Http\Service\Request::class,
		'response' => \CitOmni\Http\Service\Response::class,
		'session'  => \CitOmni\Http\Service\Session::class,
		'view'     => \CitOmni\Http\Service\View::class,
	];
}
```

**A provider contributes (opt-in via `/config/providers.php`):**

```php
final class Services {
	public const MAP_HTTP = [
		'cart'     => \CitOmni\Commerce\Http\Service\Cart::class,
		'checkout' => \CitOmni\Commerce\Http\Service\Checkout::class,
	];
	public const CFG_HTTP = [
		'payments' => ['gateway' => 'stripe', 'retry' => 2],
	];
}
```

**Your app overrides one entry and adds your own:**

```php
return [
	// override vendor router with options
	'router' => [
		'class'   => \App\Service\MyRouter::class,
		'options' => ['cacheDir' => __DIR__ . '/../var/cache/routes'],
	],

	// add your own services
	'log' => [
		'class'   => \App\Service\Log::class,
		'options' => ['dir' => __DIR__ . '/../var/logs', 'level' => 'info'],
	],
];
```

---

## Performance notes

* **Lazy services**: nothing is constructed until first use (per request/process).
* **No scanning**: services are resolved by an explicit map, not by searching namespaces.
* **Deep config wrapper**: ergonomic `->` access; large lists (like `routes`) remain arrays.
* **Composer**:

  ```json
  "config": {
  	"optimize-autoloader": true,
  	"apcu-autoloader": true
  }
  ```

  `composer dump-autoload -o`
* **OPcache (prod)**: enable; consider `validate_timestamps=0` (reset on deploy).
* **Compiled caches (prod)**: pre-merge config & services to `/var/cache/cfg.{http|cli}.php` and `/var/cache/services.{http|cli}.php`.
  Use `$app->warmCache()` to generate them atomically (best-effort `opcache_invalidate()`).

> For production images/pipelines, prefer `composer install --no-dev --classmap-authoritative`.

## Operational KPIs to track

* **TTFB (p50/p95)** per route
* **CPU ms/request** (app layer only)
* **RSS memory/request** (steady-state)
* **Requests per core** at target p95 latency
* **Energy per 1k requests** (if you can meter at the host or rack level)

Tip: use `App::memoryMarker()` around hot paths to validate improvements rather than guessing.

### Compiled cache: Deploy snippet

Warm caches atomically during deploy (HTTP and CLI as needed):

```php
$app = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::HTTP);
$app->warmCache(overwrite: true, opcacheInvalidate: true);

// If you also use CLI:
$cli = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::CLI);
$cli->warmCache(overwrite: true, opcacheInvalidate: true);
```

Ensure the process can write to `<appRoot>/var/cache/` and that your deploy invalidates OPcache (either via `opcache_invalidate()` as above or a full `opcache_reset()`).

---

## Dev vs prod checklist

- **Base URL:**  
  - `dev`: Auto-detected by the HTTP kernel when `http.base_url` is empty.  
  - `stage/prod`: **Must** set an absolute `http.base_url` in the env overlay (no auto-detect).
- **OPcache:** Enable in production; consider `validate_timestamps=0` (invalidate on deploy).
- **Caches:** Warm `var/cache/cfg.{http|cli}.php` and `var/cache/services.{http|cli}.php` during deploy.

---

## Testing with CitOmni Testing (dev-only)

CitOmni Testing is an integrated, dev-only toolkit for running correctness, regression, and integration tests **inside** a fully booted CitOmni app. Same boot, same config layering, zero production overhead. Results can be exported for CI and reporting. (Yes, it is lean. No, it will not install a testing monastery in your app.)

**Repo:** https://github.com/citomni/testing

### Quick start

1) Install (dev only):
```bash
composer require --dev citomni/testing
```

2. Enable the provider in `/config/providers.php` **only in dev**:

```php
<?php
declare(strict_types=1);

return array_values(array_filter([
	// ... other providers ...

	(defined('CITOMNI_ENVIRONMENT') && CITOMNI_ENVIRONMENT === 'dev')
		? \CitOmni\Testing\Boot\Services::class
		: null,
]));
```

3. Boot your app as usual. The testing UI is mounted under a dev-only route (e.g. `/__tests`) and a POST endpoint to run tests. Exact routes come from the Testing provider’s `CFG_HTTP['routes']`.

### What you get

* **Real runtime, real answers.** Tests execute in the same environment model as prod: `vendor baseline -> providers -> app -> env overlay`.
* **Deterministic, reproducible runs.** No namespace scanning, no surprise toggles.
* **Zero prod overhead.** Not enabled unless you opt in via provider (and typically only when `CITOMNI_ENVIRONMENT === 'dev'`).
* **CI-friendly.** Outputs can be exported to common formats for pipelines and dashboards.

### Safety checklist

* Keep the provider gated to `dev` (see snippet above).
* If you must expose it temporarily, put it behind IP allowlist and/or basic auth.
* Do not ship the Testing provider to staging or production. Your future self will thank you.

> Tip: CitOmni Testing is optional. For pure unit tests you can use any harness you like; the value here is **integration** under a true CitOmni boot.

---

## Error handling philosophy

The kernel **does not** install an error/exception handler. Delivery layers do:

* HTTP: `\CitOmni\Http\Exception\ErrorHandler::install([...])`
* CLI:  `\CitOmni\Cli\Exception\ErrorHandler::install([...])`

The kernel's job is to **fail fast** and surface issues early (unknown cfg keys, unknown service ids, invalid provider classes). Your global handler logs.

---

## Exceptions & failure modes (fail fast)

The kernel does not swallow errors; typical exceptions include:

- `RuntimeException("Config directory not found: ...")` - `new App($configDir, $mode)` with an invalid path.
- `RuntimeException("Provider class not found: ...")` - a FQCN listed in `/config/providers.php` is not autoloadable.
- `RuntimeException("Invalid service definition for 'id'")` - malformed map entry (neither FQCN string nor `['class'=>, 'options'=>]`).
- `OutOfBoundsException("Unknown cfg key: '...'")` - strict access in `Cfg` for missing keys.
- `LogicException('Cfg is read-only.')` - attempting to set/unset on `Cfg`.
- `RuntimeException("Unable to create cache directory: ..." | "Failed writing cache tmp: ..." | "Failed moving cache into place: ...")` - I/O errors from `warmCache()`.

---

## FAQ / common pitfalls

**"Unknown app component: app->X"**
The id `X` is not present in the final service map. Add/override it in `/config/services.php` or enable a provider that contributes it. (Run `composer dump-autoload -o` if you just added a new class.)

**"Provider class not found ..."**
An entry in `/config/providers.php` points to a non-autoloadable FQCN. Check package install and PSR-4 namespace. Providers must be loadable for their constants to be read.

**"Config must return array or object."**
Your `citomni_http_cfg.php` (or CLI variant) must return an **array** (recommended) or an **object**; scalars are invalid. If you include files (like `routes.php`), ensure those return arrays too.

**Deep config access throws `OutOfBoundsException`**
The `Cfg` wrapper is strict-unknown keys throw. Use `isset($app->cfg->someKey)` to guard, or move the key into your cfg files.

**Service constructor signature**
Stick to `__construct(App $app, array $options = [])`. The kernel passes `$options` only when your service map entry uses the `['class'=>..., 'options'=>...]` shape.

**Compiled cache not picked up**
Ensure files exist at:

* `<appRoot>/var/cache/cfg.{http|cli}.php`
* `<appRoot>/var/cache/services.{http|cli}.php`
  They must `return [ ... ];` (plain arrays). If OPcache runs with `validate_timestamps=0`, either let `warmCache()` call `opcache_invalidate()` (default) or perform a full `opcache_reset()` as part of deploy.

---

## Versioning & BC

* Targets **PHP 8.2+** only.
* Semantic Versioning for the kernel's **public API** (class names, method signatures, merge behavior).
* The kernel avoids catching exceptions-this is deliberate and part of the contract.

---

## Contributing

* Code style: PHP 8.2+, PSR-4, **tabs**, K&R braces.
* Keep vendor files side-effect free (OPcache-friendly).
* No exception swallowing; let the global error handler log.

---

## Coding & Documentation Conventions

All CitOmni and LiteX projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Kernel** is open-source under the **MIT License**.  
See: [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
Usage of the name or logo must follow the policy in **[NOTICE](NOTICE)**. Do not imply endorsement or
affiliation without prior written permission.

### FAQ (licensing)

**Can I build proprietary providers/plugins on top of CitOmni?**  
Yes. Providers/apps may be distributed under any terms (including proprietary). MIT places no copyleft obligations on your code.

**Do I need to keep attribution?**  
Yes. Keep the copyright and license notice from `LICENSE` in distributions of the Software.

**Can I call my product "CitOmni <Something>"?**  
No. The name "CitOmni" and the logo are protected by trademark. Do not suggest sponsorship, endorsement, or affiliation without permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.  
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos,  
or imply sponsorship, endorsement, or affiliation without prior written permission.  
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.  
For details, see the project's [NOTICE](NOTICE).

---

## Author

Developed by **Lars Grove Mortensen** © 2012-present
Contributions and pull requests are welcome!

---

Built with ❤️ on the CitOmni philosophy: **low overhead**, **high performance**, and **ready for anything**.
