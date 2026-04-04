# Entity System

Subsystem specification for the Waaseyaa entity, entity-storage, field, and config packages. Covers entity interfaces, storage implementations, query building, field definitions, config entities, and lifecycle events.

**Internal interfaces:** `ComputedFieldInterface` (`packages/field/src/`) is `@internal` — it is an implementation detail for computed fields, not a consumer contract.

## Packages

| Package | Path | Namespace | Purpose |
|---------|------|-----------|---------|
| entity | `packages/entity/` | `Waaseyaa\Entity\` | Interfaces, base classes, entity type definitions, events. No storage. |
| entity-storage | `packages/entity-storage/` | `Waaseyaa\EntityStorage\` | SQL storage, schema handler, query builder, repository, unit of work. |
| field | `packages/field/` | `Waaseyaa\Field\` | Field type plugins, field definitions, field item lists. |
| config | `packages/config/` | `Waaseyaa\Config\` | Config objects, config factory, import/export, storage backends. |

## Core Interfaces (packages/entity/src/)

### EntityInterface

File: `packages/entity/src/EntityInterface.php`

```php
interface EntityInterface
{
    public function id(): int|string|null;
    public function uuid(): string;
    public function label(): string;
    public function getEntityTypeId(): string;
    public function bundle(): string;
    public function isNew(): bool;
    public function toArray(): array;
    public function language(): string;
}
```

### FieldableInterface

File: `packages/entity/src/FieldableInterface.php`

```php
interface FieldableInterface
{
    public function hasField(string $name): bool;
    public function get(string $name): mixed;
    public function set(string $name, mixed $value): static;
    public function getFieldDefinitions(): array; // array<string, mixed>
}
```

### ContentEntityInterface

File: `packages/entity/src/ContentEntityInterface.php`

Extends `EntityInterface` and `FieldableInterface`. No additional methods.

### ConfigEntityInterface

File: `packages/entity/src/ConfigEntityInterface.php`

```php
interface ConfigEntityInterface extends EntityInterface
{
    public function status(): bool;
    public function enable(): static;
    public function disable(): static;
    public function getDependencies(): array; // array<string, string[]>
    public function toConfig(): array;
}
```

### EntityTypeInterface

File: `packages/entity/src/EntityTypeInterface.php`

```php
interface EntityTypeInterface
{
    public function id(): string;
    public function getLabel(): string;
    public function getClass(): string;                     // class-string<EntityInterface>
    public function getStorageClass(): string;              // class-string<EntityStorageInterface>
    public function getKeys(): array;                       // array<string, string>
    public function isRevisionable(): bool;
    public function getRevisionDefault(): bool;
    public function isTranslatable(): bool;
    public function getBundleEntityType(): ?string;
    public function getConstraints(): array;                // array<string, mixed>
    public function getFieldDefinitions(): array;           // array<string, array<string, mixed>>
    public function getGroup(): ?string;                    // admin sidebar group key
    public function getDescription(): ?string;              // human-readable description
}
```

### EntityTypeManagerInterface

File: `packages/entity/src/EntityTypeManagerInterface.php`

```php
interface EntityTypeManagerInterface
{
    public function getDefinition(string $entityTypeId): EntityTypeInterface;
    public function getDefinitions(): array;        // array<string, EntityTypeInterface>
    public function hasDefinition(string $entityTypeId): bool;
    public function getStorage(string $entityTypeId): EntityStorageInterface;
}
```

### EntityStorageInterface

File: `packages/entity/src/Storage/EntityStorageInterface.php`

```php
interface EntityStorageInterface
{
    public function create(array $values = []): EntityInterface;
    public function load(int|string $id): ?EntityInterface;
    public function loadByKey(string $key, mixed $value): ?EntityInterface;
    public function loadMultiple(array $ids = []): array;   // array<int|string, EntityInterface>
    public function save(EntityInterface $entity): int;     // SAVED_NEW (1) or SAVED_UPDATED (2)
    public function delete(array $entities): void;          // EntityInterface[]
    public function getQuery(): EntityQueryInterface;
    public function getEntityTypeId(): string;
}
```

`loadByKey()` is a convenience method encapsulating the common query+load pattern: query by an arbitrary unique key, limit to 1, load the result. Equivalent to `$ids = $storage->getQuery()->condition($key, $value)->range(0, 1)->execute(); return $ids ? $storage->load(reset($ids)) : null;`

### EntityQueryInterface

File: `packages/entity/src/Storage/EntityQueryInterface.php`

```php
interface EntityQueryInterface
{
    public function condition(string $field, mixed $value, string $operator = '='): static;
    public function exists(string $field): static;
    public function notExists(string $field): static;
    public function sort(string $field, string $direction = 'ASC'): static;
    public function range(int $offset, int $limit): static;
    public function count(): static;
    public function accessCheck(bool $check = true): static;
    public function execute(): array;  // array<int|string>
}
```

### RevisionableStorageInterface

File: `packages/entity/src/Storage/RevisionableStorageInterface.php`

Extends `EntityStorageInterface`. Adds:

```php
public function loadRevision(int|string $revisionId): ?EntityInterface;
public function loadMultipleRevisions(array $ids): array;
public function deleteRevision(int|string $revisionId): void;
public function getLatestRevisionId(int|string $entityId): int|string|null;
```

### EntityRepositoryInterface

File: `packages/entity/src/Repository/EntityRepositoryInterface.php`

Higher-level API with language fallback:

```php
interface EntityRepositoryInterface
{
    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface;
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array;
    public function save(EntityInterface $entity, bool $validate = true): int;
    public function delete(EntityInterface $entity): void;
    public function exists(string $id): bool;
    public function count(array $criteria = []): int;
    public function loadRevision(string $entityId, int $revisionId): ?EntityInterface;
    public function rollback(string $entityId, int $targetRevisionId): EntityInterface;
    public function saveMany(array $entities, bool $validate = true): array;   // int[] (SAVED_NEW/SAVED_UPDATED)
    public function deleteMany(array $entities): int;
}
```

`save()` accepts `bool $validate = true`. When true and an `EntityValidator` is injected, validates against `EntityType::getConstraints()` before persisting. Throws `EntityValidationException` on failure.

`saveMany()`/`deleteMany()` wrap all operations in a `UnitOfWork` transaction. Events are buffered and dispatched only after successful commit. Requires `$database` to be non-null (throws `\LogicException` otherwise).

### EntityConstants

File: `packages/entity/src/EntityConstants.php`

```php
final class EntityConstants
{
    public const SAVED_NEW = 1;
    public const SAVED_UPDATED = 2;
}
```

## EntityType Definition

File: `packages/entity/src/EntityType.php`

`EntityType` is a `final readonly class` implementing `EntityTypeInterface`. Constructed with named parameters:

```php
new EntityType(
    id: 'node',
    label: 'Content',
    class: Node::class,
    storageClass: SqlEntityStorage::class,
    keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
    revisionable: false,
    revisionDefault: false,
    translatable: false,
    bundleEntityType: 'node_type',
    constraints: [],
    fieldDefinitions: [],
    group: null,
    description: null,
);
```

New parameters added to `EntityType`:
- `revisionDefault: bool` -- whether new revisions are created by default on save (when `revisionable` is true)
- `fieldDefinitions: array` -- field definitions keyed by field name, used by `SchemaController`, `GraphQL`, and `EntityTypeBuilder`
- `group: ?string` -- admin sidebar group key (e.g., `'content'`, `'taxonomy'`) for catalog grouping
- `description: ?string` -- human-readable description of the entity type, displayed in admin catalog

Entity types are registered explicitly with `EntityTypeManager::registerEntityType()`. The manager throws `\InvalidArgumentException` if a type ID is already registered.

### EntityTypeAttribute (future plugin discovery)

File: `packages/entity/src/Attribute/EntityTypeAttribute.php`

PHP attribute `#[EntityTypeAttribute(...)]` for class-level discovery. Extends `WaaseyaaPlugin`. Not currently used for registration (types are registered manually) but wired for future plugin-based discovery.

