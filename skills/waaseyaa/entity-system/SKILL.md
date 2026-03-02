---
name: waaseyaa:entity-system
description: "Use when working with entity types, entity storage, entity queries, field definitions, field type plugins, config entities, config import/export, or files in packages/entity/, packages/entity-storage/, packages/field/, packages/config/. Covers EntityInterface, EntityBase, EntityType, EntityTypeManager, SqlEntityStorage, SqlEntityQuery, SqlSchemaHandler, EntityRepository, UnitOfWork, FieldDefinition, FieldTypeManager, FieldItemBase, ConfigFactory, ConfigManager. Also applies when touching User (packages/user/) or Node (packages/node/) entity subclasses."
---

# Entity System Specialist

## Scope

This skill covers four packages and their collaborators:

| Package | Path | Namespace |
|---------|------|-----------|
| entity | `packages/entity/` | `Waaseyaa\Entity\` |
| entity-storage | `packages/entity-storage/` | `Waaseyaa\EntityStorage\` |
| field | `packages/field/` | `Waaseyaa\Field\` |
| config | `packages/config/` | `Waaseyaa\Config\` |

Also covers entity subclasses in:
- `packages/user/src/User.php` (`Waaseyaa\User\User`)
- `packages/node/src/Node.php` (`Waaseyaa\Node\Node`)
- `packages/api/tests/Fixtures/InMemoryEntityStorage.php` (test fixture)

## Key Interfaces

### Entity Layer (`packages/entity/src/`)

**EntityInterface** -- core contract for all entities:
```php
public function id(): int|string|null;
public function uuid(): string;
public function label(): string;
public function getEntityTypeId(): string;
public function bundle(): string;
public function isNew(): bool;
public function toArray(): array;
public function language(): string;
```

**FieldableInterface** -- dynamic field access:
```php
public function hasField(string $name): bool;
public function get(string $name): mixed;
public function set(string $name, mixed $value): static;
public function getFieldDefinitions(): array;
```

**ContentEntityInterface** -- extends EntityInterface + FieldableInterface (no added methods).

**ConfigEntityInterface** -- extends EntityInterface with config concerns:
```php
public function status(): bool;
public function enable(): static;
public function disable(): static;
public function getDependencies(): array;   // array<string, string[]>
public function toConfig(): array;
```

**EntityStorageInterface** -- CRUD + query:
```php
public function create(array $values = []): EntityInterface;
public function load(int|string $id): ?EntityInterface;
public function loadMultiple(array $ids = []): array;
public function save(EntityInterface $entity): int;     // returns SAVED_NEW (1) or SAVED_UPDATED (2)
public function delete(array $entities): void;
public function getQuery(): EntityQueryInterface;
public function getEntityTypeId(): string;
```

**EntityQueryInterface** -- fluent query builder:
```php
public function condition(string $field, mixed $value, string $operator = '='): static;
public function exists(string $field): static;
public function notExists(string $field): static;
public function sort(string $field, string $direction = 'ASC'): static;
public function range(int $offset, int $limit): static;
public function count(): static;
public function accessCheck(bool $check = true): static;
public function execute(): array;  // returns entity IDs
```

**EntityTypeInterface** -- entity type definition:
```php
public function id(): string;
public function getLabel(): string;
public function getClass(): string;
public function getStorageClass(): string;
public function getKeys(): array;           // ['id' => 'nid', 'uuid' => 'uuid', ...]
public function isRevisionable(): bool;
public function isTranslatable(): bool;
public function getBundleEntityType(): ?string;
public function getConstraints(): array;
```

**EntityTypeManagerInterface** -- registry + storage access:
```php
public function getDefinition(string $entityTypeId): EntityTypeInterface;
public function getDefinitions(): array;
public function hasDefinition(string $entityTypeId): bool;
public function getStorage(string $entityTypeId): EntityStorageInterface;
```

**EntityRepositoryInterface** -- higher-level API with language fallback:
```php
public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface;
public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array;
public function save(EntityInterface $entity): int;
public function delete(EntityInterface $entity): void;
public function exists(string $id): bool;
public function count(array $criteria = []): int;
```

### Field Layer (`packages/field/src/`)

**FieldTypeInterface** -- plugin contract for field types:
```php
public static function schema(): array;
public static function defaultSettings(): array;
public static function defaultValue(): mixed;
public static function jsonSchema(): array;
```

**FieldDefinitionInterface** -- field metadata:
```php
public function getName(): string;
public function getType(): string;
public function getCardinality(): int;
public function isMultiple(): bool;
public function getSettings(): array;
public function isTranslatable(): bool;
public function isRevisionable(): bool;
public function getDefaultValue(): mixed;
public function toJsonSchema(): array;
```

### Config Layer (`packages/config/src/`)

**ConfigInterface** -- dot-notation config access:
```php
public function getName(): string;
public function get(string $key = ''): mixed;
public function set(string $key, mixed $value): static;
public function save(): static;
public function isNew(): bool;
```

**ConfigFactoryInterface** -- immutable vs mutable access:
```php
public function get(string $name): ConfigInterface;         // immutable
public function getEditable(string $name): ConfigInterface; // mutable
```

## Architecture

### Data Flow: Entity Create-Save-Load

```
Client Code
    |
    v
