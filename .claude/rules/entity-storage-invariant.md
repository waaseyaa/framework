# Entity Storage Invariant

Supplemental Claude guidance. The authorization and precedence rules in
`docs/governance/agent-contract.md` remain authoritative. The enduring storage
contract lives in `docs/specs/entity-system.md`; this file is a session-hot
summary, not an independent authority.

---

## The Pipeline

All entity persistence MUST follow this pipeline:

```
Entity (extends EntityBase or ContentEntityBase)
  → EntityType registered via EntityTypeManager
  → EntityStorageDriverInterface (SqlStorageDriver for SQL databases)
  → EntityRepository (high-level: hydration, events, language fallback)
  → DatabaseInterface (Doctrine DBAL abstraction, NOT raw PDO)
```

---

## Forbidden Patterns

| Pattern | Why | Use Instead |
|---------|-----|-------------|
| `new \PDO(...)` | Bypasses framework DB layer | `DBALDatabase` + `DriverManager::getConnection()` |
| `$pdo->prepare(...)` | Raw PDO queries, no events | `EntityRepository::findBy()` or `DatabaseInterface::select()` |
| `\PDO::FETCH_ASSOC` arrays | Untyped, no entity lifecycle | Entity objects via `EntityRepository::find()` |
| Direct SQL strings for entities | No event dispatch, no hydration | `EntityRepository::save()` / `delete()` |
| `use Illuminate\*` | Waaseyaa uses Symfony + Doctrine | Waaseyaa/Symfony equivalents |
| Laravel facades | Magic globals | DI-injected services |

---

## Required Pattern for New Entity Types

```php
// 1. Define entity class
class MyEntity extends ContentEntityBase {
    public function __construct(array $values = []) {
        parent::__construct($values, 'my_entity', [
            'id' => 'id', 'uuid' => 'uuid', 'label' => 'title',
        ]);
    }
}

// 2. Register entity type
$entityTypeManager->addEntityType(new EntityType(
    id: 'my_entity',
    label: 'My Entity',
    class: MyEntity::class,
    keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
));

// 3. Wire storage (in ServiceProvider::register())
$resolver = new SingleConnectionResolver($database);
$driver = new SqlStorageDriver($resolver);
$repository = new EntityRepository($entityType, $driver, $eventDispatcher);

// 4. Use repository for all CRUD
$entity = $repository->find($id);              // Read
$entities = $repository->findBy(['key' => $v]); // Query
$repository->save($entity);                     // Create/Update (dispatches events)
$repository->delete($entity);                   // Delete (dispatches events)
```

---

## When DatabaseInterface Is Acceptable Without EntityRepository

Non-entity tables (join tables, counters, audit logs, subscriptions) may use `DatabaseInterface` directly. The rule is:

- **Has an identity (ID/UUID) and lifecycle?** → Must be an entity with EntityRepository
- **Is a supporting/join table?** → DatabaseInterface::select/insert/update/delete is fine

---

## ContentEntityBase vs EntityBase

- Use `ContentEntityBase` when the entity needs `set()` for field mutations (most entities)
- Use `EntityBase` for immutable value-like entities (rare)

---

## Database Connections

Waaseyaa supports multiple database backends via `ConnectionResolverInterface`:

```php
// SQLite (default for entity storage)
$db = DBALDatabase::createSqlite($path);

// MySQL/MariaDB
$db = new DBALDatabase(DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => '...', 'dbname' => '...', ...
]));

// PostgreSQL
$db = new DBALDatabase(DriverManager::getConnection([
    'driver' => 'pdo_pgsql', 'host' => '...', 'dbname' => '...', ...
]));

// Wrap for entity storage
$resolver = new SingleConnectionResolver($db);
```