## Entity Lifecycle

### Create

1. Call `$storage->create(['title' => 'Hello'])` or `new Node(['title' => 'Hello'])`
2. `EntityBase::__construct()` auto-generates UUID via `Uuid::v4()->toRfc4122()` if not provided
3. Entity starts with `isNew() === true` (id is null)

### Save (via EntityRepository)

The `EntityRepository::save()` pipeline (used for all high-level persistence):

1. Validates entity against `EntityType::getConstraints()` if `$validate === true` and `EntityValidator` is injected
2. Calls `$entity->preSave($isNew)` lifecycle hook (if entity extends `EntityBase`)
3. Dispatches `EntityEvents::PRE_SAVE` event (via `EntityEventFactoryInterface`)
4. Writes to storage driver (`$driver->write()`)
5. Calls `$entity->enforceIsNew(false)` for new entities
6. Dispatches `EntityEvents::POST_SAVE` event
7. Calls `$entity->postSave($isNew)` lifecycle hook (if entity extends `EntityBase`)
8. Returns `EntityConstants::SAVED_NEW` (1) or `SAVED_UPDATED` (2)

### Save (via SqlEntityStorage — low-level)

1. `SqlEntityStorage::save()` detects `isNew() === true`
2. Calls `splitForStorage()` to separate schema columns from `_data` JSON blob
3. Dispatches `EntityEvents::PRE_SAVE` event
4. Runs `INSERT` via `$database->insert()`, omitting null id key (auto-increment)
5. Sets the generated ID on entity via `$entity->set($idKey, (int)$id)`
6. Calls `$entity->enforceIsNew(false)`
7. Dispatches `EntityEvents::POST_SAVE` event
8. Returns `EntityConstants::SAVED_NEW` (1)

