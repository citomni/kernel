# CitOmni Kernel

Ultra-lean application kernel for CitOmni-based applications.

`citomni/kernel` is the smallest common runtime layer in the CitOmni ecosystem. It provides the structural primitives shared by CitOmni-based applications and packages, while deliberately avoiding transport runtimes, persistence logic, and framework-level magic.

The package exists to keep application boot deterministic, explicit, cheap to execute, and frugal with resources. In CitOmni terms, that means performance is not treated as a luxury feature bolted on later, but as part of the architectural contract from day one.

Small runtime surface. Predictable behavior. Less waste. Green by design.

## What this package is

The kernel provides:

- `App` as the central application object
- `Cfg` as a strict, deep, read-only configuration wrapper
- `Arr` for deterministic array normalization and merge helpers
- `Mode` for execution-mode selection
- thin abstract base classes for:
	- `BaseController`
	- `BaseCommand`
	- `BaseOperation`
	- `BaseRepository`
	- `BaseService`

In practice, the kernel gives the rest of CitOmni a shared structural contract without trying to become a transport runtime on its own.

## What this package is not

The kernel does **not** provide:

- HTTP routing runtime
- request / response handling
- session handling
- cookies
- CSRF protection
- template rendering
- CLI command dispatch
- error handling / exception rendering
- mail, logging, DB access, or other infrastructure services

Those concerns belong in mode packages such as `citomni/http` and `citomni/cli`, or in provider/application code.

## Design goals

CitOmni kernel is built around four priorities:

1. Determinism
	- Boot order must be explicit and reviewable.
	- Merge rules must be stable.
	- No hidden registration or discovery.	

2. Low overhead
	- Minimal indirection.
	- Minimal runtime work.
	- No namespace scanning or reflection-driven service discovery.
	- Less framework work per request/process.

3. High performance
	- Predictable data shapes.
	- Fail-fast behavior.
	- Cheap runtime primitives.
	- Lower overhead means lower CPU time, lower memory churn, and less wasted work.

4. Maintainable structure
	- Transport concerns stay outside the kernel.
	- Persistence concerns stay outside the kernel.
	- Shared contracts stay small and explicit.

CitOmni's performance philosophy is practical rather than theatrical: do less, allocate less, surprise less. That is good for latency, good for hosting costs, and, at scale, simply better engineering hygiene.

## Installation

Install through Composer:

```bash
composer require citomni/kernel
````

In real applications, `citomni/kernel` is normally used together with one or both mode packages:

* `citomni/http`
* `citomni/cli`

## Package structure

Current package structure:

```text
citomni/kernel/
├── .gitignore
├── composer.json
├── CONVENTIONS.md
├── LICENSE
├── NOTICE
├── README.md
├── TRADEMARKS.md
├── src/
│   ├── App.php
│   ├── Arr.php
│   ├── Cfg.php
│   ├── Mode.php
│   ├── Command/
│   │   └── BaseCommand.php
│   ├── Controller/
│   │   └── BaseController.php
│   ├── Operation/
│   │   └── BaseOperation.php
│   ├── Repository/
│   │   └── BaseRepository.php
│   └── Service/
│       └── BaseService.php
└── tests/
    └── Command/
        └── BaseCommandTest.php
```

PSR-4 autoload root:

```json
"CitOmni\\Kernel\\": "src/"
```

## Architectural role

Within CitOmni, the kernel defines the smallest shared contract used by applications and packages.

Conceptually:

* Adapters speak transport protocols.
* Operations decide what happens.
* Repositories talk to storage.
* Services provide reusable tools.
* Utils compute.
* Exceptions encode failure semantics.

The kernel itself only ships the shared primitives and thin base classes behind that structure. It does not implement transport runtimes or infrastructure services itself.

## Entry-point constants

CitOmni applications define a few constants early in the entrypoint.

Typical HTTP entrypoint:

```php
<?php
declare(strict_types=1);

define('CITOMNI_ENVIRONMENT', 'dev');           // dev | stage | prod
define('CITOMNI_PUBLIC_PATH', __DIR__);
define('CITOMNI_APP_PATH', \dirname(__DIR__));
// Optional (recommended for stage/prod):
// define('CITOMNI_PUBLIC_ROOT_URL', 'https://www.example.com');

