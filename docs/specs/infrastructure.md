# Infrastructure

<!-- Spec reviewed 2026-04-02 - post-M10 package declarations, FoundationServiceProvider registration, provider-owned routes and CLI graph, C18 drift remediation (#1017), and production SQLite bootstrap guard follow-up (#748) -->

Specification for the foundational infrastructure layer of Waaseyaa CMS: domain events, cache system, database abstraction, query builder, migration system, kernel bootstrapping (including environment resolution and debug mode), service provider discovery, and queue workers.

## Packages

| Package | Namespace | Layer | Purpose |
|---------|-----------|-------|---------|
| `packages/foundation/` | `Waaseyaa\Foundation\` | 0 (Foundation) | DomainEvent, ServiceProvider, middleware interfaces, migration system, attribute discovery |
| `packages/cache/` | `Waaseyaa\Cache\` | 0 (Foundation) | CacheBackendInterface, MemoryBackend, DatabaseBackend, NullBackend, tag invalidation |
| `packages/database-legacy/` | `Waaseyaa\Database\` | 0 (Foundation) | DatabaseInterface, DBALDatabase (Doctrine DBAL), query builder (select/insert/update/delete), schema, transactions |
| `packages/plugin/` | `Waaseyaa\Plugin\` | 0 (Foundation) | PluginManager, attribute-based plugin discovery, plugin factory |
| `packages/mail/` | `Waaseyaa\Mail\` | 0 (Foundation) | Transport-agnostic mail API with Twig templating, pluggable transports (ArrayTransport for tests, LocalTransport for file-based delivery) |
| `packages/http-client/` | `Waaseyaa\HttpClient\` | 0 (Foundation) | Minimal HTTP client for JSON APIs and webhooks, zero external dependencies |

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

**CRITICAL**: `DatabaseInterface` does NOT have `getConnection()`. If the DBAL `Connection` is needed, type-hint `DBALDatabase` directly. Prefer using the query builder (`select()`, `insert()`, `update()`, `delete()`) over raw DBAL.

### DBALDatabase

File: `packages/database-legacy/src/DBALDatabase.php`

```php
final class DBALDatabase implements DatabaseInterface
{
    public function __construct(private readonly Connection $connection);
    public static function createSqlite(string $path = ':memory:'): self;
    public function getConnection(): Connection;   // ONLY on DBALDatabase, NOT on DatabaseInterface
}
```

`DBALDatabase` wraps a Doctrine DBAL `Connection`. The `createSqlite()` factory enables WAL mode for non-memory databases. Query results use `fetchAssociative()` (equivalent to FETCH_ASSOC — no duplicate numeric-indexed columns).

### TransactionInterface

File: `packages/database-legacy/src/TransactionInterface.php`

```php
interface TransactionInterface
{
    public function commit(): void;
    public function rollBack(): void;
}
```

`DBALTransaction` begins the transaction in its constructor. Calling `commit()` or `rollBack()` after the transaction is no longer active throws `\RuntimeException`.

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

### DBALSelect condition operators

File: `packages/database-legacy/src/Query/DBALSelect.php`

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

`DBALSchema` uses Doctrine DBAL's schema introspection and DDL generation. Type mapping: `serial` -> INTEGER AUTOINCREMENT, `varchar` -> TEXT, `int`/`integer` -> INTEGER, `text` -> TEXT, `float`/`numeric`/`decimal` -> REAL, `blob` -> BLOB.

Note: SQLite cannot add a primary key to an existing table. `addPrimaryKey()` throws `\RuntimeException`.

**Distinction from SchemaPresenter**: `SchemaInterface` is a database DDL abstraction in `packages/database-legacy/` for creating/altering tables. It is unrelated to `SchemaPresenter` (`packages/api/src/Schema/SchemaPresenter.php`), which generates JSON Schema output from entity field definitions for the API layer. `SchemaPresenter` works with `EntityType::getFieldDefinitions()` and does not use `SchemaInterface`.

## Migration System

The migration system uses Doctrine DBAL (same as the database layer). It lives in `packages/foundation/src/Migration/`.

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

## HTTP Client

Minimal HTTP client with no external dependencies (uses PHP streams). Zero composer dependencies — requires only `php: >=8.4`.

### HttpClientInterface

File: `packages/http-client/src/HttpClientInterface.php`

```php
interface HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse;
    public function get(string $url, array $headers = []): HttpResponse;
    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse;
}
```

### HttpResponse

File: `packages/http-client/src/HttpResponse.php`

```php
final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    );

    public function json(): array;      // json_decode with JSON_THROW_ON_ERROR
    public function isSuccess(): bool;  // 200-299
}
```

### StreamHttpClient

File: `packages/http-client/src/StreamHttpClient.php`

Implementation using `file_get_contents()` with stream contexts. Throws `HttpRequestException` on failure.

### HttpRequestException

File: `packages/http-client/src/HttpRequestException.php`

```php
final class HttpRequestException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $url,
        public readonly string $method,
        public readonly ?HttpResponse $response = null,
        ?\Throwable $previous = null,
    );
}
```

Carries the failed request's URL, method, and optionally the response (when the server responded but with an error status). This allows callers to inspect both transport failures and HTTP error responses uniformly.

## Logging

Waaseyaa provides its own logging interfaces (not `psr/log`). All loggers implement `Waaseyaa\Foundation\Log\LoggerInterface`.

### LoggerInterface

File: `packages/foundation/src/Log/LoggerInterface.php`

```php
interface LoggerInterface
{
    public function emergency(string|\Stringable $message, array $context = []): void;
    public function alert(string|\Stringable $message, array $context = []): void;
    public function critical(string|\Stringable $message, array $context = []): void;
    public function error(string|\Stringable $message, array $context = []): void;
    public function warning(string|\Stringable $message, array $context = []): void;
    public function notice(string|\Stringable $message, array $context = []): void;
    public function info(string|\Stringable $message, array $context = []): void;
    public function debug(string|\Stringable $message, array $context = []): void;
    public function log(LogLevel $level, string|\Stringable $message, array $context = []): void;
}
```

### LogLevel

File: `packages/foundation/src/Log/LogLevel.php`

String-backed enum: `EMERGENCY`, `ALERT`, `CRITICAL`, `ERROR`, `WARNING`, `NOTICE`, `INFO`, `DEBUG`.

### LogRecord

File: `packages/foundation/src/Log/LogRecord.php`

Immutable value object carrying a single log entry: `level` (LogLevel), `message` (string), `context` (array), `channel` (string, defaults to `'default'`), `timestamp` (DateTimeImmutable, defaults to now).

### LogManager

File: `packages/foundation/src/Log/LogManager.php`

Central log orchestrator. Implements `LoggerInterface` — calling `log()` delegates to the default channel. Constructor accepts `LoggerInterface|HandlerInterface` for the default handler (legacy loggers are wrapped in `LegacyLoggerHandler`). `channel(string $name)` returns a `ChannelLogger` for the named channel; unknown channels fall back to the default. `fromConfig(array $config)` static factory builds channels from config (two-pass: non-stack handlers first, then stack handlers that reference other channels).

The kernel constructs `LogManager(new Handler\ErrorLogHandler())` at startup, then upgrades it after config loads: if `config['logging']['channels']` exists, uses `LogManager::fromConfig()`; otherwise falls back to `log_level` config with a single `Handler\ErrorLogHandler(minimumLevel: $level)`.

### ChannelLogger

File: `packages/foundation/src/Log/ChannelLogger.php`

Scoped `LoggerInterface` that stamps a channel name on every `LogRecord`, runs processors (global + per-channel), then delegates to a `HandlerInterface`. Created by `LogManager::channel()`. Constructor: `(string $channel, HandlerInterface $handler, array $processors = [])`. Processor failures are best-effort: caught, logged via `error_log()`, pipeline continues.

### Handler pipeline

| Interface/Class | File | Purpose |
|-------|------|---------|
| `HandlerInterface` | `Log/Handler/HandlerInterface.php` | Contract: `handle(LogRecord $record): void` |
| `ErrorLogHandler` | `Log/Handler/ErrorLogHandler.php` | Delegates to `error_log()`. Constructor: `(?FormatterInterface $formatter = null, LogLevel $minimumLevel = LogLevel::DEBUG, ?\Closure $writer = null)`. Discards messages below `minimumLevel`. |
| `FileHandler` | `Log/Handler/FileHandler.php` | Appends formatted record to a file with `LOCK_EX`. Constructor: `(string $path, ?FormatterInterface $formatter = null, LogLevel $minimumLevel = LogLevel::DEBUG)`. |
| `StackHandler` | `Log/Handler/StackHandler.php` | Fan-out to multiple handlers. Constructor: `(HandlerInterface ...$handlers)`. Best-effort: catches `\Throwable` per handler so one failure doesn't stop others. |
| `NullHandler` | `Log/Handler/NullHandler.php` | Discards all records — for testing and disabled logging. |
| `StreamHandler` | `Log/Handler/StreamHandler.php` | Writes to `php://stderr` or any stream resource. Constructor validates resource type; throws `\InvalidArgumentException` on non-resource. |
| `LegacyLoggerHandler` | `Log/LegacyLoggerHandler.php` | Adapts Phase A `LoggerInterface` implementations to `HandlerInterface`. Internal, used by `LogManager` for backward compatibility. |