### Delete (via EntityRepository)

1. Calls `$entity->preDelete()` lifecycle hook (if entity extends `EntityBase`)
2. Dispatches `EntityEvents::PRE_DELETE` event
3. Removes from storage driver (`$driver->remove()`)
4. Dispatches `EntityEvents::POST_DELETE` event
5. Calls `$entity->postDelete()` lifecycle hook (if entity extends `EntityBase`)

### Load

1. `SqlEntityStorage::load($id)` executes `SELECT` on entity table
2. `mapRowToEntity()` casts numeric IDs to `int`
3. Merges `_data` JSON blob back into the values array
4. Instantiates entity via `instantiateEntity()` (adapts to constructor signature)
5. Calls `$entity->enforceIsNew(false)` on loaded entities

## Storage Layer

### SqlEntityStorage

File: `packages/entity-storage/src/SqlEntityStorage.php`
Namespace: `Waaseyaa\EntityStorage`
Class: `final class SqlEntityStorage implements EntityStorageInterface`

Constructor:
```php
public function __construct(
    private readonly EntityTypeInterface $entityType,
    private readonly DatabaseInterface $database,
    private readonly EventDispatcherInterface $eventDispatcher,
    ?LoggerInterface $logger = null,
    ?EntityEventFactoryInterface $eventFactory = null,
)
```

`$logger` defaults to `NullLogger`. `$eventFactory` defaults to `DefaultEntityEventFactory`. The logger is from `Waaseyaa\Foundation\Log\LoggerInterface` (not PSR-3).

**`loadByKey()`**: Implements `EntityStorageInterface::loadByKey()` using the query+load pattern.

**Automatic timestamp population**: `SqlEntityStorage::save()` calls `populateTimestamps()` which inspects `EntityType::getFieldDefinitions()` for fields with `'type' => 'timestamp'`. On new entities, sets `created` to `time()` if not already set. Always updates `changed` to `time()`.

Table name derived from `$entityType->id()` (e.g., entity type `'node'` maps to table `node`).

### _data JSON Blob

`SqlSchemaHandler::buildTableSpec()` adds a `_data TEXT NOT NULL DEFAULT '{}'` column to every entity table.

`SqlEntityStorage::splitForStorage()`:
- For each value key, checks if a matching column exists in the table (via `SchemaInterface::fieldExists()`, results cached in `$columnCache`)
- Values matching real columns go into `$dbValues`
- All other values go into `$extraData`, which is JSON-encoded into `_data`

`SqlEntityStorage::mapRowToEntity()`:
- If `$row['_data']` exists, `json_decode()` it and merge back into `$row`
- Remove the `_data` key from the row before entity creation

### SqlSchemaHandler

File: `packages/entity-storage/src/SqlSchemaHandler.php`
Class: `final class SqlSchemaHandler`

Constructor: `(EntityTypeInterface $entityType, DatabaseInterface $database)`

Key methods:
- `ensureTable(): void` -- creates entity table if it does not exist
- `ensureTranslationTable(array $translatableFieldSchemas = []): void` -- creates `{type}_translations` table
- `addFieldColumns(array $fieldSchemas): void` -- adds columns to existing entity table
- `addTranslationFieldColumns(array $fieldSchemas): void` -- adds columns to translation table
- `getTableName(): string` -- returns entity type id
- `getTranslationTableName(): string` -- returns `{type}_translations`

Default table schema (from `buildTableSpec()`):
- `{idKey}` -- `serial NOT NULL` (auto-increment primary key)
- `{uuidKey}` -- `varchar(128) NOT NULL DEFAULT ''`
- `{bundleKey}` -- `varchar(128) NOT NULL DEFAULT ''`
- `{labelKey}` -- `varchar(255) NOT NULL DEFAULT ''`
- `{langcodeKey}` -- `varchar(12) NOT NULL DEFAULT 'en'`
- `_data` -- `text NOT NULL DEFAULT '{}'`
- Primary key on `{idKey}`, unique index on UUID, index on bundle

### EntityStorageFactory

File: `packages/entity-storage/src/EntityStorageFactory.php`
Class: `final class EntityStorageFactory`

Constructor: `(DatabaseInterface $database, EventDispatcherInterface $eventDispatcher, ?EntityEventFactoryInterface $eventFactory = null)`

`getStorage(EntityTypeInterface $entityType): SqlEntityStorage` -- creates and caches SqlEntityStorage instances by entity type ID. Propagates `$eventFactory` to each SqlEntityStorage instance.

### EntityRepository

File: `packages/entity-storage/src/EntityRepository.php`
Class: `final class EntityRepository implements EntityRepositoryInterface`

Constructor:
```php
public function __construct(
    private readonly EntityTypeInterface $entityType,
    private readonly EntityStorageDriverInterface $driver,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ?RevisionableStorageDriver $revisionDriver = null,
    private readonly ?DatabaseInterface $database = null,
    ?EntityEventFactoryInterface $eventFactory = null,
    private readonly ?EntityValidator $validator = null,
)
```