require CITOMNI_APP_PATH . '/vendor/autoload.php';
```

Notes:

* `CITOMNI_PUBLIC_PATH` is HTTP-only.
* `CITOMNI_APP_PATH` points to the app root.
* `CITOMNI_PUBLIC_ROOT_URL` is optional in development, but recommended explicitly in stage/production.
* The kernel assumes these are defined by the delivery-layer entrypoint, not by the kernel itself.

## `Mode`

`Mode` selects the active execution mode.

```php
enum Mode: string {
	case HTTP = 'http';
	case CLI  = 'cli';
}
```

Pass this to `App` so the kernel can load the correct mode-specific baselines and provider constants.

## `Arr`

`Arr` contains small deterministic array helpers used by the kernel.

### `Arr::mergeAssocLastWins(array $a, array $b): array`

Recursive merge for associative arrays where the later side wins per key.

Behavior:

* associative arrays are merged recursively
* numeric arrays (lists) are replaced, not merged
* integer keys are overwritten directly by the later side

### `Arr::normalizeConfig(mixed $x): array`

Normalizes config-like input into a plain array.

Accepted inputs:

* array
* object
* `Traversable`

Anything else throws `RuntimeException`.

## `Cfg`

`Cfg` is a strict, deep, read-only wrapper around the merged configuration array.

Examples:

```php
$app->cfg->http->base_url;
$app->cfg->locale->timezone;
$app->cfg->security->csrf_protection;
```

Behavior:

* associative arrays are wrapped as nested `Cfg` nodes
* numeric arrays remain plain arrays
* unknown keys throw `OutOfBoundsException`
* writes/unsets throw `LogicException`
* implements `ArrayAccess`, `IteratorAggregate`, and `Countable`
* `toArray()` returns the underlying raw array

Important access note:

```php
$csrf = (bool)($this->app->cfg->security->csrf_protection ?? true);
```

The null-coalescing operator is only safe for the **final** key in the chain. If intermediate nodes may not exist, guard them first with `isset()`.

## `App`

`App` is the central application object.

```php
final class App {
	public readonly Cfg $cfg;
	public readonly array $routes;

	public function __construct(string $configDir, Mode $mode);
	public function __get(string $id): object;

	public function getAppRoot(): string;
	public function getConfigDir(): string;

	public function buildConfig(?string $env = null): array;
	public function warmCache(bool $overwrite = true, bool $opcacheInvalidate = true): array;

	public function hasService(string $id): bool;
	public function hasAnyService(string ...$ids): bool;
	public function hasPackage(string $slug): bool;
	public function hasNamespace(string $prefix): bool;

	public function vardumpServices(): void;
	public function memoryMarker(string $label, bool $asHeader = false): void;
}
```

### Responsibilities

`App` is responsible for:

* building final configuration
* building final route arrays
* building the final service map
* exposing services as lazy singletons
* exposing the final config as a strict read-only object

At a practical level, `App` is the one shared object used throughout a CitOmni request/process.

### Construction

`new App($configDir, $mode)` expects:

* a path that resolves to your app's `/config` directory
* a `Mode` enum (`Mode::HTTP` or `Mode::CLI`)

If the config directory cannot be resolved, construction fails fast.

If compiled cache files exist, the constructor prefers them:

* `<appRoot>/var/cache/cfg.{http|cli}.php`
* `<appRoot>/var/cache/routes.{http|cli}.php`
* `<appRoot>/var/cache/services.{http|cli}.php`

If a cache file is missing or invalid, the kernel falls back to the normal build pipeline.

### Service resolution

Services are resolved lazily from a deterministic service map.

Supported definition shapes:

```php
'mailer' => \App\Service\Mailer::class,
```

or:

```php
'mailer' => [
	'class' => \App\Service\Mailer::class,
	'options' => [
		'transport' => 'smtp',
	],
],
```

Instantiation behavior is explicit:

* string FQCN -> `new $class($this)`
* array definition -> `new $class($this, $options)`

Unknown service ids fail fast.

There is:

* no autowiring
* no namespace scanning
* no fallback discovery
* no hidden service registration

### Helper examples

```php
<?php
declare(strict_types=1);

// 1) Check availability
if (!$app->hasService('router')) {
	throw new RuntimeException('Router service missing.');
}
$app->router->run();

// 2) Pick the first available cache backend
$candidates = ['apcuCache', 'redisCache', 'fileCache'];
$cacheId = null;

foreach ($candidates as $id) {
	if ($app->hasService($id)) {
		$cacheId = $id;
		break;
	}
}

if ($cacheId !== null) {
	$app->{$cacheId}->set('healthcheck', 'ok', ttl: 60);
}

// 3) Feature toggle by package slug
if ($app->hasPackage('citomni/auth')) {
	$app->role->enforce('ADMIN');
}

// 4) Namespace presence
if ($app->hasNamespace('\CitOmni\Commerce')) {
	// Optional commerce module is installed
}

// 5) Read routes directly
$homeController = $app->routes['/']['controller'] ?? null;

