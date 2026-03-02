# Package Discovery

Specification for how Waaseyaa packages are discovered, registered, booted, and compiled into optimized artifacts.

## Overview

Waaseyaa uses a two-phase discovery system:

1. **Coarse-grained**: Composer `extra.waaseyaa` in each package's `composer.json` declares providers, commands, routes, migrations, and permissions.
2. **Fine-grained**: PHP 8 attributes on classes (`#[AsFieldType]`, `#[Listener]`, `#[AsMiddleware]`, `PolicyAttribute`) are scanned at compile time from Composer's autoload classmap.

Both are unified by `PackageManifestCompiler` into a single cached artifact at `storage/framework/packages.php`.

## ServiceProvider Lifecycle

### Interface

File: `packages/foundation/src/ServiceProvider/ServiceProviderInterface.php`

```php
namespace Waaseyaa\Foundation\ServiceProvider;

interface ServiceProviderInterface
{
    public function register(): void;
    public function boot(): void;
    public function provides(): array;
    public function isDeferred(): bool;
}
```

### Abstract base class

File: `packages/foundation/src/ServiceProvider/ServiceProvider.php`

```php
abstract class ServiceProvider implements ServiceProviderInterface
{
    abstract public function register(): void;
    public function boot(): void {}

    public function provides(): array { return []; }
    public function isDeferred(): bool { return $this->provides() !== []; }

    // Binding helpers
    protected function singleton(string $abstract, string|callable $concrete): void;
    protected function bind(string $abstract, string|callable $concrete): void;
    protected function tag(string $abstract, string $tag): void;

    // Introspection (used by ContainerCompiler)
    public function getBindings(): array;   // ['abstract' => ['concrete' => ..., 'shared' => bool]]
    public function getTags(): array;       // ['tag' => ['service1', 'service2']]
}
```

### Two-phase lifecycle

**Phase 1 -- register()**: Pure binding. No side effects. No resolving other services. All packages call `register()` before any `boot()` runs.

```php
public function register(): void
{
    $this->singleton(EntityStorageInterface::class, SqlEntityStorage::class);
    $this->singleton(EntityTypeManagerInterface::class, EntityTypeManager::class);
    $this->tag(SqlEntityStorage::class, 'storage');
}
```

**Phase 2 -- boot()**: All bindings are available from all packages. Safe to resolve cross-package dependencies, register event listeners, configure services.

```php
public function boot(): void
{
    // All packages are registered; cross-package resolution is safe here
}
```

### Deferred providers

A provider is deferred if `provides()` returns a non-empty array. Deferred providers are only loaded when one of their declared interfaces is first resolved. This keeps cold boot fast.

```php
public function provides(): array
{
    return [AiEmbedderInterface::class, AiCompletionInterface::class];
}
```

### ContainerCompiler

File: `packages/foundation/src/ServiceProvider/ContainerCompiler.php`

Orchestrates the two-phase lifecycle and wires bindings into Symfony's `ContainerBuilder`:

```php
final class ContainerCompiler
{
    public function compile(array $providers, ContainerBuilder $container): void
    {
        // Phase 1: register all bindings
        foreach ($providers as $provider) {
            $provider->register();
            // Map getBindings() -> ContainerBuilder definitions
            // Map getTags() -> ContainerBuilder tags
        }

        // Phase 2: boot all providers
        foreach ($providers as $provider) {
            $provider->boot();
        }
    }
}
```

Binding properties:
- `shared: true` (from `singleton()`) -> `Definition::setShared(true)`
- `shared: false` (from `bind()`) -> `Definition::setShared(false)`
- Callable concrete values -> `Definition::setFactory($concrete)`
- All definitions are set to `public: true`

## Composer Manifest

### Package composer.json format

Each package declares its registration metadata in `extra.waaseyaa`:

```json
{
    "name": "waaseyaa/node",
    "extra": {
        "waaseyaa": {
            "providers": ["Waaseyaa\\Node\\NodeServiceProvider"],
            "commands": ["Waaseyaa\\Node\\Command\\NodeCreateCommand"],
            "routes": ["Waaseyaa\\Node\\NodeRouteProvider"],
            "migrations": "migrations/",
            "config": "config/",
            "permissions": {
                "create node content": {
                    "title": "Create node content",
                    "description": "Allows creating new nodes"
                }
            }
        }
    }
}
```