Higher-level layer that handles:
- Entity hydration (`hydrate()` method with `_data` merge and constructor adaptation)
- Language fallback via `setFallbackChain(string[] $chain)` (default: `['en']`)
- Event dispatch via `EntityEventFactoryInterface` (defaults to `DefaultEntityEventFactory`)
- Pre-save validation via `EntityValidator` (when injected and `validate: true`)
- Entity lifecycle hooks (`preSave`, `postSave`, `preDelete`, `postDelete` on `EntityBase`)
- Batch operations via `saveMany()`/`deleteMany()` with `UnitOfWork` transaction wrapping
- Revision management via `loadRevision()` and `rollback()`
- Automatic revision creation based on `EntityType::getRevisionDefault()` and per-entity `isNewRevision()` override (via `shouldCreateRevision()` internal method)

### UnitOfWork

File: `packages/entity-storage/src/UnitOfWork.php`
Class: `final class UnitOfWork`

Constructor: `(DatabaseInterface $database, EventDispatcherInterface $eventDispatcher)`

`transaction(\Closure $callback): mixed` -- wraps callback in DB transaction, buffers events during transaction, dispatches after commit. On failure, discards events and rolls back.

`bufferEvent(Event $event, string $eventName): void` -- buffers events inside transaction, dispatches immediately outside.

### Storage Drivers

#### EntityStorageDriverInterface

File: `packages/entity-storage/src/Driver/EntityStorageDriverInterface.php`

Low-level I/O SPI without entity hydration or events:

```php
public function read(string $entityType, string $id, ?string $langcode = null): ?array;
public function write(string $entityType, string $id, array $values): void;
public function remove(string $entityType, string $id): void;
public function exists(string $entityType, string $id): bool;
public function count(string $entityType, array $criteria = []): int;
public function findBy(string $entityType, array $criteria = [], ?array $orderBy = null, ?int $limit = null): array;
```

#### SqlStorageDriver

File: `packages/entity-storage/src/Driver/SqlStorageDriver.php`
Constructor: `(ConnectionResolverInterface $connectionResolver, string $idKey = 'id')`

Handles raw SQL I/O. Supports translation tables: if `{entityType}_translations` table exists, `read()` merges translation data over base values.

#### InMemoryStorageDriver

File: `packages/entity-storage/src/Driver/InMemoryStorageDriver.php`

In-memory storage for testing. Additional methods beyond the interface:
- `writeTranslation(string $entityType, string $id, string $langcode, array $values): void`
- `deleteTranslation(string $entityType, string $id, string $langcode): void`
- `getAvailableLanguages(string $entityType, string $id): string[]`
- `clear(): void`

### Connection Resolution

#### ConnectionResolverInterface

File: `packages/entity-storage/src/Connection/ConnectionResolverInterface.php`

```php
public function connection(?string $name = null): DatabaseInterface;
public function getDefaultConnectionName(): string;
```

Multi-tenancy seam. `SingleConnectionResolver` always returns the same connection.

## Constructor Patterns

### Base class constructor (EntityBase)

```php
public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [])
```

Accepts `$entityTypeId` and `$entityKeys` parameters. Used when storage instantiates generic entities.

### Subclass constructor (User, Node)

Subclasses hardcode their entity type ID and keys. Only accept `(array $values)`:

```php
// User: packages/user/src/User.php
final class User extends ContentEntityBase implements AccountInterface
{
    private const ENTITY_TYPE_ID = 'user';
    private const ENTITY_KEYS = ['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name'];

    public function __construct(array $values = [])
    {
        parent::__construct($values, self::ENTITY_TYPE_ID, self::ENTITY_KEYS);
    }
}

// Node: packages/node/src/Node.php
final class Node extends ContentEntityBase
{
    protected string $entityTypeId = 'node';
    protected array $entityKeys = ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

### SqlEntityStorage constructor detection

`SqlEntityStorage::instantiateEntity()` uses reflection to detect the constructor shape:
1. Reflects the entity class constructor
2. Checks if a parameter named `'entityTypeId'` exists
3. If yes, passes `(values: $values, entityTypeId: ..., entityKeys: ...)`
4. If no, passes `(values: $values)` only

This is critical: entity subclasses like User and Node only accept `(array $values)` and hardcode their type/keys.

### enforceIsNew()

`EntityBase::enforceIsNew(bool $value = true): static`

When creating entities with pre-set IDs (e.g., `new User(['uid' => 2])`), call `$entity->enforceIsNew()` before `save()`. Without this, `isNew()` returns false (because `id()` is not null), and SqlEntityStorage performs UPDATE instead of INSERT, silently affecting 0 rows.

`isNew()` returns `$this->enforceIsNew || $this->id() === null`.

## Entity Keys

Entity keys map logical names to actual column/property names. Defined in `EntityType::$keys`:

| Key | Purpose | Default fallback |
|-----|---------|-----------------|
| `id` | Primary key column | `'id'` |
| `uuid` | UUID column | `'uuid'` |
| `label` | Human-readable label | `'label'` |
| `bundle` | Bundle/type discriminator | `'bundle'` |
| `langcode` | Language code | `'langcode'` |
| `revision` | Revision ID (revisionable types) | -- |

Examples:
- Node: `['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type']`
- User: `['id' => 'uid', 'uuid' => 'uuid', 'label' => 'name']`

`EntityBase` resolves values via keys: `$this->values[$this->entityKeys['id'] ?? 'id']`.
Bundle defaults to `$this->entityTypeId` when no bundle value exists.

## Query Pipeline

### SqlEntityQuery

File: `packages/entity-storage/src/SqlEntityQuery.php`
Class: `final class SqlEntityQuery implements EntityQueryInterface`

Constructor: `(EntityTypeInterface $entityType, DatabaseInterface $database)`

Table and ID key derived from entity type. Fluent API builds conditions, sorts, and ranges.

**JSON field resolution**: `resolveField()` checks if a field exists as a real table column (cached in `$columnCache`). Fields stored in the `_data` JSON blob are wrapped in `json_extract()` so they can be used in conditions, sorts, and counts transparently.

Supported operators:
- Standard SQL: `=`, `<>`, `<`, `>`, `<=`, `>=`, `IN`, `NOT IN`, `LIKE`, `NOT LIKE`
- `IS NULL` / `IS NOT NULL` -- via `exists()` and `notExists()`
- `CONTAINS` -- translated to `LIKE '%escaped_value%'`
- `STARTS_WITH` -- translated to `LIKE 'escaped_value%'`

The `IN` operator coerces non-array values to a single-element array (`is_array($value) ? $value : [$value]`), making it safe to pass either a single value or an array.

LIKE wildcard escaping: `str_replace(['%', '_'], ['\\%', '\\_'], $value)` before wrapping with `%`.

Count mode: `count()` switches `execute()` to return `[(int) $count]` instead of IDs.

`accessCheck()` is a no-op in v0.1.0.

Usage pattern:
```php
$ids = $storage->getQuery()
    ->condition('status', 1)
    ->condition('type', 'article')
    ->sort('created', 'DESC')
    ->range(0, 10)
    ->execute();

$entities = $storage->loadMultiple($ids);
```

## Events

### EntityEvent

File: `packages/entity/src/Event/EntityEvent.php`

```php
class EntityEvent extends Event
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly ?EntityInterface $originalEntity = null,
    ) {}
}
```

Properties are **public readonly**. Access as `$event->entity` and `$event->originalEntity`. There are NO getter methods. Common mistake: `$event->getEntity()` does not exist.

### EntityEvents (enum)

File: `packages/entity/src/Event/EntityEvents.php`

```php
enum EntityEvents: string
{
    case PRE_SAVE = 'waaseyaa.entity.pre_save';
    case POST_SAVE = 'waaseyaa.entity.post_save';
    case PRE_DELETE = 'waaseyaa.entity.pre_delete';
    case POST_DELETE = 'waaseyaa.entity.post_delete';
    case POST_LOAD = 'waaseyaa.entity.post_load';
    case PRE_CREATE = 'waaseyaa.entity.pre_create';
    case REVISION_CREATED = 'waaseyaa.entity.revision_created';
    case REVISION_REVERTED = 'waaseyaa.entity.revision_reverted';
}
```

Dispatched with `$eventDispatcher->dispatch(new EntityEvent($entity), EntityEvents::PRE_SAVE->value)`.
Note: use `->value` to get the string from the enum.

### Domain Events (EntitySaved, EntityDeleted)

File: `packages/entity/src/Event/EntitySaved.php` -- extends `DomainEvent`, contains `$changedFields`, `$isNew`
File: `packages/entity/src/Event/EntityDeleted.php` -- extends `DomainEvent`

These are separate from `EntityEvent`. They carry aggregate metadata (`aggregateType`, `aggregateId`, `tenantId`, `actorId`).

### EntityEventFactoryInterface

File: `packages/entity/src/Event/EntityEventFactoryInterface.php`

```php
interface EntityEventFactoryInterface
{
    public function create(
        EntityInterface $entity,
        ?EntityInterface $originalEntity = null,
    ): EntityEvent;
}
```

`DefaultEntityEventFactory` (`packages/entity/src/Event/DefaultEntityEventFactory.php`) is the default implementation — simply returns `new EntityEvent($entity, $originalEntity)`. Applications can provide custom factories to attach additional context (e.g., tenant ID, actor ID) to entity events.

`EntityRepository` accepts `?EntityEventFactoryInterface` in its constructor (defaults to `DefaultEntityEventFactory`). `EntityStorageFactory` propagates the factory to storage instances.

### Entity Validation

File: `packages/entity/src/Validation/EntityValidator.php`

```php
final class EntityValidator
{
    public function __construct(private readonly ValidatorInterface $validator);

