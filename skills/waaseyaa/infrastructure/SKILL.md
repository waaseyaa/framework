---
name: waaseyaa:infrastructure
description: Use when working with service providers, domain events, cache backends, database queries, migrations, package discovery, attribute scanning, or files in packages/foundation/, packages/cache/, packages/database-legacy/, packages/plugin/
---

# Infrastructure Specialist

## Scope

This skill covers the foundational infrastructure layer of Waaseyaa CMS:

- **Domain Events**: `DomainEvent`, `EventBus`, three-channel dispatch (sync/async/broadcast)
- **Service Providers**: `ServiceProvider`, `register()`/`boot()` lifecycle, `ContainerCompiler`
- **Package Discovery**: `PackageManifestCompiler`, attribute scanning, `ProviderDiscovery`
- **Cache System**: `CacheBackendInterface`, `MemoryBackend`, `DatabaseBackend`, tag invalidation
- **Database Abstraction**: `DatabaseInterface`, `PdoDatabase`, query builder (select/insert/update/delete)
- **Schema & Migrations**: `SchemaBuilder`, `TableBuilder`, `Migrator`, `MigrationRepository`
- **Plugin System**: `PluginManagerInterface`, `AttributeDiscovery`, `DefaultPluginManager`
- **Middleware Pipelines**: `HttpPipeline`, `EventPipeline`, `JobPipeline`

Relevant packages: `packages/foundation/`, `packages/cache/`, `packages/database-legacy/`, `packages/plugin/`.

## Key Interfaces

### DatabaseInterface (packages/database-legacy/src/DatabaseInterface.php)

```php
interface DatabaseInterface
{
    public function select(string $table, string $alias = ''): SelectInterface;
    public function insert(string $table): InsertInterface;
    public function update(string $table): UpdateInterface;
    public function delete(string $table): DeleteInterface;
    public function schema(): SchemaInterface;
    public function transaction(string $name = ''): TransactionInterface;
    public function query(string $sql, array $args = []): \Traversable;
}
```

Does NOT have `getPdo()`. Only `PdoDatabase` has that method. Type-hint `PdoDatabase` directly when raw PDO is needed.

### CacheBackendInterface (packages/cache/src/CacheBackendInterface.php)

```php
interface CacheBackendInterface
{
    public const PERMANENT = -1;
    public function get(string $cid): CacheItem|false;
    public function getMultiple(array &$cids): array;
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void;
    public function delete(string $cid): void;
    public function deleteMultiple(array $cids): void;
    public function deleteAll(): void;
    public function invalidate(string $cid): void;
    public function invalidateMultiple(array $cids): void;
    public function invalidateAll(): void;
    public function removeBin(): void;
}
```

### ServiceProviderInterface (packages/foundation/src/ServiceProvider/ServiceProviderInterface.php)

```php
interface ServiceProviderInterface
{
    public function register(): void;   // pure binding, no side effects
    public function boot(): void;       // runs after ALL register() calls
    public function provides(): array;  // deferred provider interfaces
    public function isDeferred(): bool; // true if provides() is non-empty
}
```

### DomainEvent (packages/foundation/src/Event/DomainEvent.php)

```php
abstract class DomainEvent extends Event
{
    public readonly string $eventId;
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    );

    abstract public function getPayload(): array;
}
```

### EventBus (packages/foundation/src/Event/EventBus.php)

```php
final class EventBus
{
    public function __construct(
        EventDispatcherInterface $syncDispatcher,
        MessageBusInterface $asyncBus,
        BroadcasterInterface $broadcaster,
        ?EventStoreInterface $eventStore = null,
        ?EventPipeline $eventPipeline = null,
    );
    public function dispatch(DomainEvent $event): void;
}
```

### Middleware pipeline interfaces

