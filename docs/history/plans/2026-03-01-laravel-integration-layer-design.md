# Laravel Integration Layer Design

**Date:** 2026-03-01
**Status:** Approved
**Scope:** Package auto-discovery, middleware pipelines, config caching

## Context

Waaseyaa absorbed many Laravel 12 patterns implicitly (service providers, typed config, attribute routing, queue semantics, typed entities, component rendering, CLI scaffolding, real-time broadcasting). Three high-leverage improvements remain unaddressed:

1. **Package auto-discovery** — self-registering packages via manifests + attribute scanning
2. **Middleware everywhere** — typed middleware pipelines for HTTP, events, and jobs
3. **Config caching** — compiled config for zero-parse production boot

These are foundational and compound across everything else in the architecture.

## Approach: Separate Compilers, CLI-driven Build Step

Three focused compilers, each independently invocable, orchestrated by `waaseyaa optimize`:

| Feature | Compiler | Artifact |
|---------|----------|----------|
| Package auto-discovery | `PackageManifestCompiler` | `storage/framework/packages.php` |
| Middleware stacks | `MiddlewarePipelineCompiler` | `storage/framework/middleware.php` |
| Config cache | `ConfigCacheCompiler` | `storage/framework/config.php` |

Dev: auto-compilation on first use. Prod: `waaseyaa optimize` pre-compiles everything.

## 1. Package Auto-Discovery

### Manifest format (coarse-grained)

Extends existing `extra.waaseyaa` in each package's `composer.json`:

```json
{
  "extra": {
    "waaseyaa": {
      "providers": ["Waaseyaa\\Node\\NodeServiceProvider"],
      "commands": ["Waaseyaa\\Node\\Command\\NodeCreateCommand"],
      "routes": ["Waaseyaa\\Node\\NodeRouteProvider"],
      "migrations": "migrations/",
      "config": "config/"
    }
  }
}
```

### Attribute scanning (fine-grained)

PHP 8 attributes on classes, scanned at compile time within declared autoload namespaces:

```php
#[AsFieldType(id: 'text', label: 'Text')]
final class TextField implements FieldTypeInterface { ... }

#[AsListener(event: EntitySaved::class)]
final class InvalidateCacheOnSave { ... }

#[AsMiddleware(pipeline: 'http', priority: 100)]
final class TenantResolverMiddleware implements HttpMiddlewareInterface { ... }
```

### PackageManifestCompiler

- Reads `vendor/composer/installed.json` for manifest declarations
- Scans declared autoload namespaces for PHP 8 attributes
- Produces `storage/framework/packages.php`
- Returns typed `PackageManifest` object

Cached artifact structure:

```php
return [
    'providers' => ['Waaseyaa\\Node\\NodeServiceProvider', ...],
    'commands' => ['Waaseyaa\\Node\\Command\\NodeCreateCommand', ...],
    'routes' => ['Waaseyaa\\Node\\NodeRouteProvider', ...],
    'migrations' => ['waaseyaa/node' => '/path/to/migrations/', ...],
    'field_types' => ['text' => 'Waaseyaa\\Field\\Plugin\\TextField', ...],
    'listeners' => [
        'Waaseyaa\\Entity\\Event\\EntitySaved' => [
            ['class' => 'Waaseyaa\\Cache\\Listener\\InvalidateCacheOnSave', 'priority' => 0],
        ],
    ],
    'middleware' => [
        'http' => [['class' => '...', 'priority' => 100], ...],
        'event' => [['class' => '...', 'priority' => 50], ...],
        'job' => [['class' => '...', 'priority' => 50], ...],
    ],
];
```

### File locations

- `packages/foundation/src/Discovery/PackageManifestCompiler.php`
- `packages/foundation/src/Discovery/PackageManifest.php`
- `packages/foundation/src/Attribute/AsCommand.php`
- `packages/foundation/src/Attribute/AsEntityType.php`
- `packages/foundation/src/Attribute/AsFieldType.php`
- `packages/foundation/src/Attribute/AsListener.php`
- `packages/foundation/src/Attribute/AsMiddleware.php`

### Backwards compatibility

`ProviderDiscovery` is preserved as-is. The new compiler delegates to it for provider discovery and extends the result with additional manifest keys.

### Dev vs. prod

- **Dev:** `PackageManifest::load()` checks for `packages.php`. Compiles on first access if missing.
- **Prod:** `waaseyaa optimize` pre-compiles. Missing cache throws exception.

## 2. Middleware Pipelines

### Design constraint

Separate typed interfaces per pipeline (HTTP, event, job). Same structural pattern (process + next), but type-safe for each context. No accidental cross-pipeline wiring.

### Interfaces