// 6) Lightweight timing/memory markers (dev only)
$app->memoryMarker('boot', true);
$app->memoryMarker('after-routing');
```

## Merge model

CitOmni kernel follows deterministic merge rules.

### Config and routes

For configuration and routes, merge behavior is:

* deep associative merge
* last wins

Effective order:

1. vendor baseline
2. providers
3. app base
4. environment overlay

### Services

For services, merge behavior is:

* PHP array union (`+`)
* left wins

Effective precedence:

* app overrides provider
* provider overrides vendor

In short:

* config/routes: last wins
* services: left wins

That distinction is intentional and part of the contract.

## Build sources

### Config

`App::buildConfig()` builds config in this order:

1. mode-package baseline config
2. provider `CFG_HTTP` / `CFG_CLI`
3. app config file:

   * `config/citomni_http_cfg.php`
   * `config/citomni_cli_cfg.php`
4. environment overlay:

   * `config/citomni_http_cfg.{env}.php`
   * `config/citomni_cli_cfg.{env}.php`

### Routes

Routes are built in this order:

1. mode-package baseline routes
2. provider `ROUTES_HTTP` / `ROUTES_CLI`
3. app routes file:

   * `config/citomni_http_routes.php`
   * `config/citomni_cli_routes.php`
4. environment overlay:

   * `config/citomni_http_routes.{env}.php`
   * `config/citomni_cli_routes.{env}.php`

### Services

Services are built in this order:

1. mode-package baseline map
2. provider `MAP_HTTP` / `MAP_CLI`
3. app override file:

   * `config/services.php`

## Providers and boot metadata

Provider packages contribute boot metadata through:

```php
src/Boot/Registry.php
```

Typical constants are:

* `MAP_HTTP`
* `MAP_CLI`
* `CFG_HTTP`
* `CFG_CLI`
* `ROUTES_HTTP`
* `ROUTES_CLI`

All are optional.

Important rule:

* routes must not be nested inside config constants

A provider may contribute only services, only config, only routes, or any combination of the three.

## Mode-package baseline note

The current kernel implementation reads mode-package baselines from:

* `\CitOmni\Http\Boot\Registry`
* `\CitOmni\Cli\Boot\Registry`

Specifically, it expects mode-specific constants such as:

* `CFG_HTTP` / `CFG_CLI`
* `MAP_HTTP` / `MAP_CLI`
* `ROUTES_HTTP` / `ROUTES_CLI`

So while provider packages are documented via `src/Boot/Registry.php`, the current kernel code also expects Registry-based baseline access from the mode packages it integrates with.

## Caches

The kernel can use compiled cache artifacts for configuration, routes, and services.

Cache targets:

* `var/cache/cfg.http.php`
* `var/cache/routes.http.php`
* `var/cache/services.http.php`
* `var/cache/cfg.cli.php`
* `var/cache/routes.cli.php`
* `var/cache/services.cli.php`

These caches are performance tools, not correctness mechanisms.

Expected properties:

* side-effect free
* plain PHP files
* deterministic content
* safe to regenerate

### `warmCache()`

`warmCache(bool $overwrite = true, bool $opcacheInvalidate = true): array` rebuilds config, routes, and services, then writes the three cache files atomically.

Returned shape:

```php
[
	'cfg' => '/absolute/path/to/cfg.http.php' | null,
	'routes' => '/absolute/path/to/routes.http.php' | null,
	'services' => '/absolute/path/to/services.http.php' | null,
]
```

If `overwrite` is `false` and a target already exists, that entry returns `null`.

Typical deploy usage:

```php
$app = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::HTTP);
$app->warmCache(overwrite: true, opcacheInvalidate: true);

$cli = new \CitOmni\Kernel\App(__DIR__ . '/../config', \CitOmni\Kernel\Mode::CLI);
$cli->warmCache(overwrite: true, opcacheInvalidate: true);
```

Ensure the process can write to `<appRoot>/var/cache/`.

## Dev helpers

### `vardumpServices()`

Dev-only helper that dumps the current service map.

Outside `dev`, it throws `RuntimeException`.

### `memoryMarker(string $label, bool $asHeader = false): void`

Dev-oriented lightweight marker for memory/timing diagnostics.

Behavior:

* outside `dev`, it returns immediately
* with `$asHeader = true` and headers still open, it emits:

  * `X-CitOmni-MemMark: ...`
* otherwise it emits an HTML comment

This is intentionally cheap and practical, not a profiling framework.

## Base classes shipped by the kernel

The base classes in this package are intentionally thin.

They exist to standardize constructor contracts and shared access to `App`, not to hide behavior behind inheritance.

### `BaseController`

HTTP-facing adapter base.

Responsibilities belong to the adapter layer:

* request parsing
* CSRF/session checks
* response shaping
* view/model translation for transport output

A controller must not own SQL or non-trivial orchestration.

### `BaseCommand`

CLI-facing adapter base.

Responsibilities belong to the adapter layer:

* argument parsing
* terminal output
* exit codes

A command must not own SQL or cross-cutting workflow logic.

### `BaseOperation`

Transport-agnostic orchestration base.

An operation:

* is instantiated explicitly
* is SQL-free
* may coordinate services and repositories
* returns domain-shaped arrays

Operations are introduced when orchestration earns its existence, not by default.

### `BaseRepository`

Persistence base.

Repositories own:

* SQL
* datastore IO
* persistence-oriented mapping and lookup logic

Repositories do not own transport shaping or workflow orchestration.

### `BaseService`

Reusable App-aware service base.

Services are:

* registered in the service map
* resolved through `$app->{id}`
* typically infrastructure or cross-cutting helpers

Examples include mail, logging, text lookup, formatting, caching, and similar reusable tools.

## Recommended usage pattern

The normal decision path in CitOmni is:

* trivial read/write:

  * Controller/Command -> Repository

* non-trivial orchestration:

  * Controller/Command -> Operation -> Repository/Services

This keeps the call graph explicit and prevents needless abstraction.

## Example: explicit operation usage

```php
<?php
declare(strict_types=1);