Supported keys:
| Key | Type | Purpose |
|-----|------|---------|
| `providers` | `string[]` | ServiceProvider FQCNs |
| `commands` | `string[]` | CLI command FQCNs |
| `routes` | `string[]` | Route provider FQCNs |
| `migrations` | `string` | Path to migrations directory (relative to package) |
| `config` | `string` | Path to default config directory |
| `permissions` | `object` | Permission definitions with title and optional description |

### Root composer.json conventions

The monorepo root uses `@dev` constraints for all `waaseyaa/*` packages and path repository references:

```json
{
    "repositories": [
        { "type": "path", "url": "packages/*" }
    ],
    "require": {
        "waaseyaa/foundation": "@dev",
        "waaseyaa/entity": "@dev"
    }
}
```

### ProviderDiscovery

File: `packages/foundation/src/ServiceProvider/ProviderDiscovery.php`

Reads `vendor/composer/installed.json` and collects all `extra.waaseyaa.providers` entries:

```php
final class ProviderDiscovery
{
    public function discoverFromArray(array $installed): array;
    public function discoverFromVendor(string $vendorPath): array;
}
```

Returns `list<class-string<ServiceProviderInterface>>`.

## PackageManifest

### PackageManifest DTO

File: `packages/foundation/src/Discovery/PackageManifest.php`

```php
final class PackageManifest
{
    public function __construct(
        public readonly array $providers = [],      // string[]
        public readonly array $commands = [],       // string[]
        public readonly array $routes = [],         // string[]
        public readonly array $migrations = [],     // [packageName => path]
        public readonly array $fieldTypes = [],     // [id => className]
        public readonly array $listeners = [],      // [eventClass => [{class, priority}]]
        public readonly array $middleware = [],      // [pipeline => [{class, priority}]]
        public readonly array $permissions = [],    // [id => {title, description?}]
        public readonly array $policies = [],       // [entityType => className]
    ) {}

    public static function fromArray(array $data): self;
    public function toArray(): array;
}
```

When deserializing from cache, `permissions` and `policies` are optional keys (`$data['permissions'] ?? []`). This supports backward-compatible cache evolution -- old cache files missing new keys will not break.

Required cache keys: `providers`, `commands`, `routes`, `migrations`, `field_types`, `listeners`, `middleware`.

### PackageManifestCompiler

File: `packages/foundation/src/Discovery/PackageManifestCompiler.php`

```php
final class PackageManifestCompiler
{
    public function __construct(
        private readonly string $basePath,      // project root
        private readonly string $storagePath,   // storage/ directory
    );

    public function compile(): PackageManifest;
    public function compileAndCache(): PackageManifest;
    public function load(): PackageManifest;           // cache-first, compile on miss
}
```

**Compile pipeline:**

1. Read `vendor/composer/installed.json` for coarse-grained manifest data (providers, commands, routes, migrations, permissions)
2. Read `vendor/composer/autoload_classmap.php` for all Waaseyaa-namespaced classes
3. Reflect each class, checking for discovery attributes
4. Sort middleware and listeners by priority (descending -- highest priority first)
5. Produce `PackageManifest` instance

**Attribute scanning details:**

