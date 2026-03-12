# Infrastructure

Specification for the foundational infrastructure layer of Waaseyaa CMS: domain events, cache system, database abstraction, query builder, and migration system.

## Packages

| Package | Namespace | Layer | Purpose |
|---------|-----------|-------|---------|
| `packages/foundation/` | `Waaseyaa\Foundation\` | 0 (Foundation) | DomainEvent, ServiceProvider, middleware interfaces, migration system, attribute discovery |
| `packages/cache/` | `Waaseyaa\Cache\` | 0 (Foundation) | CacheBackendInterface, MemoryBackend, DatabaseBackend, NullBackend, tag invalidation |
| `packages/database-legacy/` | `Waaseyaa\Database\` | 0 (Foundation) | DatabaseInterface, PdoDatabase, query builder (select/insert/update/delete), schema, transactions |
| `packages/plugin/` | `Waaseyaa\Plugin\` | 0 (Foundation) | PluginManager, attribute-based plugin discovery, plugin factory |
| `packages/mail/` | `Waaseyaa\Mail\` | 0 (Foundation) | Transport-agnostic mail API with Twig templating, pluggable transports (ArrayTransport for tests, LocalTransport for file-based delivery) |

## Domain Events

### DomainEvent base class

File: `packages/foundation/src/Event/DomainEvent.php`

```php
namespace Waaseyaa\Foundation\Event;

abstract class DomainEvent extends Event
{
    public readonly string $eventId;          // UUIDv7, auto-generated
    public readonly \DateTimeImmutable $occurredAt;  // auto-set to now

    public function __construct(
        public readonly string $aggregateType,   // e.g., 'node', 'user', 'config'
        public readonly string $aggregateId,     // entity ID or config name
        public readonly ?string $tenantId = null,
        public readonly ?string $actorId = null,
    );

    abstract public function getPayload(): array;
}
```

All properties are `public readonly`. There are no getter methods.

### Three-channel dispatch

EventBus dispatches every DomainEvent through three channels in order:

```
DomainEvent dispatched
    |
    1. EventStore::append()        -- optional, for event sourcing
    |
    2. Sync listeners              -- Symfony EventDispatcher, wrapped by EventPipeline middleware
    |                                 Cache invalidation, access index updates, validation side-effects
    |                                 Must complete before response.
    |
    3. Async listeners             -- Symfony Messenger ($asyncBus->dispatch())
    |                                 AI re-embedding, search re-indexing, webhook delivery
    |
    4. Broadcast listeners         -- BroadcasterInterface ($broadcaster->broadcast())
                                     Admin SPA real-time updates via SSE
```

File: `packages/foundation/src/Event/EventBus.php`

```php
namespace Waaseyaa\Foundation\Event;

final class EventBus
{
    public function __construct(
        private readonly EventDispatcherInterface $syncDispatcher,
        private readonly MessageBusInterface $asyncBus,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?EventStoreInterface $eventStore = null,
        private readonly ?EventPipeline $eventPipeline = null,
    ) {}

    public function dispatch(DomainEvent $event): void;
}
```

When `$eventPipeline` is non-null, sync dispatch is wrapped in the event middleware pipeline. When null, sync dispatch calls the dispatcher directly.

### Event attributes

| Attribute | Target | File | Purpose |
|-----------|--------|------|---------|
| `#[Listener(priority: 0)]` | CLASS | `packages/foundation/src/Event/Attribute/Listener.php` | Mark class as event listener; event type inferred from `__invoke()` parameter |
| `#[Async]` | METHOD | `packages/foundation/src/Event/Attribute/Async.php` | Route listener through Messenger async bus |
| `#[Broadcast(channel: '...')]` | CLASS | `packages/foundation/src/Event/Attribute/Broadcast.php` | Route listener through SSE broadcaster |

### Supporting interfaces

| Interface | File | Method |
|-----------|------|--------|
| `EventStoreInterface` | `packages/foundation/src/Event/EventStoreInterface.php` | `append(DomainEvent $event): void` |
| `BroadcasterInterface` | `packages/foundation/src/Event/BroadcasterInterface.php` | `broadcast(DomainEvent $event): void` |

### Best-effort side effects

Event listeners for non-critical operations (broadcasting, logging, cache invalidation) must wrap in try-catch and log via `error_log()` to avoid crashing the primary request. The project does not use `psr/log`.