Three typed pipeline variants, all using the onion pattern:

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
    public function process(Job $job, JobHandlerInterface $next): void;
}
interface JobHandlerInterface {
    public function handle(Job $job): void;
}
```

### SelectInterface query builder (packages/database-legacy/src/SelectInterface.php)

```php
interface SelectInterface
{
    public function fields(string $tableAlias, array $fields = []): static;
    public function addField(string $tableAlias, string $field, string $alias = ''): static;
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function isNull(string $field): static;
    public function isNotNull(string $field): static;
    public function orderBy(string $field, string $direction = 'ASC'): static;
    public function range(int $offset, int $limit): static;
    public function join(string $table, string $alias, string $condition): static;
    public function leftJoin(string $table, string $alias, string $condition): static;
    public function countQuery(): static;
    public function execute(): \Traversable;
}
```

### PackageManifestCompiler (packages/foundation/src/Discovery/PackageManifestCompiler.php)

```php
final class PackageManifestCompiler
{
    public function __construct(string $basePath, string $storagePath);
    public function compile(): PackageManifest;
    public function compileAndCache(): PackageManifest;
    public function load(): PackageManifest;
}
```

### Migration (packages/foundation/src/Migration/Migration.php)

```php
abstract class Migration
{
    public array $after = [];
    abstract public function up(SchemaBuilder $schema): void;
    public function down(SchemaBuilder $schema): void {}
}
```

## Architecture

### Event dispatch flow

```
EventBus::dispatch(DomainEvent)
    1. $eventStore?->append($event)
    2. If EventPipeline exists: pipeline wraps sync dispatch
       Else: direct sync dispatch via Symfony EventDispatcher
    3. $asyncBus->dispatch($event) -- Symfony Messenger
    4. $broadcaster->broadcast($event) -- SSE
```

### Service provider lifecycle

```
ContainerCompiler::compile(providers, container)
    Phase 1: foreach provider -> register()
        - Collects bindings via getBindings()
        - Collects tags via getTags()
        - Wires into Symfony ContainerBuilder
    Phase 2: foreach provider -> boot()
        - All bindings available from all packages
```

### Package manifest compilation

```
PackageManifestCompiler::compile()
    1. Read vendor/composer/installed.json -> extract extra.waaseyaa keys
    2. Read vendor/composer/autoload_classmap.php -> filter Waaseyaa\\ classes
    3. Reflect each class -> check for AsFieldType, Listener, AsMiddleware, PolicyAttribute
    4. Sort middleware + listeners by priority (descending)
    5. Return PackageManifest

PackageManifestCompiler::compileAndCache()
    1. compile()
    2. Write to storage/framework/packages.php (atomic: tmp file + rename)
```

### Cache factory resolution

```
CacheFactory::get(bin)
    1. Check CacheConfiguration::getFactoryForBin(bin)
       - bin-specific factory? -> call it
       - default factory? -> call it
    2. Fall back to CacheConfiguration::getBackendForBin(bin)
       - bin-specific class? -> new $class()
       - default class -> new $defaultBackend()
```

### Migration execution

```
Migrator::run(migrations)
    1. Topologically sort packages by Migration::$after dependencies
    2. Get next batch number
    3. For each migration in order:
       - Skip if already run (MigrationRepository::hasRun)
       - Create SchemaBuilder -> call migration->up($schema)
       - Record in waaseyaa_migrations table
    4. Return MigrationResult with count + names
```

## Common Mistakes

### DatabaseInterface vs PdoDatabase

`DatabaseInterface` does NOT have `getPdo()`. This is the most common mistake. If you need raw PDO access, type-hint `PdoDatabase` directly. Prefer using the query builder over raw PDO.

```php
// WRONG
public function __construct(private DatabaseInterface $db) {
    $pdo = $db->getPdo();  // Method does not exist on interface
}

// CORRECT
public function __construct(private PdoDatabase $db) {
    $pdo = $db->getPdo();
}

// PREFERRED -- use query builder instead of raw PDO
$db->select('users', 'u')->condition('u.uid', $uid)->execute();
```

### LIKE wildcard escaping

`PdoSelect` appends `ESCAPE '\'` for LIKE/NOT LIKE operators automatically. You must escape `%` and `_` in user input yourself:

```php
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $userInput);
$query->condition('title', '%' . $escaped . '%', 'LIKE');
```

### Atomic file writes

Cache files and compiled artifacts must use write-to-temp-then-rename:

```php
$tmpPath = $cachePath . '.tmp.' . getmypid();
file_put_contents($tmpPath, $content);
rename($tmpPath, $cachePath);
```

Never write directly to the target path. Partial writes cause corrupt cache.

### JSON symmetry

Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. Asymmetric usage causes silent `null` on corrupt data.

### Layer discipline for imports

Foundation (layer 1) must never import from higher layers. When cross-layer attribute scanning is needed, use string constants:

```php
// WRONG -- imports from layer 3
use Waaseyaa\Access\Gate\PolicyAttribute;

// CORRECT -- string constant, no import
private const POLICY_ATTRIBUTE = 'Waaseyaa\\Access\\Gate\\PolicyAttribute';
```

`ReflectionClass::getAttributes()` accepts string class names.

### Best-effort side effects in event listeners

Event listeners for non-critical operations must wrap in try-catch:

```php
public function onPostSave(EntityEvent $event): void
{
    try {
        $this->cacheTagsInvalidator->invalidateTags([...]);
    } catch (\Throwable $e) {
        error_log('Cache invalidation failed: ' . $e->getMessage());
    }
}
```

The project does not use `psr/log`. Use `error_log()` for logging.

### Final classes cannot be mocked

PHPUnit `createMock()` fails on `final class`. Use real instances with temp directories:

```php
$dir = sys_get_temp_dir() . '/waaseyaa_test_' . uniqid();
$db = PdoDatabase::createSqlite();  // in-memory
$cache = new MemoryBackend();
```

### Backward-compatible cache evolution

When adding new properties to cached manifests, make them optional:

```php
// In PackageManifest::fromArray()
permissions: $data['permissions'] ?? [],
policies: $data['policies'] ?? [],
```

Old cache files missing new keys will not break.

### PDO fetch mode

`PdoDatabase` sets `FETCH_ASSOC` to avoid duplicate numeric-indexed columns. Do not override this.

### register() vs boot() in ServiceProvider

`register()` is pure binding only. Never resolve other services or cause side effects in `register()`. Use `boot()` for anything that depends on other packages being registered.

## Testing Patterns

### In-memory database

```php
$db = PdoDatabase::createSqlite();  // SQLite :memory:
```

### In-memory cache

```php
$cache = new MemoryBackend();
$cache->set('key', $data, tags: ['entity:node']);
$cache->invalidateByTags(['entity:node']);
```

### Cache factory with configuration

```php
$config = new CacheConfiguration(
    defaultBackend: MemoryBackend::class,
    binFactories: [
        'cache_entity' => fn() => new DatabaseBackend($pdo, 'cache_entity'),
    ],
);
$factory = new CacheFactory($config);
```

### Testing corrupt cache recovery

```php
// Write corrupt cache file
file_put_contents($cachePath, '<?php throw new \\RuntimeException("corrupt");');
$manifest = $compiler->load();  // should recompile instead of crashing

// Wrong return type
file_put_contents($cachePath, '<?php return "not an array";');
$manifest = $compiler->load();  // should recompile
```

### Testing migrations

```php
$connection = DriverManager::getConnection(['url' => 'sqlite:///:memory:']);
$repository = new MigrationRepository($connection);
$repository->createTable();
$migrator = new Migrator($connection, $repository);

$result = $migrator->run([
    'waaseyaa/node' => [
        '001_create_nodes' => new CreateNodesTable(),
    ],
]);
assert($result->count === 1);
```

### Testing event bus

```php
$dispatcher = new EventDispatcher();
$asyncBus = /* Symfony Messenger test bus */;
$broadcaster = new class implements BroadcasterInterface {
    public function broadcast(DomainEvent $event): void {}
};
$bus = new EventBus($dispatcher, $asyncBus, $broadcaster);
$bus->dispatch($event);
```

### Testing query builder

```php
$db = PdoDatabase::createSqlite();
$db->schema()->createTable('users', [
    'fields' => [
        'uid' => ['type' => 'serial'],
        'name' => ['type' => 'varchar', 'not null' => true],
    ],
    'primary key' => ['uid'],
]);

$db->insert('users')->values(['name' => 'admin'])->execute();
$result = $db->select('users')->condition('name', 'admin')->execute();
```

## Related Specs

- `docs/specs/infrastructure.md` -- full infrastructure specification (domain events, cache, database, migrations)
- `docs/specs/package-discovery.md` -- full package discovery specification (service providers, manifest compilation, attribute scanning, plugin system)
- `docs/plans/2026-03-01-laravel-integration-layer-design.md` -- design document for package auto-discovery, middleware pipelines, config caching
- `docs/plans/2026-02-28-aurora-architecture-v2-design.md` -- full architecture v2 design with all 17 pillars