The compiler scans classes that start with `Waaseyaa\` from the classmap. For each concrete (non-abstract, non-interface, non-trait) class:

| Attribute | What it discovers | How |
|-----------|------------------|-----|
| `AsFieldType` | Field type plugins | `$instance->id` => class name |
| `Listener` | Event listeners | Reads `__invoke()` parameter type to determine event class; `$instance->priority` for ordering |
| `AsMiddleware` | Middleware | `$instance->pipeline` (http/event/job) + `$instance->priority` |
| `AsEntityType` | Entity types | Currently tracked via providers (no-op in compiler) |
| `PolicyAttribute` | Access policies | `$instance->entityType` => class name |

**Cache output**: `storage/framework/packages.php`

```php
<?php return [
    'providers' => ['Waaseyaa\\Node\\NodeServiceProvider', ...],
    'commands' => [...],
    'routes' => [...],
    'migrations' => ['waaseyaa/node' => '/path/to/migrations/', ...],
    'field_types' => ['text' => 'Waaseyaa\\Field\\Plugin\\TextField', ...],
    'listeners' => [
        'Waaseyaa\\Entity\\Event\\EntitySaved' => [
            ['class' => '...', 'priority' => 100],
            ['class' => '...', 'priority' => 0],
        ],
    ],
    'middleware' => [
        'http' => [['class' => '...', 'priority' => 100], ...],
        'event' => [...],
        'job' => [...],
    ],
    'permissions' => [...],
    'policies' => [...],
];
```

**Dev vs. prod behavior:**

| Scenario | Behavior |
|----------|----------|
| Dev, no cache | `load()` compiles and writes cache |
| Dev, cache exists | `load()` reads from cache; corrupt cache triggers recompile |
| Prod | `waaseyaa optimize` pre-compiles; `load()` reads cache only |

**Atomic file write**: Cache is written via temp file + `rename()` to prevent serving partial writes. See `compileAndCache()`.

## Three Compilers

The system has three independent compilers, orchestrated by `waaseyaa optimize`:

| Compiler | Artifact | File |
|----------|----------|------|
| `PackageManifestCompiler` | `storage/framework/packages.php` | `packages/foundation/src/Discovery/PackageManifestCompiler.php` |
| `MiddlewarePipelineCompiler` | `storage/framework/middleware.php` | `packages/foundation/src/Middleware/MiddlewarePipelineCompiler.php` |
| `ConfigCacheCompiler` | `storage/framework/config.php` | `packages/config/src/Cache/ConfigCacheCompiler.php` |

**Compilation order**: Manifest first (middleware and config compilers may need it):

```
optimize:manifest -> optimize:middleware -> optimize:config
```

### CLI commands

| Command | Purpose |
|---------|---------|
| `waaseyaa optimize` | Run all compilers in order |
| `waaseyaa optimize:clear` | Delete all cached artifacts |
| `waaseyaa optimize:manifest` | Compile package manifest only |
| `waaseyaa optimize:middleware` | Compile middleware pipelines only |
| `waaseyaa optimize:config` | Compile config cache only |

## Attribute Discovery

### Discovery attributes defined in Foundation

All discovery attributes live in `packages/foundation/src/Attribute/` and `packages/foundation/src/Event/Attribute/`:

```php
// packages/foundation/src/Attribute/AsFieldType.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsFieldType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}

// packages/foundation/src/Attribute/AsEntityType.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsEntityType
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {}
}

// packages/foundation/src/Attribute/AsMiddleware.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsMiddleware
{
    public function __construct(
        public readonly string $pipeline,   // 'http', 'event', 'job'
        public readonly int $priority = 0,
    ) {}
}

// packages/foundation/src/Event/Attribute/Listener.php
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Listener
{
    public function __construct(
        public readonly int $priority = 0,
    ) {}
}
```

### Plugin system attribute discovery

The `packages/plugin/` package provides a separate attribute-based discovery system for extensible plugin types:

```php
// packages/plugin/src/Attribute/WaaseyaaPlugin.php
#[\Attribute(\Attribute::TARGET_CLASS)]
class WaaseyaaPlugin
{
    public function __construct(
        public readonly string $id,
        public readonly string $label = '',
        public readonly string $description = '',
        public readonly string $package = '',
    ) {}
}
```

`AttributeDiscovery` scans directories for classes with a given attribute (configurable per plugin type), extracts `PluginDefinition` objects, and caches them via `DefaultPluginManager`.

```php
// Discovery setup
$discovery = new AttributeDiscovery(
    directories: ['/path/to/packages/field/src/Plugin'],
    attributeClass: AsFieldType::class,
);
$manager = new DefaultPluginManager($discovery, cache: $cacheBackend);
$definitions = $manager->getDefinitions();
$instance = $manager->createInstance('text');
```

### Cross-layer attribute scanning

File: `packages/foundation/src/Discovery/PackageManifestCompiler.php`

Foundation (layer 1) must never import from higher layers. When the compiler needs to scan for attributes defined in higher-layer packages (e.g., `PolicyAttribute` from the Access package), it uses string constants instead of `::class` references:

```php
private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';

// In scanning code:
foreach ($ref->getAttributes(self::POLICY_ATTRIBUTE) as $attr) {
    $instance = $attr->newInstance();
    $policies[$instance->entityType] = $class;
}
```

`ReflectionClass::getAttributes()` accepts string class names, so no import is needed. This preserves strict layer discipline.

## Layer Discipline

### Import rules

Foundation (layer 1) must never import from higher layers. This is enforced by convention and code review:

- Layer 1 (Foundation, Cache, Database) -> imports only PHP core and Symfony components
- Layer 2 (Core Data: Entity, Config, Plugin) -> may import from layer 1
- Layer 3 (Services: Access, Field, I18n) -> may import from layers 1-2
- Higher layers follow the same upward-only pattern

### Avoiding circular package dependencies

Key ownership boundaries:
- `packages/access/` owns `AccountInterface`
- `packages/user/` owns `User`, `AnonymousUser`
- `packages/access/` must NOT depend on `packages/user/`

Middleware needing an account type-hints `AccountInterface`, never concrete `AnonymousUser`.

### String constants for cross-layer attribute names

When layer 1 code needs to reference attribute classes from higher layers:

```php
// WRONG -- creates import dependency on higher layer
use Waaseyaa\Access\Gate\PolicyAttribute;