## Cache System

### CacheBackendInterface

File: `packages/cache/src/CacheBackendInterface.php`

```php
namespace Waaseyaa\Cache;

interface CacheBackendInterface
{
    public const PERMANENT = -1;

    public function get(string $cid): CacheItem|false;
    public function getMultiple(array &$cids): array;   // pass-by-reference; $cids narrowed to misses
    public function set(string $cid, mixed $data, int $expire = self::PERMANENT, array $tags = []): void;
    public function delete(string $cid): void;
    public function deleteMultiple(array $cids): void;
    public function deleteAll(): void;
    public function invalidate(string $cid): void;       // marks invalid but does not delete
    public function invalidateMultiple(array $cids): void;
    public function invalidateAll(): void;
    public function removeBin(): void;                   // drops the entire bin
}
```

### CacheItem

File: `packages/cache/src/CacheItem.php`

```php
final readonly class CacheItem
{
    public function __construct(
        public string $cid,
        public mixed $data,
        public int $created,
        public int $expire = CacheBackendInterface::PERMANENT,
        public array $tags = [],
        public bool $valid = true,
    ) {}
}
```

### TagAwareCacheInterface

File: `packages/cache/src/TagAwareCacheInterface.php`

Extends `CacheBackendInterface` with:

```php
interface TagAwareCacheInterface extends CacheBackendInterface
{
    /** @param string[] $tags */
    public function invalidateByTags(array $tags): void;
}
```

### Backend implementations

| Backend | File | Tag-aware | Notes |
|---------|------|-----------|-------|
| `MemoryBackend` | `packages/cache/src/Backend/MemoryBackend.php` | Yes | In-memory array; use for tests. Implements `TagAwareCacheInterface`. |
| `DatabaseBackend` | `packages/cache/src/Backend/DatabaseBackend.php` | Yes | PDO-backed; auto-creates table on first use. `INSERT OR REPLACE`. Tags stored comma-separated. |
| `NullBackend` | `packages/cache/src/Backend/NullBackend.php` | No | All gets return false; all writes are no-ops. Use for disabled bins. |

### CacheFactory and CacheConfiguration

File: `packages/cache/src/CacheFactory.php`, `packages/cache/src/CacheConfiguration.php`

```php
interface CacheFactoryInterface
{
    public function get(string $bin): CacheBackendInterface;
}
```

`CacheFactory` creates backends per bin. `CacheConfiguration` maps bin names to backend classes or factory callables. Factory callables take precedence over class names for backends that need constructor arguments (e.g., DatabaseBackend needs a `\PDO`).

```php
$config = new CacheConfiguration(
    defaultBackend: MemoryBackend::class,
    binFactories: [
        'cache_entity' => fn() => new DatabaseBackend($pdo, 'cache_entity'),
    ],
);
$factory = new CacheFactory($config);
$cache = $factory->get('cache_entity');  // returns DatabaseBackend
$cache = $factory->get('cache_other');   // returns MemoryBackend
```

### Tag invalidation

File: `packages/cache/src/CacheTagsInvalidator.php`

`CacheTagsInvalidator` holds references to all registered cache bins and delegates `invalidateTags()` to those that implement `TagAwareCacheInterface`.

### Cache event listeners

| Listener | File | Listens to | Tags invalidated |
|----------|------|-----------|------------------|
| `EntityCacheInvalidator` | `packages/cache/src/Listener/EntityCacheInvalidator.php` | `EntityEvent` (post-save, post-delete) | `entity:{type}`, `entity:{type}:{id}` |
| `ConfigCacheInvalidator` | `packages/cache/src/Listener/ConfigCacheInvalidator.php` | `ConfigEvent` (post-save, post-delete) | `config`, `config:{name}` |
| `TranslationCacheInvalidator` | `packages/cache/src/Listener/TranslationCacheInvalidator.php` | Translation events | Translation-specific tags |

### Cache initialization timing in HttpKernel

Cache setup follows a two-stage lifecycle:

1. **Boot phase** (`AbstractKernel::boot()`): Core services are initialized (database, config, entity type manager, dispatcher, access handler). No cache bins or cache-related objects are created yet.