EntityTypeManager::getStorage('node')
    |  returns SqlEntityStorage (cached by entity type ID)
    v
SqlEntityStorage::create(['title' => 'Hello', 'type' => 'article'])
    |  instantiateEntity() via reflection
    |  (detects constructor shape: $values-only vs $values+$entityTypeId+$entityKeys)
    v
Node(['title' => 'Hello', 'type' => 'article'])
    |  EntityBase auto-generates UUID
    |  isNew() === true (id is null)
    v
SqlEntityStorage::save($node)
    |  splitForStorage(): schema columns -> direct, others -> _data JSON
    |  dispatches PRE_SAVE event
    |  INSERT into database
    |  sets generated ID on entity
    |  enforceIsNew(false)
    |  dispatches POST_SAVE event
    |  returns EntityConstants::SAVED_NEW (1)
    v
SqlEntityStorage::load($id)
    |  SELECT from database
    |  mapRowToEntity(): merges _data JSON back into values
    |  instantiateEntity() via reflection
    |  enforceIsNew(false)
    v
Node with all values restored
```

### EntityTypeManager wiring pattern

```php
$eventDispatcher = new EventDispatcher();
$database = PdoDatabase::createSqlite(':memory:');

$storageFactory = new EntityStorageFactory($database, $eventDispatcher);

$entityTypeManager = new EntityTypeManager(
    $eventDispatcher,
    fn (EntityTypeInterface $def) => $storageFactory->getStorage($def),
);

$entityTypeManager->registerEntityType(new EntityType(
    id: 'node',
    label: 'Content',
    class: Node::class,
    keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
));

$storage = $entityTypeManager->getStorage('node');
```

### Entity query pattern

```php
$storage = $entityTypeManager->getStorage('node');

// Find published articles, newest first, limit 10
$ids = $storage->getQuery()
    ->condition('status', 1)
    ->condition('type', 'article')
    ->sort('created', 'DESC')
    ->range(0, 10)
    ->execute();

$entities = $storage->loadMultiple($ids);

// Count published nodes
$count = $storage->getQuery()
    ->condition('status', 1)
    ->count()
    ->execute();
// $count === [42]  (single-element array with the count)

// Contains search (LIKE '%term%')
$ids = $storage->getQuery()
    ->condition('title', 'search term', 'CONTAINS')
    ->execute();

// Starts-with search (LIKE 'prefix%')
$ids = $storage->getQuery()
    ->condition('title', 'Hello', 'STARTS_WITH')
    ->execute();