// CORRECT -- string constant, no import
private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
```

## Plugin System

### Core interfaces

File: `packages/plugin/src/PluginManagerInterface.php`

```php
interface PluginManagerInterface
{
    public function getDefinition(string $pluginId): PluginDefinition;
    public function getDefinitions(): array;
    public function hasDefinition(string $pluginId): bool;
    public function createInstance(string $pluginId, array $configuration = []): PluginInspectionInterface;
}
```

### PluginDefinition

File: `packages/plugin/src/Definition/PluginDefinition.php`

```php
final readonly class PluginDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public string $class,
        public string $description = '',
        public string $package = '',
        public array $metadata = [],
    ) {}
}
```

### DefaultPluginManager

File: `packages/plugin/src/DefaultPluginManager.php`

Caches plugin definitions via `CacheBackendInterface`. On cache miss, delegates to `PluginDiscoveryInterface` to scan. Uses `ContainerFactory` to instantiate plugin instances.

```php
class DefaultPluginManager implements PluginManagerInterface
{
    public function __construct(
        PluginDiscoveryInterface $discovery,
        ?CacheBackendInterface $cache = null,
        string $cacheKey = 'plugin_definitions',
        ?PluginFactoryInterface $factory = null,
    );

    public function clearCachedDefinitions(): void;
}
```

### Plugin base class

File: `packages/plugin/src/PluginBase.php`

```php
abstract class PluginBase implements PluginInspectionInterface
{
    public function __construct(
        protected readonly string $pluginId,
        protected readonly PluginDefinition $pluginDefinition,
        protected readonly array $configuration = [],
    );
}
```

Plugin classes extend `PluginBase` and receive their ID, definition, and configuration at construction time via `ContainerFactory`.

## File Reference

### packages/foundation/src/ServiceProvider/

```
ServiceProviderInterface.php    -- register/boot/provides/isDeferred contract
ServiceProvider.php             -- abstract base with singleton/bind/tag + getBindings/getTags
ProviderDiscovery.php           -- reads extra.waaseyaa.providers from installed.json
ContainerCompiler.php           -- two-phase compile into Symfony ContainerBuilder
```

### packages/foundation/src/Discovery/

```
PackageManifest.php             -- typed DTO: fromArray/toArray with backward-compatible optional keys
PackageManifestCompiler.php     -- compile from composer metadata + attributes; atomic cache write
```

### packages/foundation/src/Attribute/

```
AsFieldType.php                 -- #[AsFieldType(id, label)]
AsEntityType.php                -- #[AsEntityType(id, label)]
AsMiddleware.php                -- #[AsMiddleware(pipeline, priority)]
```

### packages/foundation/src/Event/Attribute/

```
Listener.php                    -- #[Listener(priority)]
Async.php                       -- #[Async] marks method for async dispatch
Broadcast.php                   -- #[Broadcast(channel)] marks for SSE broadcast
```

### packages/plugin/src/

```
PluginManagerInterface.php      -- getDefinition/getDefinitions/hasDefinition/createInstance
DefaultPluginManager.php        -- cached discovery + factory instantiation
PluginInspectionInterface.php   -- getPluginId/getPluginDefinition
PluginBase.php                  -- abstract base (pluginId, definition, configuration)
Attribute/
    WaaseyaaPlugin.php          -- #[WaaseyaaPlugin(id, label, description, package)]
Discovery/
    PluginDiscoveryInterface.php -- getDefinitions(): array<string, PluginDefinition>
    AttributeDiscovery.php       -- recursive directory scan + ReflectionClass attribute extraction
Definition/
    PluginDefinition.php         -- readonly DTO (id, label, class, description, package, metadata)
Factory/
    PluginFactoryInterface.php   -- createInstance(pluginId, configuration)
    ContainerFactory.php         -- instantiates via new $class($pluginId, $definition, $configuration)
```