2. **Handle phase** (`HttpKernel::handle()`, after `boot()` returns):
   - `CacheConfigResolver` is instantiated with the loaded config array.
   - `CacheConfiguration` is created and bin factories are registered for `render`, `discovery`, and `mcp_read` bins (all database-backed).
   - `CacheFactory` creates the three cache backends.
   - `RenderCache` wraps the render backend; `discoveryCache` and `mcpReadCache` are stored as `CacheBackendInterface` references.
   - `EventListenerRegistrar` registers invalidation listeners in this order:
     1. `registerRenderCacheListeners(renderCache)`
     2. `registerDiscoveryCacheListeners(discoveryCache)`
     3. `registerMcpReadCacheListeners(mcpReadCache)`
   - All three listener methods subscribe to `EntityEvents::POST_SAVE->value` and `EntityEvents::POST_DELETE->value` (the string-backed enum values from `Waaseyaa\Entity\Event\EntityEvents`, e.g. `'waaseyaa.entity.post_save'`).

This means `CacheConfigResolver` is **not** available during boot — it requires the config array which is populated by boot, and is only needed by the SSR page handler created later in `handle()`.

### Atomic file writes pattern

Cache files and compiled artifacts must use write-to-temp-then-rename to prevent serving partial writes:

```php
$tmpPath = $cachePath . '.tmp.' . getmypid();
file_put_contents($tmpPath, $content);
rename($tmpPath, $cachePath);
```

This pattern is used in `PackageManifestCompiler::compileAndCache()` and must be used anywhere the cache system writes PHP files to disk.

## Database Layer

### DatabaseInterface

File: `packages/database-legacy/src/DatabaseInterface.php`

```php
namespace Waaseyaa\Database;

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

**CRITICAL**: `DatabaseInterface` does NOT have `getPdo()`. If raw PDO is needed, type-hint `PdoDatabase` directly. Prefer using the query builder (`select()`, `insert()`, `update()`, `delete()`) over raw PDO.

### PdoDatabase

File: `packages/database-legacy/src/PdoDatabase.php`

```php
final class PdoDatabase implements DatabaseInterface
{
    public function __construct(private readonly \PDO $pdo);
    public static function createSqlite(string $path = ':memory:'): self;
    public function getPdo(): \PDO;   // ONLY on PdoDatabase, NOT on DatabaseInterface
}
```

PdoDatabase sets two PDO attributes on construction:
- `ATTR_ERRMODE` = `ERRMODE_EXCEPTION`
- `ATTR_DEFAULT_FETCH_MODE` = `FETCH_ASSOC` (avoids duplicate numeric-indexed columns)

### TransactionInterface

File: `packages/database-legacy/src/TransactionInterface.php`

```php
interface TransactionInterface
{
    public function commit(): void;
    public function rollBack(): void;
}
```

`PdoTransaction` begins the transaction in its constructor. Calling `commit()` or `rollBack()` after the transaction is no longer active throws `\RuntimeException`.

## Query Builder

### SelectInterface

File: `packages/database-legacy/src/SelectInterface.php`

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
    public function countQuery(): static;  // clones + wraps in COUNT(*)
    public function execute(): \Traversable;
}
```

### PdoSelect condition operators

File: `packages/database-legacy/src/Query/PdoSelect.php`

Supported operators in `condition()`:
- `=`, `!=`, `<`, `>`, `<=`, `>=` -- standard comparison, single `?` placeholder
- `IN`, `NOT IN` -- value must be array, generates `(?, ?, ...)` placeholders
- `BETWEEN` -- value must be array of exactly 2
- `LIKE`, `NOT LIKE` -- appends `ESCAPE '\'` automatically
- `IS NULL`, `IS NOT NULL` -- use `isNull()`/`isNotNull()` methods instead

**LIKE wildcard escaping**: When building LIKE patterns in application code (e.g., `SqlEntityQuery`), escape `%` and `_` in user input:
```php
$escaped = str_replace(['%', '_'], ['\\%', '\\_'], $userInput);
$query->condition('title', '%' . $escaped . '%', 'LIKE');
```

All conditions are ANDed together. No OR support at this level.

## Discovery Response Caching (v1.0)

The HTTP kernel now maintains a dedicated `discovery` cache bin (database-backed, table `cache_discovery`) for anonymous public discovery API surfaces:

- `/api/discovery/hub/{entity_type}/{id}`
- `/api/discovery/cluster/{entity_type}/{id}`
- `/api/discovery/timeline/{entity_type}/{id}`
- `/api/discovery/endpoint/{entity_type}/{id}`

Cache key contract:

- Stable hash of `{surface, entity_type, entity_id, options}`.
- `options` are recursively normalized with deterministic associative-key sorting.
- Key dimensions include relationship filters, direction, temporal filters (`at/from/to`), pagination (`limit/offset`), and status mode.
- Shared primitive: `Waaseyaa\Foundation\Cache\DiscoveryCachePrimitives`.

Runtime behavior:

- Anonymous requests: cache read-through with `Cache-Control: public, max-age=120`.
- Cache hit header: `X-Waaseyaa-Discovery-Cache: HIT`.
- Cache miss header: `X-Waaseyaa-Discovery-Cache: MISS`.
- Authenticated requests bypass persistence and return `Cache-Control: private, no-store`.
- Discovery payloads carry a stable metadata envelope:
  - `meta.contract_version = v1.0`
  - `meta.contract_stability = stable`
  - `meta.surface = discovery_api` (default when not supplied by the caller)

Invalidation:

- Preferred path (tag-aware backends): targeted `invalidateByTags()` on save/delete using tags such as:
  - `discovery`
  - `discovery:entity:{type}`
  - `discovery:entity:{type}:{id}`
  - related-entity tags extracted from discovery payload edges/clusters/browse surfaces
  - plus broad discovery-surface tags for relationship/node graph-impact changes
- Fallback path (non tag-aware backends): `deleteAll()` for correctness.

## MCP Read-Path Caching (v1.1)

The HTTP kernel maintains a dedicated MCP read cache bin (database-backed, table `cache_mcp_read`) for read-heavy tool calls served by `Waaseyaa\Mcp\McpController`:

- `search_entities` / `search_teachings`
- `ai_discover`
- `traverse_relationships`
- `get_related_entities`
- `get_knowledge_graph`

Cache key contract:

- Stable hash of `{contract_version, tool, arguments, account_context}`.
- `arguments` are recursively normalized with deterministic associative-key sorting.
- `account_context` includes:
  - `authenticated` flag
  - account ID
  - sorted role list

This prevents cross-account and anonymous/authenticated cache leakage while preserving deterministic replay for identical callers and inputs.

Runtime behavior:

- Tool result payloads are cached with 120-second TTL.
- Payload contract remains unchanged (`meta.contract_version`, `meta.contract_stability`, tool metadata).
- Cache writes include tags:
  - `mcp_read`
  - `mcp_read:contract:v1.0`
  - `mcp_read:tool:{tool}`
  - entity tags extracted from arguments/payload (`mcp_read:entity:{type}` and `mcp_read:entity:{type}:{id}`).

Invalidation:

- Preferred path (tag-aware backends): targeted `invalidateByTags()` on entity save/delete:
  - `mcp_read`
  - `mcp_read:entity:{type}`
  - `mcp_read:entity:{type}:{id}`
- Fallback path (non tag-aware backends): `deleteAll()`.

## SSR Render Cache Variant Contract (v1.1)

SSR render cache keys include a deterministic variant suffix built from:

- language (`langcode`)
- view mode (`view_mode`)
- preview/public mode (`preview`)
- workflow state (`workflow_visibility.state`)
- graph-context hash (normalized `relationship_navigation`)
- contract version

The variant payload is normalized and hashed, then emitted with a readable prefix:

- `v2:{langcode}:{view_mode}:{public|preview}:{workflow_state}:{hash}`

This hardens cache partitioning and prevents future cache-key ambiguity while preserving deterministic replay under equivalent context inputs.

Security boundary:

- preview requests and public requests resolve to distinct variant keys,
- preview render paths are not persisted to shared public cache storage,
- public cache reads/writes remain restricted to unauthenticated, non-preview requests.

Render cache invalidation is broadened for relationship-aware pages:

- entity-specific invalidation still occurs on save/delete,
- when `node` or `relationship` entities change, type-wide node/relationship render tags are invalidated to prevent stale relationship-navigation output.

## Public SSR CDN Strategy (v1.4)

