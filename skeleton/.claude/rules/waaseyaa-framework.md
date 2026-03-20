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

| Need | Use |
|------|-----|
| Transactions, raw queries | `DatabaseInterface` |
| Entity persistence | `SqlEntityStorage` + `StorageRepositoryAdapter` |
| Entity data access | `EntityRepositoryInterface` |
| Entity registration | `EntityTypeManager` |
| Authorization | `AccessPolicyInterface` + `FieldAccessPolicyInterface` |
| Query building | `SelectInterface` |
| Dependency injection | Symfony DI container |
| Config access | `getenv()` or Waaseyaa `env()` helper |

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
