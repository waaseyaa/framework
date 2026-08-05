# 010 — Multi-backend field storage

**Status:** Accepted (2026-05-11)
**Mission:** Stability charter ratification; M3 (column-backed fields)
**Spec context:** `docs/specs/drupal-comparison-matrix.md` §6.1, §1.1; `docs/specs/stability-charter.md` §5.3

## Context

Today every entity stores all field values in a single `_data` JSON CLOB via `SqlEntityStorage`. Audit mission M3 will add column-backed fields. Beyond M3, two further pressures are visible on the public-surface-map:

- **AI-vector storage** (Layer 5, `ai-vector`) is a legitimate entity concern: a `dictionary_entry` having a `vec` field that lives in pgvector / Qdrant / sqlite-vss is not exotic, it's the canonical case for a 2026 framework. SQL is the wrong backend for it.
- **Remote/foreign-system fields** — pulling a `band_office` from a remote provider API into an entity instance without persisting it to local SQL. Drupal's "external entity" pattern.

The decision: SQL-only with everything else outside the entity system, or multi-backend with storage selection as a first-class field concern.

## Options considered

### A. SQL-only

Force all entity storage through `SqlEntityStorage`. AI-vector lives outside the entity system; remote-system fields are computed/derived rather than stored. Simplest. Rejected: forfeits the "everything is an entity" mission promise and makes vector search a second-class consumer of the access/policy/event surfaces.

### B. Multi-backend, per-entity-type primary

Each entity type names a single storage backend. All fields go through that one backend. Rejected: forces a vector field on `dictionary_entry` to either drag the entire entity into a vector store, or live outside the entity. Same shape as A in the awkward cases.

### C. Per-entity primary + per-field override (CHOSEN)

Each entity type names a primary storage backend (default: `sql`). Individual fields may opt into an alternate backend via `FieldDefinition::storedIn(...)`. The framework's storage layer fans out reads/writes per field.

## Decision

Multi-backend storage, with **per-entity primary backend** and **per-field overrides** for special-purpose fields.

### Contract

A new stable interface `Waaseyaa\Entity\Storage\FieldStorageBackendInterface`:

```php
interface FieldStorageBackendInterface
{
    public function id(): string; // 'sql' | 'sql-column' | 'vector' | 'kv' | 'remote'
    public function read(EntityInterface $entity, FieldDefinition $field): mixed;
    public function write(EntityInterface $entity, FieldDefinition $field, mixed $value): void;
    public function delete(EntityInterface $entity, FieldDefinition $field): void;
    public function supportsQuery(): bool;
}
```

Field definitions declare backend:

```php
FieldDefinition::create('vec', 'float_vector_768')->storedIn('vector')
```

`EntityStorage` becomes a coordinator: it looks up the primary backend for the entity type, asks each field's backend to read/write, and assembles the result. The blob path is preserved as one backend (`sql-blob`); column-backed fields (M3) are a second (`sql-column`); vector and remote are additional plugins.

### Backend registration

Backends register via service providers, identified by string id. The framework ships:
- `sql-blob` — current `_data` JSON path (compatibility default for unmigrated entity types).
- `sql-column` — M3's column-backed path.
- `vector` — abstract; concrete implementations (`pgvector`, `qdrant`, `sqlite-vss`) ship as separate packages.

Apps may register additional backends. The id namespace is stable surface; `sql-blob`, `sql-column`, `vector` are reserved.

### Query support

`supportsQuery()` tells the query layer whether a field is filterable/sortable. Vector-stored fields support similarity queries (separate interface — `VectorSearchableBackendInterface`); blob-stored fields support equality on extracted keys; column-backed fields support full SQL predicates. The query layer raises `UnsupportedQueryException` when an unsupported operation is requested rather than silently returning wrong results.

### Migration path

Existing entity types stay on `sql-blob` until per-type migration. M3 introduces `sql-column` as opt-in via `FieldDefinition`. No flag day. Charter §5.3 governs the `_data` → columns transition; this ADR governs the *shape* of that transition, not its schedule.

## Consequences

- **M3 scope expands.** Column-backed fields are now "one backend implementation in a multi-backend system," not a special case. Slightly more design work; substantially better extensibility.
- **AI-vector becomes a first-class entity concern.** Vector storage rides the entity access policy, lifecycle events, and event dispatch — not bolted on.
- **Storage plugin contract becomes stable surface.** Adding/removing backend ids follows the deprecation cycle.
- **Query layer becomes more complex.** Cross-backend joins are forbidden; the query builder errors loudly when asked to filter on a non-queryable backend.
- **The "external entity" pattern (remote fields) becomes possible without a separate entity type system.** A `remote` backend reading from an external provider API is a future package, not a framework change.

## References

- Matrix: `docs/specs/drupal-comparison-matrix.md` §1.1 (entity & data model), §6.1.
- Charter: `docs/specs/stability-charter.md` §5.3 (entity/storage rules).
- Audit: `waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md` F1, M3.
- Related ADRs: 016 (revisions need to ride this), 015 (Views queries depend on `supportsQuery`), 011 (lifecycle events fire per coordinator op, not per backend).