Public SSR routes now expose deterministic HTTP cache profiles aligned with workflow and graph-context invariants:

- `Cache-Control` for anonymous/public SSR responses:
  - `public, max-age={cache_max_age}, s-maxage={cache_shared_max_age}, stale-while-revalidate={cache_stale_while_revalidate}, stale-if-error={cache_stale_if_error}`
- Authenticated SSR responses remain private:
  - `private, no-store`

Default values when no explicit config is provided:

- `cache_max_age`: `300`
- `cache_shared_max_age`: fallback to `cache_max_age`
- `cache_stale_while_revalidate`: `60`
- `cache_stale_if_error`: `600`

### Surrogate-key contract

Public SSR entity responses also emit CDN-oriented surrogate keys:

- `Surrogate-Key` includes:
  - `waaseyaa:ssr`
  - entity scope: `waaseyaa:ssr:entity:{type}` and `waaseyaa:ssr:entity:{type}:{id}`
  - workflow scope: `waaseyaa:ssr:workflow:{workflow_state}`
  - view/lang scope: `waaseyaa:ssr:view:{view_mode}`, `waaseyaa:ssr:lang:{langcode}`
  - graph scope: `waaseyaa:ssr:graph:{graph_hash}`
- Debug/trace headers:
  - `X-Waaseyaa-Render-Variant`
  - `X-Waaseyaa-Render-Workflow`

### Invalidation behavior

SSR cache invalidation remains workflow/graph-aware and deterministic:

- save/delete of the rendered entity invalidates its entity-specific SSR cache entries,
- save/delete of `node` and `relationship` entities triggers broader invalidation for relationship-aware public surfaces,
- emitted surrogate keys are aligned with these invariants so CDN purge tooling can target entity/workflow/graph scopes without contract drift.

### InsertInterface

File: `packages/database-legacy/src/InsertInterface.php`

```php
interface InsertInterface
{
    public function fields(array $fields): static;      // column names
    public function values(array $values): static;      // can be called multiple times for batch
    public function execute(): int|string;              // returns lastInsertId
}
```

If `fields()` is not called, field names are inferred from the first `values()` call's array keys. Indexed arrays require prior `fields()` call.

### UpdateInterface

File: `packages/database-legacy/src/UpdateInterface.php`

```php
interface UpdateInterface
{
    public function fields(array $fields): static;      // ['column' => value]
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function execute(): int;                     // returns affected row count
}
```

### DeleteInterface

File: `packages/database-legacy/src/DeleteInterface.php`

```php
interface DeleteInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function execute(): int;                     // returns affected row count
}
```

### Usage examples

```php
// Select with join
$results = $db->select('node', 'n')
    ->fields('n', ['nid', 'title'])
    ->leftJoin('node_field_data', 'nfd', 'n.nid = nfd.nid')
    ->condition('n.type', 'article')
    ->orderBy('n.created', 'DESC')
    ->range(0, 10)
    ->execute();

// Insert
$db->insert('users')
    ->values(['uid' => 1, 'name' => 'admin', 'mail' => 'admin@example.com'])
    ->execute();

// Update
$affected = $db->update('users')
    ->fields(['name' => 'superadmin'])
    ->condition('uid', 1)
    ->execute();

// Delete
$affected = $db->delete('sessions')
    ->condition('expire', time(), '<')
    ->execute();

// Transaction
$txn = $db->transaction();
try {
    $db->insert('audit_log')->values([...])->execute();
    $txn->commit();
} catch (\Throwable $e) {
    $txn->rollBack();
    throw $e;
}
```

## Schema System

### SchemaInterface (database DDL)

File: `packages/database-legacy/src/SchemaInterface.php`

```php
interface SchemaInterface
{
    public function tableExists(string $table): bool;
    public function fieldExists(string $table, string $field): bool;
    public function createTable(string $name, array $spec): void;
    public function dropTable(string $table): void;
    public function addField(string $table, string $field, array $spec): void;
    public function dropField(string $table, string $field): void;
    public function addIndex(string $table, string $name, array $fields): void;
    public function dropIndex(string $table, string $name): void;
    public function addUniqueKey(string $table, string $name, array $fields): void;
    public function addPrimaryKey(string $table, array $fields): void;
}
```