use App\Operation\PublishArticle;

$operation = new PublishArticle($this->app);
$result = $operation->run($input);
```

Operations are instantiated explicitly with `new ...($this->app)`. They are not hidden behind container magic.

## Example: service access

```php
<?php
declare(strict_types=1);

if ($this->app->hasService('mailer')) {
	$this->app->mailer->send($message);
}
```

## Example: config access

```php
<?php
declare(strict_types=1);

$timezone = $this->app->cfg->locale->timezone ?? 'UTC';
```

## Example: route access

```php
<?php
declare(strict_types=1);

$route = $this->app->routes['/dashboard'] ?? null;
```

## Application layout context

The kernel package itself is small, but it is designed to sit inside the wider CitOmni application structure.

Typical application-layer folders include:

* `src/Http/Controller`
* `src/Cli/Command`
* `src/Operation`
* `src/Repository`
* `src/Service`
* `src/Exception`
* `config`
* `language`
* `templates`
* `public`
* `bin`
* `var`

The kernel does not require every folder to exist in this package. It only supplies the shared structural primitives used by that architecture.

## Performance philosophy

The kernel is designed around mechanical sympathy with ordinary PHP runtimes.

That means:

- explicit wiring over discovery
- arrays with predictable shapes
- lazy service instantiation
- fail-fast behavior
- no hidden runtime work

The goal is not fashionable abstraction. The goal is a small, predictable runtime surface with low operating cost.

CitOmni prefers architecture that earns its keep. If a layer, helper, or boot mechanism adds overhead without adding proportional value, it does not belong in the kernel. The fastest code is not always the code with the cleverest story, but very often the code that simply does less.

That is part of what "green by design" means here: fewer moving parts, fewer wasted cycles, and less accidental framework work between input and outcome.

## Why "green by design" matters

CitOmni treats resource efficiency as an engineering concern, not a slogan.

When a framework avoids unnecessary discovery, hidden boot work, and inflated abstraction layers, the result is not only easier to reason about. It also tends to use less CPU time, less memory, and fewer machine cycles to complete the same job.

For a single request, that may look modest. Across many requests, many CLI runs, and many deployments, it adds up. Lower overhead is good for speed, good for operational cost, and good for the broader goal of building software that wastes fewer resources.

## Failure philosophy

The kernel fails fast by design.

Typical failure cases include:

* missing config directory
* malformed provider registration
* invalid service definitions
* unknown service ids
* invalid cache writes
* unknown config keys

The kernel should not silently recover from structural mistakes that deserve to be fixed.

---

## Contributing

* Code style: PHP 8.2+, PSR-4, tabs, K&R braces
* Keep vendor files side-effect free
* Do not hide failures behind silent fallbacks
* Prefer deterministic structure over cleverness

---

## Coding & Documentation Conventions

All CitOmni and LiteX projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni Kernel** is open source under the **MIT License**.
See: [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
Use of the name or logo must follow the policy in **[NOTICE](NOTICE)**. Do not imply endorsement or affiliation without prior written permission.

### FAQ (licensing)

**Can I build proprietary providers or plugins on top of CitOmni?**
Yes. Your apps and providers may use any license terms you choose.

**Do I need to keep attribution?**
Yes. Keep the copyright and license notice from `LICENSE` in distributions of the Software.

**Can I call my product "CitOmni Something"?**
No. The name "CitOmni" and the logo are protected by trademark. Do not imply sponsorship, endorsement, or affiliation without permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to CitOmni, but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.

Do not register or use `citomni` (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.

For details, see [NOTICE](NOTICE).

---

## Author

Developed by **Lars Grove Mortensen** © 2012-present.
Contributions and pull requests are welcome.

---

CitOmni Kernel keeps the common core deliberately small, because efficient software should spend its resources on the application's work, not on the framework getting ready to do it.

Built with ❤️ on the CitOmni philosophy: **low overhead**, **high performance**, and **ready for anything**.