### Formatter pipeline

| Interface/Class | File | Purpose |
|-------|------|---------|
| `FormatterInterface` | `Log/Formatter/FormatterInterface.php` | Contract: `format(LogRecord $record): string` |
| `TextFormatter` | `Log/Formatter/TextFormatter.php` | Format: `[timestamp] [level] [channel] message {context}`. Omits context braces when empty. |
| `JsonFormatter` | `Log/Formatter/JsonFormatter.php` | One JSON object per line with all fields: timestamp, level, channel, message, context. |

### Processor pipeline

Processors enrich `LogRecord` context before handlers receive the record. Execution order: global processors first, then per-channel processors.

| Interface/Class | File | Purpose |
|-------|------|---------|
| `ProcessorInterface` | `Log/Processor/ProcessorInterface.php` | Contract: `process(LogRecord $record): LogRecord`. Must return a new record, not mutate input. |
| `RequestIdProcessor` | `Log/Processor/RequestIdProcessor.php` | Adds `request_id` (UUID hex) to context. Same ID for all records within a single processor instance. |
| `HostnameProcessor` | `Log/Processor/HostnameProcessor.php` | Adds `hostname` to context. Defaults to `gethostname()`. |
| `MemoryUsageProcessor` | `Log/Processor/MemoryUsageProcessor.php` | Adds `memory_peak_mb` (float) to context. |