    public function validate(EntityInterface $entity, array $constraints = []): ConstraintViolationListInterface;
}
```

Validates entity field values against per-field Symfony Validator constraints. `$constraints` is keyed by field name. For `FieldableInterface` entities, uses `get($field)` for proper resolution; otherwise falls back to `toArray()`. Violations are remapped to include the field path.

File: `packages/entity/src/Validation/EntityValidationException.php`

```php
final class EntityValidationException extends \RuntimeException
{
    public function __construct(
        public readonly ConstraintViolationListInterface $violations,
        string $message = 'Entity validation failed.',
    );
}
```

Thrown by `EntityRepository::save()` when validation fails. The `$violations` property provides programmatic access to all constraint violations.

### Entity Lifecycle Hooks

File: `packages/entity/src/EntityBase.php`

`EntityBase` provides four no-op lifecycle hooks that subclasses can override:

```php
public function preSave(bool $isNew): void {}
public function postSave(bool $isNew): void {}
public function preDelete(): void {}
public function postDelete(): void {}
```

Called by `EntityRepository` (not `SqlEntityStorage`). Execution order within `save()`:

```
preSave($isNew) → PRE_SAVE event → persist → POST_SAVE event → postSave($isNew)
```

Execution order within `delete()`:

```
preDelete() → PRE_DELETE event → remove → POST_DELETE event → postDelete()
```

Hooks are only called when the entity is an instance of `EntityBase`. They run inside `UnitOfWork` transactions for batch operations (`saveMany`/`deleteMany`).

## Configuration Entities

### ConfigEntityBase

File: `packages/entity/src/ConfigEntityBase.php`
Class: `abstract class ConfigEntityBase extends EntityBase implements ConfigEntityInterface`

Config entities differ from content entities:
- Stored as YAML via config system, not in SQL tables
- Have `status()` (enabled/disabled) and `getDependencies()`
- `toConfig()` returns array suitable for YAML serialization
- Dependencies keyed by type: `['package' => [...], 'config' => [...], 'content' => [...]]`

## Config System (packages/config/src/)

### ConfigInterface

File: `packages/config/src/ConfigInterface.php`

```php
interface ConfigInterface
{
    public function getName(): string;
    public function get(string $key = ''): mixed;      // dot-notation traversal
    public function set(string $key, mixed $value): static;
    public function clear(string $key): static;
    public function delete(): static;
    public function save(): static;
    public function isNew(): bool;
    public function getRawData(): array;
}
```

### Config

File: `packages/config/src/Config.php`
Class: `final class Config implements ConfigInterface`

Supports dot-notation for nested values: `$config->get('page.front')`.
Mutable vs immutable: constructor accepts `bool $immutable`. Immutable configs throw `ImmutableConfigException` on any write operation.

### ConfigFactoryInterface

File: `packages/config/src/ConfigFactoryInterface.php`

```php
interface ConfigFactoryInterface
{
    public function get(string $name): ConfigInterface;           // returns immutable
    public function getEditable(string $name): ConfigInterface;   // returns mutable
    public function loadMultiple(array $names): array;
    public function rename(string $oldName, string $newName): static;
    public function listAll(string $prefix = ''): array;
}
```

`get()` returns cached immutable Config. `getEditable()` always creates a new mutable Config wrapped in EventAwareStorage.

### ConfigManagerInterface

File: `packages/config/src/ConfigManagerInterface.php`

```php
interface ConfigManagerInterface
{
    public function getActiveStorage(): StorageInterface;
    public function getSyncStorage(): StorageInterface;
    public function import(): ConfigImportResult;
    public function export(): void;
    public function diff(string $configName): array;
}
```

`import()` compares sync to active storage, creates/updates/deletes as needed, returns `ConfigImportResult`.
`export()` clears sync storage, copies all active configs.

`ConfigManager` dispatches `ConfigEvents::IMPORT` through `Symfony\Contracts\EventDispatcher\EventDispatcherInterface`. Callers may provide either the concrete Symfony dispatcher or any contracts-compatible dispatcher implementation; the manager only relies on `dispatch()`.

### Config StorageInterface

File: `packages/config/src/StorageInterface.php`

```php
interface StorageInterface
{
    public function exists(string $name): bool;
    public function read(string $name): array|false;
    public function readMultiple(array $names): array;
    public function write(string $name, array $data): bool;
    public function delete(string $name): bool;
    public function rename(string $name, string $newName): bool;
    public function listAll(string $prefix = ''): array;
    public function deleteAll(string $prefix = ''): bool;
    public function createCollection(string $collection): static;
    public function getCollectionName(): string;
    public function getAllCollectionNames(): array;
}
```

Implementations: `Storage\MemoryStorage`, `Storage\FileStorage`.

### Config Events

File: `packages/config/src/Event/ConfigEvents.php`

```php
enum ConfigEvents: string
{
    case PRE_SAVE = 'waaseyaa.config.pre_save';
    case POST_SAVE = 'waaseyaa.config.post_save';
    case PRE_DELETE = 'waaseyaa.config.pre_delete';
    case POST_DELETE = 'waaseyaa.config.post_delete';
    case IMPORT = 'waaseyaa.config.import';
}
```

## Field System (packages/field/src/)

### FieldTypeInterface

File: `packages/field/src/FieldTypeInterface.php`

```php
interface FieldTypeInterface extends PluginInspectionInterface
{
    public static function schema(): array;           // array<string, array{type: string, ...}>
    public static function defaultSettings(): array;
    public static function defaultValue(): mixed;
    public static function jsonSchema(): array;
}
```

### FieldDefinition

File: `packages/field/src/FieldDefinition.php`
Class: `final readonly class FieldDefinition implements FieldDefinitionInterface`

```php
public function __construct(
    private string $name,
    private string $type,
    private int $cardinality = 1,
    private array $settings = [],
    private string $targetEntityTypeId = '',
    private ?string $targetBundle = null,
    private bool $translatable = false,
    private bool $revisionable = false,
    private mixed $defaultValue = null,
    private string $label = '',
    private string $description = '',
    private bool $required = false,
    private bool $readOnly = false,
    private array $constraints = [],    // Constraint[]
)
```

`toJsonSchema()` maps types: `string` -> `{'type': 'string'}`, `integer` -> `{'type': 'integer'}`, `boolean` -> `{'type': 'boolean'}`, `float` -> `{'type': 'number'}`, `text` -> object with `value`/`format`, `entity_reference` -> object with `target_id`/`target_type`. Wraps in `{'type': 'array', 'items': ...}` when `isMultiple()`.

### FieldItemBase

File: `packages/field/src/FieldItemBase.php`
Class: `abstract class FieldItemBase extends PluginBase implements FieldItemInterface, FieldTypeInterface`

Subclasses must implement:
- `static function propertyDefinitions(): array` -- e.g., `['value' => 'string']`
- `static function mainPropertyName(): string` -- e.g., `'value'`
- `static function schema(): array` -- SQL column definitions
- `static function jsonSchema(): array` -- JSON Schema representation

### Built-in Field Items

| Class | Path | ID | Properties | Main Property |
|-------|------|----|------------|---------------|
| `StringItem` | `packages/field/src/Item/StringItem.php` | `string` | `value: string` | `value` |
| `TextItem` | `packages/field/src/Item/TextItem.php` | `text` | `value: string, format: string` | `value` |
| `IntegerItem` | `packages/field/src/Item/IntegerItem.php` | `integer` | `value: integer` | `value` |
| `FloatItem` | `packages/field/src/Item/FloatItem.php` | `float` | `value: float` | `value` |
| `BooleanItem` | `packages/field/src/Item/BooleanItem.php` | `boolean` | `value: boolean` | `value` |
| `EntityReferenceItem` | `packages/field/src/Item/EntityReferenceItem.php` | `entity_reference` | `target_id: integer, target_type: string` | `target_id` |

### FieldItemList

File: `packages/field/src/FieldItemList.php`
Class: `class FieldItemList implements FieldItemListInterface, \IteratorAggregate, \Countable`

Contains `FieldItemInterface[]` items. Supports `__get($name)` to access first item's property value (e.g., `$list->value`).

### FieldTypeManager

File: `packages/field/src/FieldTypeManager.php`
Class: `class FieldTypeManager extends DefaultPluginManager implements FieldTypeManagerInterface`

Constructor: `(array $directories = [], ?CacheBackendInterface $cache = null)`

Uses `AttributeDiscovery` with `FieldType::class` attribute. Plugin discovery scans directories for `#[FieldType(...)]` attributes.

