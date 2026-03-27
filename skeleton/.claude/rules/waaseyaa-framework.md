# Waaseyaa Framework Invariants

This rule is always active. Follow it silently. Do not cite this file in conversation.

---

## Identity

Waaseyaa is a **Symfony 7-based, entity-first PHP framework**. PHP 8.4+, full dependency injection, no global state.

- It is **NOT Laravel**. It does not use Illuminate components.
- It is **NOT Drupal**. It replaces Drupal's legacy runtime with a clean, modular architecture.
- If the codebase looks Laravel-ish (Actions/, Models/, artisan), do NOT default to Laravel conventions.

---

## Forbidden Dependencies

| Forbidden | Why |
|-----------|-----|
| `Illuminate\Support\Facades\*` | No Laravel facades |
| `Illuminate\Database\*` / Eloquent | No Laravel ORM |
| `DB::transaction()`, `DB::table()` | No Laravel DB layer |
| `Model::create()`, `Model::query()` | No Eloquent patterns |
| `env()`, `config()` (Laravel helpers) | Use Waaseyaa config system |
| `$entity->save()`, `$entity->delete()` | No ActiveRecord — entities are pure data objects |
| `new \PDO(...)` | Use `DBALDatabase` + `DriverManager::getConnection()` |
| `$pdo->prepare(...)` | Use `EntityRepository::findBy()` or `DatabaseInterface::select()` |

---

## Required Abstractions

| Need | Use | Full Namespace |
|------|-----|----------------|
| Transactions, raw queries | `DatabaseInterface` | `Waaseyaa\Database\DatabaseInterface` |
| Entity persistence | `SqlEntityStorage` + `StorageRepositoryAdapter` | `Waaseyaa\EntityStorage\Sql\SqlEntityStorage` |
| Entity data access | `EntityRepositoryInterface` | `Waaseyaa\Entity\Repository\EntityRepositoryInterface` |
| Entity registration | `EntityTypeManager` | `Waaseyaa\Entity\EntityTypeManager` |
| Authorization | `AccessPolicyInterface` + `FieldAccessPolicyInterface` | `Waaseyaa\Access\AccessPolicyInterface`, `Waaseyaa\Access\FieldAccessPolicyInterface` |
| Query building | `SelectInterface` | `Waaseyaa\Database\Query\SelectInterface` |
| Dependency injection | `ServiceProvider` DI methods | `Waaseyaa\Foundation\ServiceProvider\ServiceProvider` |
| Queue / async jobs | `QueueInterface` + `Job` | `Waaseyaa\Queue\QueueInterface`, `Waaseyaa\Queue\Job` |
| Config access | `getenv()` or Waaseyaa `env()` helper | — |

---

## ServiceProvider DI Methods

Service providers extend `Waaseyaa\Foundation\ServiceProvider\ServiceProvider`. Override `register()` for bindings, `boot()` for event wiring.

```php
// register() — bind services
$this->singleton(MyInterface::class, fn () => new MyImpl($this->resolve(Dep::class)));
$this->bind(Transient::class, Transient::class);

// resolve() — retrieve a registered binding or kernel service
$service = $this->resolve(MyInterface::class);

// tag() — group bindings under a tag
$this->tag(MyInterface::class, 'processors');

// entityType() — register an entity type
$this->entityType(new EntityType(id: 'widget', label: 'Widget', ...));
```

| Method | Visibility | Signature |
|--------|-----------|-----------|
| `singleton()` | protected | `(string $abstract, string\|callable $concrete): void` |
| `bind()` | protected | `(string $abstract, string\|callable $concrete): void` |
| `resolve()` | public | `(string $abstract): mixed` |
| `tag()` | protected | `(string $abstract, string $tag): void` |
| `entityType()` | protected | `(EntityTypeInterface $entityType): void` |

---

## Queue Job Pattern

Extend `Waaseyaa\Queue\Job`, implement `handle()`, dispatch via `QueueInterface::dispatch()`.

```php
final class MyJob extends \Waaseyaa\Queue\Job {
    public int $tries = 3;       // max retry attempts
    public int $timeout = 60;    // seconds
    public int $retryAfter = 0;  // delay between retries

    public function handle(): void { /* work */ }
    public function failed(\Throwable $e): void { /* optional cleanup */ }
}

// Dispatch:
$queue->dispatch(new MyJob());
```

---

## Code Organization

- **PSR-4 one-class-per-file** — each `.php` file declares exactly one class, interface, or enum
- **`declare(strict_types=1)`** in every file
- **`final class`** by default for concrete implementations
- **Namespace pattern**: `App\SubNamespace\` for application code

---

## Entity Persistence Pipeline

```
Entity (extends EntityBase or ContentEntityBase)
  → EntityType registered via EntityTypeManager
  → EntityStorageDriverInterface (SqlStorageDriver for SQL)
  → EntityRepository (hydration, events, language fallback)
  → DatabaseInterface (Doctrine DBAL, NOT raw PDO)
```

- **ContentEntityBase** — has `set()` for field mutations (most entities)
- **EntityBase** — immutable value-like entities (rare)
- Entities are immutable except through storage operations
- Non-entity tables (join tables, counters, audit logs) may use `DatabaseInterface` directly

---

## 7-Layer Architecture

Dependencies flow **downward only**. Never import from a higher layer.

| Layer | Name | Packages |
|-------|------|----------|
| 0 | Foundation | cache, plugin, typed-data, database-legacy |
| 1 | Core Data | entity, field, entity-storage, access, user, config |
| 2 | Services | routing, queue, state, validation |
| 3 | Content Types | node, taxonomy, media, path, menu, workflows |
| 4 | API | api, graphql, routing |
| 5 | AI | ai-schema, ai-agent, ai-vector, ai-pipeline |
| 6 | Interfaces | cli, ssr, admin, mcp, telescope |

---

## MCP Spec Retrieval

For deeper framework knowledge beyond these invariants, query the Waaseyaa MCP server:

- `waaseyaa_list_specs` — index of all framework specs
- `waaseyaa_get_spec("entity-system")` — full spec content
- `waaseyaa_search_specs("transaction locking")` — keyword search