### Legacy logger implementations

| Class | File | Purpose |
|-------|------|---------|
| `NullLogger` | `Log/NullLogger.php` | No-op — for testing and disabled logging. Widely used across packages. |

`LoggerTrait` provides convenience methods (`emergency()`, `error()`, etc.) that delegate to `log()`.

Removed in Phase C: `FileLogger`, `CompositeLogger`, legacy `ErrorLogHandler` (at `Log/ErrorLogHandler.php`). Use `Handler\ErrorLogHandler`, `Handler\FileHandler`, `Handler\StackHandler` instead.

## Rate Limiting

### RateLimiterInterface

File: `packages/foundation/src/RateLimit/RateLimiterInterface.php`

```php
interface RateLimiterInterface
{
    /** @return array{allowed: bool, remaining: int, retryAfter: ?int} */
    public function attempt(string $key, int $maxAttempts, int $windowSeconds): array;
}
```

### InMemoryRateLimiter

File: `packages/foundation/src/RateLimit/InMemoryRateLimiter.php`

Sliding-window rate limiter stored in memory. Resets per-process. Used by `RateLimitMiddleware`.

## Asset Management

### ViteAssetManager

File: `packages/foundation/src/Asset/ViteAssetManager.php`
Implements: `AssetManagerInterface`

```php
final class ViteAssetManager implements AssetManagerInterface
{
    public function __construct(
        private readonly string $basePath,     // dist directory path
        private readonly string $baseUrl = '/dist',
    );

    public function url(string $path, string $bundle = 'admin'): string;
}
```

Reads Vite `manifest.json` files to resolve source paths to hashed asset URLs. Manifests are cached per bundle.

`TenantAssetResolver` (`packages/foundation/src/Asset/TenantAssetResolver.php`) resolves tenant-specific asset paths.

## HTTP Utilities

### ControllerDispatcher

File: `packages/foundation/src/Http/ControllerDispatcher.php`

Routes a matched controller name to the appropriate handler. Receives controller identifier, route params, and request context, then delegates to JSON:API controllers, discovery endpoints, SSR, MCP, or other handlers. Central dispatch hub for `HttpKernel`.

**Auth login handler** (`auth.login`): Validates credentials via `AuthController::findUserByName()` + `User::checkPassword()`, sets `$_SESSION['waaseyaa_uid']`, then calls `session_regenerate_id(true)` (session fixation prevention) and `session_write_close()` to flush the `Set-Cookie` header before `ResponseSender::json()` terminates with `exit`.

### CorsHandler

File: `packages/foundation/src/Http/CorsHandler.php`

```php
final class CorsHandler
{
    public function __construct(
        private readonly array $allowedOrigins = ['http://localhost:3000', 'http://127.0.0.1:3000'],
        private readonly bool $allowDevLocalhostPorts = false,
        ?LoggerInterface $logger = null,
    );

    public function resolveCorsHeaders(string $origin): array;
    public function handlePreflight(string $origin, string $requestMethod): array;
    public function isCorsPreflightRequest(string $method): bool;
}
```