`PdoSchema` uses SQLite-specific SQL. Type mapping: `serial` -> INTEGER AUTOINCREMENT, `varchar` -> TEXT, `int`/`integer` -> INTEGER, `text` -> TEXT, `float`/`numeric`/`decimal` -> REAL, `blob` -> BLOB.

Note: SQLite cannot add a primary key to an existing table. `addPrimaryKey()` throws `\RuntimeException`.

**Distinction from SchemaPresenter**: `SchemaInterface` is a database DDL abstraction in `packages/database-legacy/` for creating/altering tables. It is unrelated to `SchemaPresenter` (`packages/api/src/Schema/SchemaPresenter.php`), which generates JSON Schema output from entity field definitions for the API layer. `SchemaPresenter` works with `EntityType::getFieldDefinitions()` and does not use `SchemaInterface`.

## Migration System

The migration system uses Doctrine DBAL (not the legacy PdoDatabase). It lives in `packages/foundation/src/Migration/`.

### Migration base class

File: `packages/foundation/src/Migration/Migration.php`

```php
abstract class Migration
{
    public array $after = [];  // package names this migration must run after

    abstract public function up(SchemaBuilder $schema): void;
    public function down(SchemaBuilder $schema): void {}  // optional rollback
}
```

### SchemaBuilder

File: `packages/foundation/src/Migration/SchemaBuilder.php`

Uses Doctrine DBAL `Connection` + `Schema`. Creates tables via `TableBuilder` closure pattern:

```php
$schema->create('nodes', function (TableBuilder $table) {
    $table->id();                           // string('id', 128)
    $table->string('type', 64);
    $table->text('title');
    $table->json('_data')->nullable();
    $table->timestamps();                   // created + changed timestamps
    $table->primary(['id']);
    $table->index(['type']);
});
```

Other `SchemaBuilder` methods: `drop()`, `dropIfExists()`, `hasTable()`, `hasColumn()`.

### TableBuilder column types

File: `packages/foundation/src/Migration/TableBuilder.php`

| Method | Column Type | Doctrine Type |
|--------|------------|---------------|
| `id(name)` | `string(name, 128)` | STRING |
| `string(name, length)` | varchar | STRING |
| `text(name)` | text | TEXT |
| `integer(name)` | integer | INTEGER |
| `boolean(name)` | boolean | BOOLEAN |
| `float(name)` | float | FLOAT |
| `json(name)` | json | JSON |
| `timestamp(name)` | datetime_immutable | DATETIME_IMMUTABLE |

Convenience methods: `timestamps()` (creates `created` + `changed`), `entityBase()` (id + entity_type + bundle + _data + timestamps), `translationColumns()` (langcode + default_langcode + translation_source), `revisionColumns()` (revision_id + revision_created + revision_log).

### ColumnDefinition

File: `packages/foundation/src/Migration/ColumnDefinition.php`

Fluent modifiers: `->nullable()`, `->default(value)`, `->unique()`.

### Migrator

File: `packages/foundation/src/Migration/Migrator.php`

```php
final class Migrator
{
    public function __construct(Connection $connection, MigrationRepository $repository);

    /** @param array<string, array<string, Migration>> $migrations  package => [name => Migration] */
    public function run(array $migrations): MigrationResult;
    public function rollback(array $migrations): MigrationResult;
    public function status(array $migrations): array;  // ['pending' => [...], 'completed' => [...]]
}
```

Migrations are topologically sorted by `Migration::$after` dependencies. Each batch gets an incrementing batch number. Rollback undoes the last batch in reverse order.

### MigrationRepository

File: `packages/foundation/src/Migration/MigrationRepository.php`

Tracks executed migrations in the `waaseyaa_migrations` table:
- `id` INTEGER PRIMARY KEY AUTOINCREMENT
- `migration` VARCHAR(255) -- migration name
- `package` VARCHAR(128) -- originating package
- `batch` INTEGER -- batch number
- `ran_at` TIMESTAMP

### MigrationResult

File: `packages/foundation/src/Migration/MigrationResult.php`

```php
final readonly class MigrationResult
{
    public function __construct(
        public int $count,
        public array $migrations = [],
    ) {}
}
```

## File Reference

### packages/foundation/src/