Additional methods:
- `getDefaultSettings(string $fieldType): array`
- `getColumns(string $fieldType): array` -- returns `schema()` from the field type class

### FieldType Attribute

File: `packages/field/src/Attribute/FieldType.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS)]
class FieldType extends WaaseyaaPlugin
{
    public function __construct(
        string $id,
        string $label = '',
        string $description = '',
        string $package = '',
        public readonly string $category = 'general',
        public readonly int $defaultCardinality = 1,
        public readonly string $defaultWidget = '',
        public readonly string $defaultFormatter = '',
    )
}
```

## File Reference

### packages/entity/src/
- `EntityInterface.php` -- core entity contract
- `EntityBase.php` -- abstract base with values array, UUID generation, enforceIsNew, lifecycle hooks (preSave/postSave/preDelete/postDelete)
- `ContentEntityInterface.php` -- extends EntityInterface + FieldableInterface
- `ContentEntityBase.php` -- abstract base for content entities (fieldable)
- `ConfigEntityInterface.php` -- config entity contract with status/dependencies
- `ConfigEntityBase.php` -- abstract base for config entities
- `FieldableInterface.php` -- hasField, get, set, getFieldDefinitions
- `EntityType.php` -- final readonly value object for entity type definitions
- `EntityTypeInterface.php` -- entity type definition contract
- `EntityTypeManager.php` -- registry with storage factory support
- `EntityTypeManagerInterface.php` -- manager contract
- `EntityConstants.php` -- SAVED_NEW (1), SAVED_UPDATED (2)
- `TranslatableInterface.php` -- translation contract
- `RevisionableInterface.php` -- revision contract
- `Attribute/EntityTypeAttribute.php` -- PHP attribute for entity type discovery
- `Event/EntityEvent.php` -- event with public readonly entity + originalEntity
- `Event/EntityEvents.php` -- string-backed enum of event names (includes REVISION_CREATED, REVISION_REVERTED)
- `Event/EntityEventFactoryInterface.php` -- factory interface for creating EntityEvent instances
- `Event/EntitySaved.php` -- domain event for entity save
- `Event/EntityDeleted.php` -- domain event for entity delete
- `Validation/EntityValidator.php` -- per-field validation via Symfony Validator
- `Validation/EntityValidationException.php` -- exception carrying ConstraintViolationListInterface
- `Storage/EntityStorageInterface.php` -- storage CRUD contract
- `Storage/EntityQueryInterface.php` -- query builder contract
- `Storage/RevisionableStorageInterface.php` -- revision storage contract
- `Repository/EntityRepositoryInterface.php` -- high-level repository contract