CORS origin resolution in `HttpKernel::handleCors()`:
1. Reads `cors_origins` from config (defaults to `localhost:3000` and `127.0.0.1:3000`).
2. Checks `WAASEYAA_CORS_ORIGIN` env var — if set, overrides the config array with a single-origin list.
3. Passes `allowDevLocalhostPorts: true` when the kernel is in development mode (env is `dev`, `development`, or `local`), allowing any localhost port.

### Dev fallback account

`HttpKernel::shouldUseDevFallbackAccount()` controls whether `DevAdminAccount` is injected as the session fallback. All three conditions must be true:
- PHP SAPI is `cli-server` (built-in dev server)
- Application is in development mode (`config.environment` or `APP_ENV` is dev/development/local)
- `config.auth.dev_fallback_account` is explicitly `true`

### ResponseSender

File: `packages/foundation/src/Http/ResponseSender.php`

Sends Symfony `Response` objects to the client. Handles header output and body streaming.

## Operator Diagnostics

### DiagnosticCode

File: `packages/foundation/src/Diagnostic/DiagnosticCode.php`

String-backed enum of operator-facing error codes:

| Code | Trigger |
|------|---------|
| `DEFAULT_TYPE_MISSING` | No entity types registered at boot |
| `DEFAULT_TYPE_DISABLED` | All registered types disabled |
| `DATABASE_UNREACHABLE` | Database file missing or corrupt |
| `DATABASE_SCHEMA_DRIFT` | Entity table columns don't match expected schema |
| `STORAGE_DIRECTORY_MISSING` | `storage/framework/` does not exist |
| `CACHE_DIRECTORY_UNWRITABLE` | Cache directory not writable |
| `INGESTION_LOG_OVERSIZED` | Ingestion log exceeds retention threshold |
| `INGESTION_RECENT_FAILURES` | High ingestion failure rate |

Each code has a `defaultMessage()` method for human-readable descriptions.

### DiagnosticEmitter

File: `packages/foundation/src/Diagnostic/DiagnosticEmitter.php`

```php
final class DiagnosticEmitter
{
    public function __construct(?LoggerInterface $logger = null);
    public function emit(DiagnosticCode $code, string $message, array $context = []): DiagnosticEntry;
}
```

Emits structured JSON diagnostic log entries. Returns `DiagnosticEntry` for callers that need to inspect or re-throw.

### HealthChecker

File: `packages/foundation/src/Diagnostic/HealthChecker.php`
Implements: `HealthCheckerInterface`

```php
final class HealthChecker implements HealthCheckerInterface
{
    public function __construct(
        private readonly BootDiagnosticReport $bootReport,
        private readonly DatabaseInterface $database,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $projectRoot,
        ?LoggerInterface $logger = null,
    );

    public function runAll(): array;          // list<HealthCheckResult>
    public function checkBoot(): array;       // entity type registry state
    public function checkRuntime(): array;    // database, schema drift, storage, cache dirs
    public function checkIngestion(): array;  // ingestion log health, error rates
}
```

Three check groups: boot (entity type registry), runtime (database connectivity, schema drift, storage directories), and ingestion (log size, error rate). Results are `HealthCheckResult` value objects with pass/warn/fail status.

## Queue System