```

### _data JSON blob pattern

SqlSchemaHandler creates every entity table with a `_data TEXT DEFAULT '{}'` column.

On save, `SqlEntityStorage::splitForStorage()`:
1. For each value, checks if a column exists in the table (cached)
2. Schema columns go to `$dbValues`
3. Other values go to `$extraData`, JSON-encoded into `_data`

On load, `SqlEntityStorage::mapRowToEntity()`:
1. `json_decode($row['_data'])` to get extras
2. `unset($row['_data'])`
3. `array_merge($row, $extras)` to restore full values

This means **any arbitrary key-value pair** set on an entity will survive a save-load cycle, even without a dedicated column.

### Config pattern

```php
// Read config (immutable by default)
$config = $configFactory->get('system.site');
$siteName = $config->get('name');              // top-level key
$frontPage = $config->get('page.front');       // dot-notation

// Edit config (must use getEditable)
$config = $configFactory->getEditable('system.site');
$config->set('name', 'My Site');
$config->set('page.front', '/home');
$config->save();

// Import/export via ConfigManager
$result = $configManager->import();
// $result->created, $result->updated, $result->deleted, $result->errors
```

### EntityRepository with language fallback

```php
$driver = new InMemoryStorageDriver();
$repo = new EntityRepository($entityType, $driver, $eventDispatcher);
$repo->setFallbackChain(['fr', 'en']);

// Tries 'de' first, then 'fr', then 'en', then without language
$entity = $repo->find('42', langcode: 'de', fallback: true);
```

### UnitOfWork transaction pattern

```php
$unitOfWork = new UnitOfWork($database, $eventDispatcher);

$result = $unitOfWork->transaction(function () use ($storage, $node1, $node2) {
    $storage->save($node1);
    $storage->save($node2);
    return 'done';
});
// Events dispatched only after successful commit
// On exception: rollback + events discarded
```

### Field definition pattern

```php
$field = new FieldDefinition(
    name: 'body',
    type: 'text',
    cardinality: 1,
    label: 'Body',
    description: 'The main content body.',
    required: true,
    translatable: true,
);

$jsonSchema = $field->toJsonSchema();
// {'type': 'object', 'properties': {'value': {'type': 'string'}, 'format': {'type': 'string'}}}
```

## Common Mistakes

### Entity subclass constructors
User, Node, and similar subclasses only accept `(array $values)` and hardcode entityTypeId and entityKeys. SqlEntityStorage uses reflection to detect the constructor shape. If you add a new entity subclass, either:
- Accept only `(array $values)` and hardcode type/keys (like User/Node)
- Accept the full `(array $values, string $entityTypeId, array $entityKeys)` signature (like EntityBase)

Do NOT mix patterns (e.g., adding `$entityTypeId` to a subclass that already hardcodes it).

### enforceIsNew() is required for pre-set IDs
When creating entities with pre-set IDs (`new User(['uid' => 2])`), call `$entity->enforceIsNew()` before `save()`. Otherwise `isNew()` returns false, SqlEntityStorage performs UPDATE instead of INSERT, and silently affects 0 rows.

### EntityEvent uses public properties, not getters
`$event->entity` and `$event->originalEntity` are public readonly. There is no `$event->getEntity()` method. Using a getter will cause a fatal error.

### Event name requires ->value
`EntityEvents::PRE_SAVE` is an enum case, not a string. Use `EntityEvents::PRE_SAVE->value` to get the string `'waaseyaa.entity.pre_save'` for dispatch.

### JSON symmetry
Always pair `json_encode(..., JSON_THROW_ON_ERROR)` with `json_decode(..., JSON_THROW_ON_ERROR)`. The `_data` column uses `JSON_THROW_ON_ERROR` on encode but not on decode (uses `?: []` fallback). Be aware of this asymmetry if modifying the storage layer.

### LIKE wildcard escaping in SqlEntityQuery
CONTAINS and STARTS_WITH operators escape `%` and `_` in user input before wrapping with wildcards. If building custom LIKE patterns elsewhere, use: `str_replace(['%', '_'], ['\\%', '\\_'], $value)`.

### DatabaseInterface does NOT have getPdo()
Type-hint `PdoDatabase` directly if raw PDO is needed. `DatabaseInterface` only exposes `select()`, `insert()`, `update()`, `delete()`, `schema()`, `transaction()`.

### final class entities cannot be mocked
PHPUnit `createMock()` fails on `final class`. Node, User, SqlEntityStorage, SqlEntityQuery are all `final`. Use real instances in tests with `PdoDatabase::createSqlite(':memory:')` or `InMemoryEntityStorage`.

### Config get() vs getEditable()
`ConfigFactory::get()` returns immutable. Calling `set()` or `save()` on it throws `ImmutableConfigException`. Use `getEditable()` for writes.

### Avoid double $storage->create() in access checks
When checking field access before persisting a new entity, create once and reuse for both the access check and the save. Do not create a throwaway temp entity.

### count() returns single-element array
`SqlEntityQuery::count()->execute()` returns `[(int) $count]`, not a bare integer. Access with `$result[0]`.

### Table name = entity type ID
SqlEntityStorage and SqlSchemaHandler derive the table name directly from `$entityType->id()`. Entity type `'node'` maps to table `node`. Translation table is `node_translations`.

## Testing Patterns

### In-memory entity storage (no database needed)

```php
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;