```
Event/
    DomainEvent.php              -- abstract base for all domain events
    EventBus.php                 -- three-channel dispatcher (sync/async/broadcast)
    EventStoreInterface.php      -- append-only event store
    BroadcasterInterface.php     -- SSE/real-time broadcast
    Attribute/
        Listener.php             -- #[Listener(priority: 0)]
        Async.php                -- #[Async] on method
        Broadcast.php            -- #[Broadcast(channel: '...')]
Middleware/
    HttpMiddlewareInterface.php  -- process(Request, HttpHandlerInterface): Response
    HttpHandlerInterface.php     -- handle(Request): Response
    HttpPipeline.php             -- onion-pattern HTTP middleware stack
    EventMiddlewareInterface.php -- process(DomainEvent, EventHandlerInterface): void
    EventHandlerInterface.php    -- handle(DomainEvent): void
    EventPipeline.php            -- onion-pattern event middleware stack
    JobMiddlewareInterface.php   -- process(Job, JobHandlerInterface): void
    JobHandlerInterface.php      -- handle(Job): void
    JobPipeline.php              -- onion-pattern job middleware stack
Migration/
    Migration.php                -- abstract base, up()/down() + $after deps
    SchemaBuilder.php            -- Doctrine DBAL table creation
    TableBuilder.php             -- fluent column definition DSL
    ColumnDefinition.php         -- nullable/default/unique modifiers
    Migrator.php                 -- topological sort + batch execution
    MigrationRepository.php      -- tracks completed migrations in DB
    MigrationResult.php          -- count + list of ran migrations
ServiceProvider/
    ServiceProviderInterface.php -- register()/boot()/provides()/isDeferred()
    ServiceProvider.php          -- abstract base with singleton/bind/tag helpers
    ProviderDiscovery.php        -- reads composer installed.json extra.waaseyaa
    ContainerCompiler.php        -- register phase -> boot phase -> Symfony DI container
Discovery/
    PackageManifest.php          -- typed DTO for cached manifest data
    PackageManifestCompiler.php  -- reads composer metadata + scans PHP attributes -> packages.php
Attribute/
    AsFieldType.php              -- #[AsFieldType(id: '...', label: '...')]
    AsEntityType.php             -- #[AsEntityType(id: '...', label: '...')]
    AsMiddleware.php             -- #[AsMiddleware(pipeline: '...', priority: 0)]
```

### packages/cache/src/

```
CacheBackendInterface.php        -- get/set/delete/invalidate contract
CacheItem.php                    -- readonly DTO: cid, data, created, expire, tags, valid
CacheFactoryInterface.php        -- get(bin): CacheBackendInterface
CacheFactory.php                 -- bin resolution via CacheConfiguration
CacheConfiguration.php           -- bin->backend mapping, factory callables
TagAwareCacheInterface.php       -- extends CacheBackendInterface + invalidateByTags()
CacheTagsInvalidatorInterface.php -- invalidateTags(tags)
CacheTagsInvalidator.php         -- delegates to all registered TagAwareCacheInterface bins
Backend/
    MemoryBackend.php            -- in-memory, tag-aware (use for tests)
    DatabaseBackend.php          -- PDO-backed, auto-creates table, tag-aware
    NullBackend.php              -- no-op backend
Listener/
    EntityCacheInvalidator.php   -- entity:{type}, entity:{type}:{id}
    ConfigCacheInvalidator.php   -- config, config:{name}
    TranslationCacheInvalidator.php
```

### packages/database-legacy/src/

```
DatabaseInterface.php            -- select/insert/update/delete/schema/transaction/query
PdoDatabase.php                  -- implements DatabaseInterface, has getPdo()
SelectInterface.php              -- fluent select builder
InsertInterface.php              -- fluent insert builder
UpdateInterface.php              -- fluent update builder
DeleteInterface.php              -- fluent delete builder
SchemaInterface.php              -- DDL operations (createTable, addField, etc.)
TransactionInterface.php         -- commit/rollBack
PdoTransaction.php               -- PDO transaction wrapper
Query/
    PdoSelect.php                -- SELECT with joins, conditions, ordering, pagination
    PdoInsert.php                -- INSERT with field inference from values
    PdoUpdate.php                -- UPDATE with conditions
    PdoDelete.php                -- DELETE with conditions
Schema/
    PdoSchema.php                -- SQLite DDL implementation
```