File: `packages/queue/`
Namespace: `Waaseyaa\Queue\`

### QueueInterface

File: `packages/queue/src/QueueInterface.php`

Queue implementations: `DbalQueue` (DBAL-backed persistent), `InMemoryQueue` (testing), `MessageBusQueue` (Symfony Messenger bridge), `SyncQueue` (immediate execution).

### Worker

File: `packages/queue/src/Worker/Worker.php`
Class: `final class Worker`

Constructor: `(TransportInterface $transport, FailedJobRepositoryInterface $failedJobRepository, array $handlers)`

Long-running daemon that processes jobs from a queue transport.

**Public API:**
- `run(string $queue, WorkerOptions $options): int` — daemon loop, returns count of jobs processed
- `runNextJob(string $queue, WorkerOptions $options): bool` — process single job (non-looping, useful for tests)
- `stop(): void` — request graceful shutdown (finishes current job, then exits)
- `addHandler(HandlerInterface $handler): void` — prepend a handler (first added = highest priority)

**Stop conditions** (checked in `shouldContinue()`):
- `$shouldQuit` flag set (via `stop()` or POSIX signal)
- `maxJobs` reached (`$options->maxJobs > 0 && $processed >= $options->maxJobs`)
- `maxTime` elapsed (`$options->maxTime > 0 && (time() - $startTime) >= $options->maxTime`)
- Memory limit exceeded (`memory_get_usage(true) / 1024 / 1024 >= $options->memoryLimit`)

**POSIX signal handling:** `listenForSignals()` registers SIGTERM/SIGINT handlers that set `$shouldQuit = true`. `pcntl_signal_dispatch()` is called each iteration in `shouldContinue()`. Gracefully degrades when `pcntl` extension is unavailable.

**Job processing pipeline:**
1. `transport->pop($queue)` — dequeue raw message (`{id, payload, attempts}`)
2. `@unserialize($raw['payload'])` — deserialize (failures recorded to `FailedJobRepository`)
3. First matching `HandlerInterface::supports($message)` handles the job
4. If `Job::isReleased()`, release back to queue with delay; otherwise `transport->ack()`
5. On exception: retry with exponential backoff (`min(baseDelay * 2^(attempts-1), 3600)`) if under `maxTries`, otherwise record failure and call `Job::failed($e)` (best-effort)

**WorkerOptions** (`packages/queue/src/Worker/WorkerOptions.php`): Controls `maxJobs`, `maxTime`, `memoryLimit`, `sleep` (seconds between polls), `maxTries`.

### Transport layer

`TransportInterface` (`packages/queue/src/Transport/TransportInterface.php`) abstracts job serialization/deserialization. Implementations: `DbalTransport` (database-backed), `InMemoryTransport` (testing).

### Failed job tracking

`FailedJobRepositoryInterface` with implementations: `DatabaseFailedJobRepository` (DBAL-backed), `InMemoryFailedJobRepository` (testing).

### Message types

| Message | Purpose |
|---------|---------|
| `EntityMessage` | Entity lifecycle events for async processing |
| `ConfigMessage` | Config change propagation |
| `GenericMessage` | Arbitrary payload |

### Job attributes

| Attribute | Purpose |
|-----------|---------|
| `#[OnQueue('name')]` | Route job to a specific queue |
| `#[RateLimited]` | Apply rate limiting to job execution |
| `#[UniqueJob]` | Prevent duplicate concurrent execution |

### Job composition

`BatchedJobs` groups multiple jobs for parallel execution. `ChainedJobs` runs jobs sequentially — failure stops the chain.

### Migration

`CreateQueueTables` migration creates the `queue_jobs` and `failed_jobs` tables.

## Kernel Bootstrap

The kernel boot sequence is decomposed into extracted bootstrapper classes in `packages/foundation/src/Kernel/Bootstrap/`. `AbstractKernel` delegates to these rather than inlining the logic.

### AbstractKernel

File: `packages/foundation/src/Kernel/AbstractKernel.php`

Constructor: `(string $projectRoot, ?LoggerInterface $logger = null)`

Default logger is `LogManager(new Handler\ErrorLogHandler())`. After config loads, the kernel rebuilds it: if `config['logging']['channels']` exists, uses `LogManager::fromConfig()`; otherwise uses `Handler\ErrorLogHandler(minimumLevel: $level)` from `config['log_level']`.

Boot sequence (idempotent — guarded by `$this->booted` flag, set only after all steps succeed):

```
EnvLoader::load(.env)
  → ConfigLoader::load(config/waaseyaa.php)
  → rebuild LogManager (fromConfig if logging.channels exists, else log_level fallback)
  → debug/environment safety guard
  → new EventDispatcher()
  → new EntityTypeLifecycleManager($projectRoot)
  → new EntityAuditLogger($projectRoot)
  → register EntityWriteAuditListener on PRE_SAVE, POST_SAVE, POST_DELETE
  → bootDatabase()           // DatabaseBootstrapper
  → bootEntityTypeManager()  // inline, wires storage factory closure
  → compileManifest()        // ManifestBootstrapper
  → bootMigrations()         // reuses DBAL connection from bootDatabase
  → discoverAndRegisterProviders()  // ProviderRegistry
  → loadAppEntityTypes()     // reads config/entity-types.php
  → validateContentTypes()   // DiagnosticEmitter check
  → bootProviders()          // calls boot() on all registered providers
  → discoverAccessPolicies() // AccessPolicyRegistry
  → bootKnowledgeExtensionRunner() // plugin discovery for knowledge tooling extensions
  → $this->booted = true
```