```php
// HTTP
interface HttpMiddlewareInterface {
    public function process(Request $request, HttpHandlerInterface $next): Response;
}
interface HttpHandlerInterface {
    public function handle(Request $request): Response;
}

// Events
interface EventMiddlewareInterface {
    public function process(DomainEvent $event, EventHandlerInterface $next): void;
}
interface EventHandlerInterface {
    public function handle(DomainEvent $event): void;
}

// Jobs
interface JobMiddlewareInterface {
    public function process(Job $job, JobNextHandlerInterface $next): void;
}
interface JobNextHandlerInterface {
    public function handle(Job $job): void;
}
```

### Pipeline classes

One per type (`HttpPipeline`, `EventPipeline`, `JobPipeline`). Each composes a stack of middleware around a final handler using the onion pattern:

```php
final class HttpPipeline
{
    /** @param HttpMiddlewareInterface[] $middleware */
    public function __construct(private readonly array $middleware) {}

    public function handle(Request $request, HttpHandlerInterface $finalHandler): Response
    {
        $handler = $finalHandler;
        foreach (array_reverse($this->middleware) as $mw) {
            $next = $handler;
            $handler = new class($mw, $next) implements HttpHandlerInterface {
                public function __construct(
                    private readonly HttpMiddlewareInterface $middleware,
                    private readonly HttpHandlerInterface $next,
                ) {}
                public function handle(Request $request): Response {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        return $handler->handle($request);
    }
}
```

### EventBus integration

Event middleware wraps around the sync dispatch in `EventBus::dispatch()`:

```php
public function dispatch(DomainEvent $event): void
{
    $this->eventStore?->append($event);
    $this->eventPipeline->handle($event, new class($this->syncDispatcher) implements EventHandlerInterface {
        public function handle(DomainEvent $event): void {
            $this->syncDispatcher->dispatch($event);
        }
    });
    $this->asyncBus->dispatch($event);
    $this->broadcaster->broadcast($event);
}
```

### JobHandler integration

Job middleware wraps around `Job::handle()` in `JobHandler`:

```php
public function handle(object $message): void
{
    $message->incrementAttempts();
    $this->jobPipeline->handle($message, new class implements JobNextHandlerInterface {
        public function handle(Job $job): void {
            $job->handle();
        }
    });
}
```

### Built-in middleware

| Middleware | Pipeline | Purpose |
|-----------|----------|---------|
| `TenantScopeMiddleware` | event, job | Ensures tenant context is set |
| `LogContextMiddleware` | event, job | Adds structured log context |
| `ThrottleMiddleware` | job | Rate limits job execution |
| `UniqueJobMiddleware` | job | Prevents duplicate dispatch |
| `WithoutOverlappingMiddleware` | job | Mutex lock on job key |
| `TenantResolverMiddleware` | http | Resolves tenant from request |
| `LanguageNegotiatorMiddleware` | http | Sets language from Accept-Language |

### Registration

Two paths, both discovered by the manifest compiler:

1. **Attribute** (auto-discovered): `#[AsMiddleware(pipeline: 'event', priority: 50)]`
2. **Service provider tag** (explicit): `$this->tag(LogContextMiddleware::class, 'middleware.event')`

### Compiled artifact

`storage/framework/middleware.php` — middleware pre-sorted by priority:

```php
return [
    'http' => [
        ['class' => 'Waaseyaa\\...\\TenantResolverMiddleware', 'priority' => 100],
        ['class' => 'Waaseyaa\\...\\LanguageNegotiatorMiddleware', 'priority' => 90],
    ],
    'event' => [
        ['class' => 'Waaseyaa\\...\\TenantScopeMiddleware', 'priority' => 100],
        ['class' => 'Waaseyaa\\...\\LogContextMiddleware', 'priority' => 50],
    ],
    'job' => [
        ['class' => 'Waaseyaa\\...\\TenantScopeMiddleware', 'priority' => 100],
        ['class' => 'Waaseyaa\\...\\ThrottleMiddleware', 'priority' => 90],
    ],
];
```

### File locations

- `packages/foundation/src/Middleware/HttpMiddlewareInterface.php`
- `packages/foundation/src/Middleware/HttpHandlerInterface.php`
- `packages/foundation/src/Middleware/HttpPipeline.php`
- `packages/foundation/src/Middleware/EventMiddlewareInterface.php`
- `packages/foundation/src/Middleware/EventHandlerInterface.php`
- `packages/foundation/src/Middleware/EventPipeline.php`
- `packages/foundation/src/Middleware/JobMiddlewareInterface.php`
- `packages/foundation/src/Middleware/JobNextHandlerInterface.php`
- `packages/foundation/src/Middleware/JobPipeline.php`
- `packages/foundation/src/Middleware/MiddlewarePipelineCompiler.php`
- `packages/foundation/src/Middleware/Builtin/TenantScopeMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/TenantResolverMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/LogContextMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/LanguageNegotiatorMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/ThrottleMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/UniqueJobMiddleware.php`
- `packages/foundation/src/Middleware/Builtin/WithoutOverlappingMiddleware.php`