$storage = new InMemoryEntityStorage(entityTypeId: 'article');
$entity = $storage->create(['title' => 'Test']);
$storage->save($entity);
$loaded = $storage->load($entity->id());
```

### SQL storage with in-memory SQLite

```php
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

$database = PdoDatabase::createSqlite(':memory:');
$eventDispatcher = new EventDispatcher();

$entityType = new EntityType(
    id: 'node',
    label: 'Content',
    class: Node::class,
    keys: ['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
);

// Create the table first
$schemaHandler = new SqlSchemaHandler($entityType, $database);
$schemaHandler->ensureTable();

// Then create storage
$storage = new SqlEntityStorage($entityType, $database, $eventDispatcher);

$node = new Node(['title' => 'Test', 'type' => 'article']);
$storage->save($node);
```

### InMemoryStorageDriver for EntityRepository tests

```php
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

$driver = new InMemoryStorageDriver();
$repo = new EntityRepository($entityType, $driver, $eventDispatcher);

$entity = $repo->find('1');
```

### Config testing with MemoryStorage

```php
use Waaseyaa\Config\Storage\MemoryStorage;
use Waaseyaa\Config\ConfigFactory;
use Waaseyaa\Config\ConfigManager;

$activeStorage = new MemoryStorage();
$syncStorage = new MemoryStorage();
$configManager = new ConfigManager($activeStorage, $syncStorage, $eventDispatcher);

$configFactory = new ConfigFactory($activeStorage, $eventDispatcher);
$config = $configFactory->getEditable('system.site');
$config->set('name', 'Test Site');
$config->save();
```

### PHPUnit conventions

- Use `#[Test]`, `#[CoversClass(...)]`, `#[CoversNothing]` attributes
- Integration tests: `tests/Integration/PhaseN/` with `Waaseyaa\Tests\Integration\PhaseN\` namespace
- Unit tests: `packages/*/tests/Unit/` with `Waaseyaa\PackageName\Tests\Unit\` namespace
- Use temp directories for file-based tests: `sys_get_temp_dir() . '/waaseyaa_test_' . uniqid()`
- Do NOT pass `-v` to PHPUnit (PHPUnit 10.5 rejects it)

## Related Specs

- `docs/specs/entity-system.md` -- full subsystem specification with all interface signatures
- `CLAUDE.md` -- project-wide gotchas and conventions
- `docs/plans/2026-02-27-aurora-cms-design.md` -- original CMS design with entity/storage architecture
- `docs/plans/2026-02-28-aurora-architecture-v2-design.md` -- architecture v2 with 17 pillars