Early boot initializes the entity lifecycle manager (for disabling entity types at runtime) and the entity audit logger (for write audit trails). The `EntityWriteAuditListener` is registered on the event dispatcher before any entity storage is created, ensuring all entity writes are audited from boot onward.

`loadAppEntityTypes()` reads `config/entity-types.php` and registers any `EntityTypeInterface` instances found there. Non-conforming entries are logged as warnings. Registration failures (duplicate IDs, invalid definitions) are logged as errors but do not halt boot.

`validateContentTypes()` checks that at least one entity type is registered and enabled. If no types exist, it emits `DEFAULT_TYPE_MISSING` and throws. If all registered types are disabled via the lifecycle manager, it emits `DEFAULT_TYPE_DISABLED` and throws.

`bootKnowledgeExtensionRunner()` reads `config.extensions.plugin_directories` and `config.extensions.plugin_attribute`, discovers plugins via `AttributeDiscovery`, and builds a `KnowledgeToolingExtensionRunner`. On failure, falls back to an empty runner. The runner is accessible via `getKnowledgeToolingExtensionRunner()` and provides `applyWorkflowContext()`, `applyTraversalContext()`, and `applyDiscoveryContext()` extension hooks.

#### Environment and debug introspection

Three protected methods provide environment awareness to all kernel subclasses:

| Method | Resolution | Returns |
|--------|-----------|---------|
| `resolveEnvironment(): string` | Config `'environment'` key → `APP_ENV` env var → `'production'` | Canonical environment name (e.g., `'production'`, `'local'`, `'development'`) |
| `isDevelopmentMode(): bool` | Calls `resolveEnvironment()`, checks if value is `dev`, `development`, or `local` (case-insensitive) | `true` in dev environments |
| `isDebugMode(): bool` | `APP_DEBUG` env var → config `'debug'` key → `false` | `true` when debug is enabled |

**Boot guard:** Immediately after loading configuration, `boot()` checks `isDebugMode() && !isDevelopmentMode()`. If debug is enabled outside a development environment, it throws `RuntimeException` with the message `APP_DEBUG must not be enabled in production (APP_ENV=...)`. This prevents accidentally deploying with debug mode active.

### DatabaseBootstrapper

File: `packages/foundation/src/Kernel/Bootstrap/DatabaseBootstrapper.php`
Class: `final class DatabaseBootstrapper`

```php
public function boot(string $projectRoot, array $config): DatabaseInterface
```

Creates `DBALDatabase::createSqlite()` using path resolution: `$config['database']` → `WAASEYAA_DB` env → `$projectRoot/storage/waaseyaa.sqlite`. In non-production environments, ensures the parent directory exists via `@mkdir()` (warning-suppressed — failure is expected in tests with inaccessible paths; SQLite will throw a proper exception downstream).

Production safety contract:
- environment resolution matches the kernel contract: config `'environment'` key → `APP_ENV` env var → `'production'`
- when the resolved environment is `production`, file-backed SQLite paths must already exist before boot continues
- if the resolved production SQLite file is missing, bootstrap throws `RuntimeException` with `Database not found at {path}. In production, the database must already exist.`
- when that production guard fires, bootstrap does not create the parent directory as a side effect
- non-production environments (`local`, `dev`, `development`, etc.) keep the existing auto-create behavior
- `:memory:` remains allowed in all environments for explicit in-memory bootstrap/test cases

### ManifestBootstrapper

File: `packages/foundation/src/Kernel/Bootstrap/ManifestBootstrapper.php`
Class: `final class ManifestBootstrapper`

```php
public function boot(string $projectRoot): PackageManifest
```

Instantiates `PackageManifestCompiler` with `storagePath: $projectRoot . '/storage'` and calls `load()` (cache-first, compile on miss).

`storage/framework/packages.php` includes metadata key `_manifest_inputs_fp`: an `xxh128` digest of the raw contents of the project `composer.json` and `vendor/composer/installed.json`. When present and not equal to a freshly computed digest, `load()` discards the cache and recompiles (covers new/removed Composer packages and copied stale caches). After loading a cached manifest, `assertProvidersExist()` validates that all declared provider classes can be autoloaded. If any are missing, the manifest auto-recovers by logging a warning and recompiling from disk — no manual `optimize:manifest` needed. `StaleManifestException` is still thrown by `assertProvidersExist()` but is caught internally by `load()` as a recompile trigger.

The compiled manifest now also carries `packageDeclarations`, derived from package-local `composer.json` metadata and merged installed-package metadata. This is the post-M10 baseline used to normalize provider ownership and to verify that declared provider classes still exist before the manifest is trusted.