## 3. Config Caching

### ConfigCacheCompiler

- Reads all config from active storage
- Resolves environment overrides (WAASEYAA_CONFIG__* env vars)
- Validates against config schemas (optional)
- Produces `storage/framework/config.php`

### Cached artifact

Single PHP file returning a flat associative array:

```php
return [
    'system.site' => ['name' => 'My Site', 'slogan' => '...', 'langcode' => 'en'],
    'node.type.article' => ['id' => 'article', 'label' => 'Article', ...],
    'user.settings' => ['register' => 'visitors', ...],
];
```

One `opcache_compile_file()` puts it in shared memory. No YAML parsing, no file I/O.

### CachedConfigFactory (decorator)

Wraps existing `ConfigFactory` with cache awareness:

```php
final class CachedConfigFactory implements ConfigFactoryInterface
{
    private ?array $cache = null;

    public function __construct(
        private readonly ConfigFactoryInterface $inner,
        private readonly string $cachePath,
    ) {}

    public function get(string $name): ConfigInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->loadCache();
        }
        if ($this->cache !== null && isset($this->cache[$name])) {
            return new Config($name, $this->cache[$name]);
        }
        return $this->inner->get($name);
    }

    private function loadCache(): ?array
    {
        $path = $this->cachePath . '/config.php';
        return is_file($path) ? require $path : null;
    }
}
```

### Environment override convention

```
WAASEYAA_CONFIG__SYSTEM_SITE__NAME="Production Site"
```

Double underscore `__` separates config name from key path. The compiler bakes resolved values into the cached array.

### Cache invalidation

1. `waaseyaa optimize:clear` — deletes cache file
2. `ConfigManager::import()` — auto-deletes cache via `ConfigEvent::IMPORT` listener
3. Any config write — `ConfigCacheInvalidator` listener deletes cache on `ConfigEvent` dispatch

### Multi-tenancy seam

Cache path includes optional tenant prefix:

```
storage/framework/config.php                # default
storage/framework/config.tenant-abc.php     # tenant overlay
```

`CachedConfigFactory` checks tenant-specific cache first, then default. Seam is ready; single-tenant initially.

### Dev vs. prod

| Scenario | Behavior |
|----------|----------|
| Dev, no cache | Falls through to FileStorage |
| Dev, cache exists | Uses cache, auto-invalidated on write |
| Prod, no cache | Exception: run `waaseyaa optimize` |
| Prod, cache exists | Single `require`, no file I/O |

### File locations

- `packages/config/src/Cache/ConfigCacheCompiler.php`
- `packages/config/src/Cache/CachedConfigFactory.php`
- `packages/config/src/Listener/ConfigCacheInvalidator.php`

## 4. CLI Orchestration

### Commands

| Command | Purpose |
|---------|---------|
| `waaseyaa optimize` | Run all compilers in order |
| `waaseyaa optimize:clear` | Delete all cached artifacts |
| `waaseyaa optimize:manifest` | Compile package manifest only |
| `waaseyaa optimize:middleware` | Compile middleware pipelines only |
| `waaseyaa optimize:config` | Compile config cache only |

### Compilation order

Manifest first (middleware and config compilers may need it):

```
optimize:manifest → optimize:middleware → optimize:config
```

### Storage directory

```
storage/
└── framework/
    ├── packages.php
    ├── middleware.php
    └── config.php
```

Gitignored. Created automatically by compilers.

### File locations

- `packages/cli/src/Command/Optimize/OptimizeCommand.php`
- `packages/cli/src/Command/Optimize/OptimizeClearCommand.php`
- `packages/cli/src/Command/Optimize/OptimizeManifestCommand.php`
- `packages/cli/src/Command/Optimize/OptimizeMiddlewareCommand.php`
- `packages/cli/src/Command/Optimize/OptimizeConfigCommand.php`

## Pillar Mapping

| Improvement | Primary Pillar | Secondary Pillars |
|------------|---------------|-------------------|
| Package auto-discovery | 1 (Service Providers) | 11 (DX), 3 (Migrations) |
| Middleware pipelines | 2 (Domain Events) | 12 (Queue), 13 (Broadcasting) |
| Config caching | 6 (Config Versioning) | 16 (Caching), 8 (Multi-Tenancy) |

## File Summary

~28 new files across 3 existing packages (foundation, config, cli). No new packages.

### foundation (19 files)

- 5 attributes in `src/Attribute/`
- 2 discovery classes in `src/Discovery/`
- 12 middleware classes in `src/Middleware/` (3 interfaces, 3 handlers, 3 pipelines, 1 compiler, 7 built-in)

### config (3 files)

- `src/Cache/ConfigCacheCompiler.php`
- `src/Cache/CachedConfigFactory.php`
- `src/Listener/ConfigCacheInvalidator.php`

### cli (5 files)

- 5 commands in `src/Command/Optimize/`