### packages/entity-storage/src/
- `SqlEntityStorage.php` -- SQL storage with _data blob split/merge
- `SqlEntityQuery.php` -- SQL query builder with CONTAINS/STARTS_WITH operators
- `SqlSchemaHandler.php` -- table creation and schema management
- `EntityStorageFactory.php` -- factory that creates/caches SqlEntityStorage
- `EntityRepository.php` -- high-level repository with language fallback
- `UnitOfWork.php` -- transaction wrapper with event buffering
- `Driver/EntityStorageDriverInterface.php` -- low-level I/O SPI
- `Driver/SqlStorageDriver.php` -- SQL driver with translation table support
- `Driver/InMemoryStorageDriver.php` -- in-memory driver for testing
- `Connection/ConnectionResolverInterface.php` -- multi-tenancy seam
- `Connection/SingleConnectionResolver.php` -- single-tenant default

### packages/field/src/
- `FieldTypeInterface.php` -- field type plugin contract
- `FieldDefinitionInterface.php` -- field definition contract
- `FieldDefinition.php` -- final readonly field definition value object
- `FieldItemInterface.php` -- field item contract
- `FieldItemBase.php` -- abstract base for field items
- `FieldItemList.php` -- list of field items with IteratorAggregate
- `FieldItemListInterface.php` -- field item list contract
- `PropertyValue.php` -- typed data wrapper for property values
- `FieldTypeManager.php` -- plugin manager for field types
- `FieldTypeManagerInterface.php` -- field type manager contract
- `Attribute/FieldType.php` -- PHP attribute for field type discovery
- `Item/StringItem.php` -- string field type plugin
- `Item/TextItem.php` -- formatted text field type plugin
- `Item/IntegerItem.php` -- integer field type plugin
- `Item/FloatItem.php` -- float field type plugin
- `Item/BooleanItem.php` -- boolean field type plugin
- `Item/EntityReferenceItem.php` -- entity reference field type plugin

### packages/config/src/
- `ConfigInterface.php` -- config object contract with dot-notation access
- `Config.php` -- mutable/immutable config with nested value support
- `ConfigFactoryInterface.php` -- factory contract (get immutable, getEditable mutable)
- `ConfigFactory.php` -- factory with in-memory cache for immutable configs
- `ConfigManagerInterface.php` -- import/export manager contract
- `ConfigManager.php` -- sync/active storage comparison and import/export
- `StorageInterface.php` -- config storage contract (read/write/delete/list)
- `ConfigImportResult.php` -- import result value object (created/updated/deleted/errors)
- `EventAwareStorage.php` -- storage wrapper that dispatches config events
- `Event/ConfigEvent.php` -- config change event
- `Event/ConfigEvents.php` -- string-backed enum of config event names
- `Storage/MemoryStorage.php` -- in-memory config storage for testing
- `Storage/FileStorage.php` -- filesystem YAML config storage

### Test fixtures
- `packages/api/tests/Fixtures/InMemoryEntityStorage.php` -- implements EntityStorageInterface for tests
- `packages/entity-storage/tests/Fixtures/LifecycleTrackingEntity.php` -- extends `TestStorageEntity`, records lifecycle hook calls (`preSave`, `postSave`, `preDelete`, `postDelete`) into a public `$hookLog` array for verification in tests