On every successful cache read, root `extra.waaseyaa` providers, commands, routes, and permissions are merged again from `composer.json` so a structurally valid cache cannot omit app-level declarations that match the current fingerprint.

### ProviderRegistry

File: `packages/foundation/src/Kernel/Bootstrap/ProviderRegistry.php`
Class: `final class ProviderRegistry`

Constructor: `(LoggerInterface $logger)`

```php
public function discoverAndRegister(
    PackageManifest $manifest,
    string $projectRoot,
    array $config,
    EntityTypeManager $entityTypeManager,
    DatabaseInterface $database,
    EventDispatcherInterface $dispatcher,
): array  // list<ServiceProvider>
```

Discovery and registration follows a multi-phase process:

1. **Instantiation**: Each provider class from `$manifest->providers` is instantiated. Non-`ServiceProvider` instances are logged and skipped.
2. **Context injection**: Each provider receives kernel context via `setKernelContext($projectRoot, $config, $manifest->formatters)` and a kernel resolver closure via `setKernelResolver()`. The resolver provides cross-provider DI — it resolves `EntityTypeManager`, `DatabaseInterface`, `EventDispatcherInterface`, `LoggerInterface`, and any binding registered by previously-loaded providers.
3. **Registration**: `register()` is called on each provider, allowing them to bind interfaces to implementations.
4. **Entity type collection**: After all providers register, entity types from `$provider->getEntityTypes()` are registered with the `EntityTypeManager`. Registration failures are logged as errors but do not halt boot.
5. **Provider-owned surfaces**: Route and command ownership stays with the package provider or package registry that declared it. Foundation now declares only its own baseline provider (`Waaseyaa\Foundation\FoundationServiceProvider`), while package-level providers such as `ApiServiceProvider`, `UserServiceProvider`, and `McpServiceProvider` own their respective HTTP surfaces.

The method returns the full list of instantiated providers. Handles instantiation failures gracefully with error logging.

### AccessPolicyRegistry

File: `packages/foundation/src/Kernel/Bootstrap/AccessPolicyRegistry.php`
Class: `final class AccessPolicyRegistry`

Constructor: `(LoggerInterface $logger)`

```php
public function discover(PackageManifest $manifest): EntityAccessHandler
```

Reads `$manifest->policies` (keyed by class name → entity type list), instantiates each policy class, and returns a wired `EntityAccessHandler`. Uses reflection heuristic: policies with required constructor parameters (e.g., `ConfigEntityAccessPolicy`) receive the entity type list; no-arg policies are instantiated directly. Missing classes and instantiation failures are logged, not fatal.

## File Reference

### packages/foundation/src/

```
Kernel/
    AbstractKernel.php           -- boot orchestrator, delegates to Bootstrap/ classes
    HttpKernel.php               -- HTTP request handling, cache setup, CORS
    ConsoleKernel.php            -- CLI bootstrapping; delegates command graph assembly to `Waaseyaa\CLI\CliCommandRegistry`
    EnvLoader.php                -- .env file parser
    ConfigLoader.php             -- config/waaseyaa.php loader
    EventListenerRegistrar.php   -- registers cache invalidation listeners
    BuiltinRouteRegistrar.php    -- registers shared foundation-owned HTTP routes (schema, discovery, entity-types, SSR catch-all)
    Bootstrap/
        DatabaseBootstrapper.php     -- creates DBALDatabase connection
        ManifestBootstrapper.php     -- loads/compiles PackageManifest
        ProviderRegistry.php         -- discovers, instantiates, and registers service providers
        AccessPolicyRegistry.php     -- discovers access policies and wires EntityAccessHandler
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
Log/
    LoggerInterface.php          -- log contract (emergency through debug + log)
    LogLevel.php                 -- string-backed enum (EMERGENCY..DEBUG)
    LoggerTrait.php              -- convenience methods delegating to log()
    LogRecord.php                -- immutable VO: level, message, context, channel, timestamp
    LogManager.php               -- channel registry, implements LoggerInterface, fromConfig() factory
    ChannelLogger.php            -- scoped LoggerInterface: stamps channel, runs processors, delegates
    LegacyLoggerHandler.php      -- adapts LoggerInterface to HandlerInterface (internal)
    NullLogger.php               -- no-op for testing (widely used)
    Handler/
        HandlerInterface.php     -- handle(LogRecord): void
        ErrorLogHandler.php      -- error_log() with formatter + minimumLevel
        FileHandler.php          -- append to file with LOCK_EX
        StackHandler.php         -- fan-out, best-effort per handler
        NullHandler.php          -- discard all records
        StreamHandler.php        -- write to php://stderr or stream resource
    Formatter/
        FormatterInterface.php   -- format(LogRecord): string
        TextFormatter.php        -- [timestamp] [level] [channel] message {context}
        JsonFormatter.php        -- one JSON object per line
    Processor/
        ProcessorInterface.php   -- process(LogRecord): LogRecord (immutable enrichment)
        RequestIdProcessor.php   -- adds request_id (UUID hex) to context
        HostnameProcessor.php    -- adds hostname to context
        MemoryUsageProcessor.php -- adds memory_peak_mb to context
RateLimit/
    RateLimiterInterface.php     -- attempt(key, max, window): {allowed, remaining, retryAfter}
    InMemoryRateLimiter.php      -- sliding-window in-memory implementation
Asset/
    AssetManagerInterface.php    -- url(path, bundle): string
    ViteAssetManager.php         -- reads Vite manifest.json for hashed URLs
    TenantAssetResolver.php      -- tenant-specific asset path resolution
Http/
    ControllerDispatcher.php     -- routes controller names to handlers
    CorsHandler.php              -- CORS preflight and header resolution
    ResponseSender.php           -- sends Symfony Response to client
Diagnostic/
    DiagnosticCode.php           -- string-backed enum of operator error codes
    DiagnosticEntry.php          -- structured diagnostic log entry
    DiagnosticEmitter.php        -- emits structured JSON diagnostic entries
    HealthCheckerInterface.php   -- health check contract
    HealthChecker.php            -- boot/runtime/ingestion health checks
    HealthCheckResult.php        -- pass/warn/fail result value object
    BootDiagnosticReport.php     -- entity type registry snapshot
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
DBALDatabase.php                 -- implements DatabaseInterface, wraps Doctrine DBAL Connection
SelectInterface.php              -- fluent select builder
InsertInterface.php              -- fluent insert builder
UpdateInterface.php              -- fluent update builder
DeleteInterface.php              -- fluent delete builder
SchemaInterface.php              -- DDL operations (createTable, addField, etc.)
TransactionInterface.php         -- commit/rollBack
DBALTransaction.php              -- DBAL transaction wrapper
Query/
    DBALSelect.php               -- SELECT with joins, conditions, ordering, pagination
    DBALInsert.php               -- INSERT with field inference from values
    DBALUpdate.php               -- UPDATE with conditions
    DBALDelete.php               -- DELETE with conditions
Schema/
    DBALSchema.php               -- DDL implementation via Doctrine DBAL
```

### packages/http-client/src/

```
HttpClientInterface.php          -- request/get/post contract
HttpResponse.php                 -- readonly DTO: statusCode, body, headers, json(), isSuccess()
StreamHttpClient.php             -- file_get_contents + stream context implementation
HttpRequestException.php         -- thrown on request failure
```

### packages/queue/src/

```
QueueInterface.php               -- push/pop/acknowledge contract
DbalQueue.php                    -- DBAL-backed persistent queue
InMemoryQueue.php                -- in-memory queue for testing
MessageBusQueue.php              -- Symfony Messenger bridge
SyncQueue.php                    -- immediate synchronous execution
Job.php                          -- job value object
Worker/
    Worker.php                   -- processes jobs from queue
    WorkerOptions.php            -- max jobs, memory limit, sleep, timeout
Transport/
    TransportInterface.php       -- job serialization/deserialization
    DbalTransport.php            -- DBAL-backed transport
    InMemoryTransport.php        -- in-memory transport for testing
Handler/
    HandlerInterface.php         -- job handler contract
    JobHandler.php               -- default handler dispatch
Message/
    EntityMessage.php            -- entity lifecycle async message
    ConfigMessage.php            -- config change message
    GenericMessage.php           -- arbitrary payload message
Storage/
    DatabaseFailedJobRepository.php  -- DBAL-backed failed job store
    InMemoryFailedJobRepository.php  -- in-memory failed job store
FailedJobRepository.php          -- failed job base class
FailedJobRepositoryInterface.php -- failed job tracking contract
QueueServiceProvider.php         -- registers queue services
AttributeGuard.php               -- enforces job attributes at runtime
BatchedJobs.php                  -- parallel job group
ChainedJobs.php                  -- sequential job chain
Attribute/
    OnQueue.php                  -- #[OnQueue('name')] route to specific queue
    RateLimited.php              -- #[RateLimited] rate-limit job execution
    UniqueJob.php                -- #[UniqueJob] prevent duplicates
Migration/
    CreateQueueTables.php        -- creates queue_jobs + failed_jobs tables
```
