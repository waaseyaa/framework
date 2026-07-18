# Entity Storage v2 — Multi-Backend Storage with Revisions

<!-- Spec reviewed 2026-07-17 - #2064 WP1 adds additive ordinary and revision V2 opaque-row/snapshot SPIs, role-separated driver/repository boundary collaborators, and a dormant repository-only V1 adapter that receives driver roles and requires entity.deprecation emission. Existing V1 drivers and repositories remain byte-compatible; activation removes V1/adapter under the charter no-shim path. Canonical contract: entity-field-read-boundary.md. -->

> **Two-axis cross-reference.** Revisionable + translatable
> entities (e.g. Minoo `teaching`) compose this spec's revision model with the
> per-field translation model from M-006. Canonical doctrine for the two-axis
> interaction — schema shapes, atomic multi-language save, listing integration —
> lives in [`revision-system-unified.md`](revision-system-unified.md). The operator
> cookbook is [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md).
> (The earlier M-004 `vid`-based stack described in
> [`entity-storage-two-axis.md`](entity-storage-two-axis.md) was retired in
> alpha.196 and is superseded historical context only.)

**Status:** Draft mission spec (target: ratify with the stability charter and ADRs 010–016)
**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** TBD (to be assigned by `@jonesrussell` on mission creation)
**Origin:** Audit mission M3 in `waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`, expanded under ADRs 010 / 011 / 016.

**Governing ADRs:**
- [ADR 010](../adr/010-multi-backend-field-storage.md) — multi-backend field storage
- [ADR 011](../adr/011-entity-lifecycle-events.md) — entity lifecycle events
- [ADR 016](../adr/016-revisions-first-class.md) — revisions first-class

**Charter linkage:**
- [`stability-charter.md`](stability-charter.md) §5.3 governs the new entity/storage stable surface.
- §3.2 criterion 8 (revisions in production) gates beta entry on this mission's delivery.
- §3.2 criterion 7 (listing pipeline) consumes this mission's `supportsQuery()` contract.

**Comparable mission:** `docs/specs/schema-evolution-v2.md` (#529) — shape and rigor template.

---

## 0. Origin

The 2026-05-11 framework/app audit (finding F1) identified the `_data` JSON blob — every entity field value stored as a JSON string in one column — as the single highest-leverage decision in the framework. The original M3 mission scope was "add real columns for engagement entities."

ADR-driven analysis expanded the scope:

- **ADR 010** reframes the change: `_data` is not removed; it becomes one of several named storage backends (`sql-blob`). Column-backed fields are a second backend (`sql-column`). The framework gains a backend contract that future backends (vector, key-value, remote) plug into.
- **ADR 016** moves entity revisions onto the stable surface. Revisions are too entangled with storage to retrofit later, so revision tables co-design with the column-backed schema in this mission, not after.
- **ADR 011** specifies lifecycle events on the stable surface; the storage coordinator is their canonical dispatch point.

The expanded mission delivers all three concerns in one coordinated pass. Doing them separately would either ship the storage shape with a known-imminent revision retrofit, or ship revisions on a blob substrate that then has to migrate twice.

---

## 1. Goals / non-goals

### 1.1 Goals

1. Establish a stable, registrable **field-storage backend contract** (`FieldStorageBackendInterface`).
2. Refactor the current `_data` JSON path into a named **`sql-blob` backend**, with no observable behavior change for existing entity types.
3. Implement a new **`sql-column` backend** that stores field values in real SQL columns with indexes.
4. Implement a storage **coordinator** that fans reads/writes across per-field backends and dispatches the four ADR-011 lifecycle events.
5. Implement first-class **entity revisions** (`RevisionableEntityInterface`) co-designed with the column-backed schema.
6. Provide a **storage-migration generator** (`bin/waaseyaa make:storage-migration <entity_type>`) for per-entity-type migration from `sql-blob` to `sql-column`.
7. Define the **query-support contract** (`supportsQuery()`, `UnsupportedQueryException`) — input for the future listing pipeline (ADR 015).
8. Ship a **backend-conformance test suite** that any future backend implementation must pass.
9. Validate the mission by migrating **one Minoo entity type** end-to-end (selection criteria in §16.4).

### 1.2 Non-goals

The following are deliberately deferred to future missions / ADRs and must not creep into this mission's scope:

- **Content moderation workflows** (states, transitions, approval queues) — separate future ADR.
- **Per-field translation** (`langcode` × `RevisionableEntityInterface` interaction) — separate future ADR; named in charter §11 Q7.
- **Revision admin UI** (compare two revisions, revert) — app concern, not framework.
- **Vector backend implementation** — ADR 010 reserves the `vector` id; an implementation is a sibling mission, not this one.
- **Remote / external-entity backend** — same.
- **Cross-backend query coordination** (joins, multi-backend filters) — charter §11 Q8 deferred.
- **Auto-pruning of old revisions** — `RevisionPruner` ships as a disabled service per ADR 016; pruning policies are app concern.
- **Admin UI for listing builder** — ADR 015 deferral; not this mission.
- **Migrating all Minoo entity types** — this mission validates with one type; subsequent type migrations are operational, not framework work.

---

## 2. Scope summary

### 2.1 In scope

- `FieldStorageBackendInterface` and its registration mechanism (composer-discovery via provider capability).
- The reserved backend-id namespace: `sql-blob`, `sql-column`, `vector` (vector reserved but not implemented here).
- `EntityStorage` coordinator: per-field backend dispatch on read/write/delete.
- `sql-blob` backend: refactor of existing `SqlEntityStorage` path; identical observable behavior.
- `sql-column` backend: new SQL strategy with real columns and indexes.
- `FieldDefinition::storedIn(string $backendId)` API.
- `RevisionableEntityInterface` and `RevisionableEntityStorageInterface`.
- Per-entity-type opt-in via `EntityType` `revisionable: true` + `entityKeys.revision`.
- Revision tables co-designed with `sql-column`; revision support for `sql-blob` (existing entity types may opt into revisions without migrating to columns).
- Lifecycle event dispatch from the coordinator (`BeforeSaveEvent`, `AfterSaveEvent`, `BeforeDeleteEvent`, `AfterDeleteEvent`, marker interface, `AbortOperationException`).
- `PartialSaveException` for backend fan-out partial failures.
- Query-support contract on backends (`supportsQuery()`, `UnsupportedQueryException` at definition validation time).
- `view_revision` operation in `GateInterface` and policy adapter.
- Storage-migration generator CLI.
- Backend-conformance test suite (reusable harness).
- Upgrade-guide template entry for "migrating entity type X to `sql-column`."
- One Minoo entity-type end-to-end migration (validation).

### 2.2 Out of scope

(See §1.2 non-goals.)

---

## 3. Functional requirements

Normative requirements use **MUST / SHOULD / MAY** per RFC 2119. Numbered for Spec Kitty tokenization.

### 3.1 Backend contract

- **FR-001** The framework MUST expose `Waaseyaa\Entity\Storage\FieldStorageBackendInterface` as a stable surface (charter §5.3).
- **FR-002** A backend MUST declare its id via `id(): string`. Ids `sql-blob`, `sql-column`, and `vector` are reserved by the framework. Apps and packages MAY register additional backends under any non-reserved id.
- **FR-003** A backend MUST implement `read`, `write`, `delete`, and `supportsQuery` per the interface signatures in ADR 010.
- **FR-004** Backends MUST be registered via a provider capability `HasFieldStorageBackendsInterface` (parallel to `HasNativeCommandsInterface`).
- **FR-005** Backend-id collisions MUST fail at boot with a typed `BackendIdCollisionException` carrying both registering FQCNs.
- **FR-006** Backend registration that fails any registration check (e.g. missing capability method, malformed id) MUST emit on the `boot.deprecation` log channel and fail the boot loudly (no silent drop — per charter §5.4).

### 3.2 `sql-blob` backend (refactor)

- **FR-007** The current `_data` JSON-blob storage path MUST be refactored to implement `FieldStorageBackendInterface` with id `sql-blob`.
- **FR-008** All existing entity types MUST continue to function with no observable behavior change. Schema, query results, and stored values MUST be byte-identical post-refactor (verified by integration tests in WP12).
- **FR-009** `sql-blob` MUST report `supportsQuery(): false` for individual field predicates. Query against `sql-blob`-backed fields MUST raise `UnsupportedQueryException` at definition validation time (per FR-021).
- **FR-010** `sql-blob` MUST support equality queries on entity keys (`id`, `uuid`, bundle, langcode) which live outside `_data` as real columns; these are not field predicates.

### 3.3 `sql-column` backend (new)

- **FR-011** A new `sql-column` backend MUST persist each `FieldDefinition` as a real SQL column on the entity table.
- **FR-012** Column types MUST be derived from `FieldDefinition` types via a documented type-mapping table (FR-012a deliverable in §8.3 of this spec). Mapping MUST cover at minimum: string, int, bigint, bool, datetime, json, uuid, text, float, decimal.
- **FR-013** `sql-column` MUST create indexes for fields marked `FieldDefinition::indexed()`. The indexed flag is additive and stable surface.
- **FR-014** `sql-column` MUST report `supportsQuery(): true` for all stored field types.
- **FR-015** `sql-column` MUST support equality, inequality, `in`, `not in`, `gt/gte/lt/lte`, `like` predicates on its columns.
- **FR-016** `sql-column` MUST NOT silently support cross-table joins for relationship-typed fields. Cross-entity queries are forbidden in v0.x (charter §11 Q8 deferral).

### 3.4 Coordinator

- **FR-017** `EntityStorage` MUST act as a coordinator: it resolves the entity type's primary backend, asks each field's backend (default: primary) to read/write, and assembles results.
- **FR-018** Coordinator reads MUST return a fully populated entity assembled from all backends' field values. The order of backend reads is implementation-defined; consumers MUST NOT depend on it.
- **FR-019** Coordinator writes MUST proceed in deterministic order: fields on the primary backend first, then fields on alternate backends in order of registration. This is to make `PartialSaveException` recovery tractable.
- **FR-020** On any backend write error, the coordinator MUST raise `PartialSaveException` carrying: the entity, the originating exception, the list of backends that committed, and the list of backends that did not. The exception itself is stable surface.
- **FR-021** The coordinator MUST validate field-to-backend bindings at boot. Bindings to unsupported predicates (e.g. a `ListingDefinition` filter on a `supportsQuery: false` field) MUST raise `UnsupportedListingException` at definition validation time, not at runtime.

### 3.5 Lifecycle events (ADR 011 integration)

- **FR-022** The coordinator MUST dispatch `BeforeSaveEvent` once before the first backend write.
- **FR-023** The coordinator MUST dispatch `AfterSaveEvent` once after all backend writes succeed.
- **FR-024** The coordinator MUST NOT dispatch `AfterSaveEvent` on a partial-save failure (charter §11 Q10 resolution).
- **FR-025** A `BeforeSaveEvent` subscriber that throws `AbortOperationException` MUST cause the coordinator to halt without contacting any backend.
- **FR-026** `BeforeDeleteEvent` and `AfterDeleteEvent` MUST follow the same rules as save events.
- **FR-027** Lifecycle events MUST emit on the `entity.lifecycle` log channel at `debug` level when log level is debug or lower.

### 3.6 Revisions (ADR 016 integration)

- **FR-028** `Waaseyaa\Entity\RevisionableEntityInterface` MUST be on the stable surface with the methods in ADR 016 §"API on the stable surface."
- **FR-029** Entity types opt into revisioning by:
  - Implementing `RevisionableEntityInterface` on the entity class.
  - Declaring `revisionable: true` on the `EntityType`.
  - Declaring `entityKeys.revision` (default key name: `vid`).
- **FR-030** For `sql-column` revisionable entity types, the schema MUST include a primary table (one row per entity, current-revision pointer) and a `<table>__revision` table (one row per revision, full field values).
- **FR-031** For `sql-blob` revisionable entity types, the revision table MUST carry the same `_data` blob structure as the primary table.
- **FR-032** By default, every `save()` on a revisionable entity MUST create a new revision and update the current-revision pointer.
- **FR-033** Apps MAY opt out per save via an explicit `SaveContext::withoutNewRevision()` flag. Default is "create revision."
- **FR-034** `RevisionableEntityStorageInterface` MUST expose `loadRevision`, `listRevisions`, and `setCurrentRevision`.
- **FR-035** Listing the revisions of an entity MUST return revisions in descending creation order; pagination is the consumer's concern.
- **FR-036** Setting the current revision MUST be access-checked under the `edit` operation on the entity type's policy.
- **FR-037** Revision creation MUST NOT introduce additional lifecycle event names. Subscribers detect revision creation by reading `$entity->revisionId()` from the post-save state.

### 3.7 Per-revision access

- **FR-038** `GateInterface` MUST recognize the `view_revision` operation.
- **FR-039** Policies declaring `view_revision` in their `#[PolicyAttribute(operations: [...])]` MUST be consulted for revision access.
- **FR-040** Policies that do NOT declare `view_revision` MUST fall back to the `view` operation; the framework MUST NOT default-deny.

### 3.8 Migration generator

- **FR-041** The framework MUST provide `bin/waaseyaa make:storage-migration <entity_type>` that generates a migration file moving the entity type from `sql-blob` to `sql-column`.
- **FR-042** The generated migration MUST create the new schema, copy `_data`-extracted values into typed columns, set the current-revision pointer, populate the revision table (if revisionable), and run within a single transaction.
- **FR-043** The generated migration MUST be reversible. A `--reversible` flag is implicit and ON by default; `--no-reversible` is allowed for entity types where reversibility is genuinely impossible (must be argued in the migration file's docblock).
- **FR-044** Migration generation MUST NOT alter the entity class or `EntityType` registration — those are app-author concerns.
- **FR-045** The migration generator MUST refuse to generate (and exit with a typed error) if any field on the entity type has a backend other than `sql-blob` or `sql-column`. Vector or remote backends in a mixed-backend entity MUST be migrated manually with author judgment.

### 3.9 Error model

- **FR-046** The mission MUST ship these exception types on stable surface: `PartialSaveException`, `UnsupportedQueryException`, `UnsupportedListingException`, `AbortOperationException`, `BackendIdCollisionException`, `EntityValidationException`.
- **FR-047** Each exception type MUST carry a stable string `code` field per charter §4.4 surface guidance.
- **FR-048** Renames or removals of any of the exception types or their codes MUST follow the deprecation cycle (charter §4).

### 3.10 Testing

- **FR-049** A reusable backend-conformance test suite (`Waaseyaa\Entity\Testing\BackendConformanceTestCase`) MUST ship. Any class implementing `FieldStorageBackendInterface` is expected to subclass and pass.
- **FR-050** The conformance suite MUST cover: read/write/delete round-trips, all FR-012 type mappings (for typed backends), supportsQuery semantics, error-path coverage (UnsupportedQueryException, write failures).
- **FR-051** Revision behavior MUST have dedicated integration tests separate from the conformance suite.
- **FR-052** The coordinator MUST have integration tests covering: multi-backend fan-out, partial-save error path, lifecycle event dispatch order, abort-on-BeforeSave semantics.

### 3.11 Documentation

- **FR-053** This mission MUST update `docs/specs/entity-system.md` to reflect the multi-backend architecture.
- **FR-054** A new spec `docs/specs/field-storage-backends.md` MUST document the backend contract, registration, and the type-mapping table.
- **FR-055** An upgrade guide template for "migrating entity type X to sql-column" MUST ship per charter §7.
- **FR-056** A first concrete upgrade guide MUST ship for the Minoo validation entity type chosen in WP11.

---

## 4. Stable surface deliverables

Maps the mission's stable-surface output to charter §5.3.

| Symbol | Kind | Governing ADR | Charter §5.3 anchor |
|---|---|---|---|
| `FieldStorageBackendInterface` | Interface | 010 | "Field storage backend contract" bullet |
| `HasFieldStorageBackendsInterface` | Provider capability | 010 | Same |
| Backend-id namespace (`sql-blob`, `sql-column`, `vector` reserved) | String set | 010 | Same |
| `FieldDefinition::storedIn(string)` | Method | 010 | "FieldDefinition API" bullet |
| `FieldDefinition::indexed()` | Method | 010 | Same |
| `EntityType.revisionable` flag | Property | 016 | "EntityType definition shape" bullet |
| `EntityType.entityKeys.revision` | Key slot | 016 | Same |
| `BeforeSaveEvent`, `AfterSaveEvent`, `BeforeDeleteEvent`, `AfterDeleteEvent` | Event classes | 011 | "Entity lifecycle events" bullet |
| `EntityLifecycleEventInterface` | Marker interface | 011 | Same |
| `AbortOperationException` | Exception class | 011 | Same |
| `RevisionableEntityInterface` | Interface | 016 | "Revisionable surface" bullet |
| `RevisionableEntityStorageInterface` | Interface | 016 | Same |
| `view_revision` operation | Op constant | 016 | "Access-policy attribute system" bullet |
| `PartialSaveException`, `UnsupportedQueryException`, `UnsupportedListingException`, `BackendIdCollisionException` | Exception classes | 010/015 | New entries to add to §5.3 stable surface during ratification |
| `entity.lifecycle` log channel | String constant | 011 | Charter §4.4 |
| `bin/waaseyaa make:storage-migration` | CLI command | 010+016 | Charter §5.2 (console surface) |

---

## 5. Backend contract spec (normative)

See ADR 010 §"Contract" for the interface signature. This mission's spec for the interface adds:

### 5.1 Registration

Backends are registered through provider capability `HasFieldStorageBackendsInterface`:

```php
public function fieldStorageBackends(): array
{
    return [
        new SqlBlobBackend(...),
        new SqlColumnBackend(...),
    ];
}
```

At boot, the registrar collects all backends, indexes them by id, and raises `BackendIdCollisionException` on duplicates.

### 5.2 Reserved id namespace policy

Reserved ids: `sql-blob`, `sql-column`, `vector`. These names are part of the stable surface; the framework owns them. Apps and packages registering reserved ids without being the canonical implementation MUST fail boot.

Apps MAY register backends under any non-reserved id. Recommended convention: `<vendor>-<purpose>` (e.g. `minoo-elasticsearch`). Conformance with the reserved id namespace policy is charter §11 Q9.

### 5.3 Per-entity primary backend

`EntityType` MAY declare a primary backend:

```php
new EntityType(
    id: 'event',
    primaryStorageBackend: 'sql-column',  // optional; default 'sql-blob' during migration window
    ...
)
```

Default for existing types during the migration window: `sql-blob`. The migration generator (FR-041) flips this to `sql-column` as part of its emitted migration.

### 5.4 Per-field backend override

`FieldDefinition::storedIn(string $backendId)` overrides per field. Common case: a vector field on an otherwise column-backed entity:

```php
FieldDefinition::create('embedding', 'float_vector_768')->storedIn('vector')
```

Override MUST be validated at boot against registered backend ids.

---

## 6. Coordinator behavior spec (normative)

### 6.1 Read

1. Resolve entity type's primary backend.
2. For each field in the entity type, resolve the field's backend (override or primary).
3. Group fields by backend.
4. For each backend, call `read($entity, $field)` for that backend's fields. Order across backends is implementation-defined.
5. Assemble field values into the returned entity instance.

### 6.2 Write

1. Dispatch `BeforeSaveEvent`. If a subscriber throws `AbortOperationException`, the operation halts and the exception propagates.
2. Group fields by backend.
3. Write primary-backend fields first.
4. Write alternate-backend fields next, in registration order.
5. If all writes succeed, dispatch `AfterSaveEvent`.
6. If any write fails, raise `PartialSaveException` carrying committed/uncommitted backend lists. `AfterSaveEvent` does NOT fire (FR-024).

### 6.3 Delete

Symmetric to write, using `BeforeDeleteEvent` / `AfterDeleteEvent`.

### 6.4 Revision creation

For revisionable entity types, the coordinator's write path:

1. Dispatches `BeforeSaveEvent` against the about-to-be-saved entity.
2. Computes whether this save creates a new revision (per FR-032/FR-033).
3. If creating: writes a new revision row first, then updates the primary table's current-revision pointer last.
4. Updates the in-memory entity's `revisionId()` to the new revision id before dispatching `AfterSaveEvent`.

### 6.5 Partial-save error model

`PartialSaveException` payload:

```php
final class PartialSaveException extends \RuntimeException
{
    public function __construct(
        public readonly EntityInterface $entity,
        public readonly \Throwable $causedBy,
        public readonly array $committedBackends,    // string[]
        public readonly array $uncommittedBackends,  // string[]
        public readonly string $code = 'PARTIAL_SAVE',
    );
}
```

Recovery is operator concern. The framework provides the diagnostic; rollback strategy is per-app.

---

## 7. `sql-blob` backend spec (refactor)

### 7.1 Behavior identity

Post-refactor, `sql-blob` MUST produce byte-identical results to current `SqlEntityStorage` for:

- All existing entity-type schemas.
- All existing query patterns (entity-key equality, bundle filters, langcode filters).
- All existing CRUD round-trips.

This is non-negotiable. The integration test suite in WP12 includes a "behavior diff" check against pre-refactor recorded outputs.

### 7.2 supportsQuery

`sql-blob` reports `false` for field predicates. Queries against fields raise `UnsupportedQueryException` at definition validation time (FR-021).

### 7.3 Revision support

`sql-blob` MAY back revisionable entity types — revision tables carry `_data` payloads. Useful for apps that want revisions before they migrate to `sql-column`.

---

## 8. `sql-column` backend spec

### 8.1 Schema generation

For an entity type with `primaryStorageBackend: 'sql-column'`, the schema is generated from the `EntityType` and `FieldDefinition` list:

- Entity keys (`id`, `uuid`, bundle, langcode, revision) become real columns.
- Each `FieldDefinition` becomes one or more columns per its type's mapping (FR-012).
- `FieldDefinition::indexed()` fields get a B-tree index.
- For revisionable types, a parallel `<table>__revision` is generated with the same columns plus revision metadata (`vid`, `revision_created_at`, `revision_author`, `revision_log`).

### 8.2 Type mapping table (FR-012a deliverable)

Initial mapping; future ADR may extend.

| FieldDefinition type | SQL column type (SQLite) | SQL column type (Postgres) |
|---|---|---|
| `string` | `TEXT` | `TEXT` or `VARCHAR(n)` if length declared |
| `int` | `INTEGER` | `INTEGER` |
| `bigint` | `INTEGER` | `BIGINT` |
| `bool` | `INTEGER` (0/1) | `BOOLEAN` |
| `datetime` | `TEXT` (ISO 8601) | `TIMESTAMPTZ` |
| `json` | `TEXT` | `JSONB` |
| `uuid` | `TEXT` | `UUID` |
| `text` | `TEXT` | `TEXT` |
| `float` | `REAL` | `DOUBLE PRECISION` |
| `decimal` | `TEXT` (lossless) | `NUMERIC(p,s)` |
| `float_vector_<n>` | (forbidden; route to `vector` backend) | (forbidden; route to `vector` backend) |

### 8.3 Query support

All FR-015 predicates supported. The query layer translates `EntityQuery` operators to SQL clauses. Implementation detail; not on stable surface.

---

## 9. Revision storage spec

### 9.1 Schema shape (sql-column)

Primary table `event`:

```
event(
  eid INTEGER PRIMARY KEY,
  uuid TEXT,
  bundle TEXT,
  langcode TEXT,
  vid INTEGER,  -- current-revision pointer
  -- field columns…
)
```

Revision table `event__revision`:

```
event__revision(
  vid INTEGER PRIMARY KEY,
  eid INTEGER,  -- FK to event.eid
  revision_created_at TEXT,
  revision_author INTEGER,
  revision_log TEXT,
  -- field columns (same shape as event)…
)
```

### 9.2 Save semantics

Per FR-032: every save creates a new revision by default. The new revision row is inserted; the primary row's `vid` updates to the new revision id last. Operation is wrapped in a transaction; partial failure raises `PartialSaveException`.

### 9.3 Load semantics

`load($entityType, $id)` returns the current revision (primary row's `vid` → revision row, hydrate). `loadRevision($entityType, $revisionId)` loads a specific revision regardless of current pointer.

### 9.4 List semantics

`listRevisions($entity)` returns revisions in descending `revision_created_at` order. Returns an iterable; pagination is consumer concern.

---

## 10. Migration semantics spec

### 10.1 Generator command

```
bin/waaseyaa make:storage-migration <entity_type> [--target=sql-column] [--no-reversible]
```

Generates: `migrations/<timestamp>_migrate_<entity_type>_to_<target>.php`.

### 10.2 Generated migration shape

The migration MUST:

1. CREATE the new schema (entity table + revision table if applicable) with a temporary name.
2. For each existing row in the source: extract `_data`, type-coerce per FR-012 mapping, INSERT into new schema.
3. If revisionable: create the initial revision row pointing at the current data, set primary's `vid` to that revision.
4. DROP the old table.
5. RENAME the temporary table to the canonical name.

All steps within a single transaction. On any failure, the transaction rolls back; the migration is reversible.

### 10.3 Failure modes

The generator MUST refuse with a typed error when:

- The entity type does not exist.
- Any field on the entity type has a backend other than `sql-blob` or `sql-column` (FR-045).
- The target backend matches the current backend (no-op migration).

---

## 11. Per-revision access spec

### 11.1 Policy declaration

```php
#[PolicyAttribute(entityType: 'teaching', operations: ['view', 'edit', 'view_revision'])]
final class TeachingAccessPolicy { ... }
```

If `view_revision` is declared, the policy method `viewRevision(Teaching $entity, AccountInterface $account, Revision $revision): bool` is consulted.

### 11.2 Fallback

Policies that don't declare `view_revision` fall back to `view`. The framework MUST NOT default-deny; this would silently break existing policies.

---

## 12. Test surface (FR-049…FR-052)

### 12.1 Backend-conformance suite

`Waaseyaa\Entity\Testing\BackendConformanceTestCase` is reusable. Future backend implementations (vector, remote) subclass and pass it. The suite covers:

- Single-field write → read round-trip.
- All FR-012 type mappings (for typed backends).
- Multi-field round-trip preserving values.
- Delete removes the row; subsequent reads return nothing.
- `supportsQuery()` semantics — backends advertising true MUST handle all FR-015 predicates without error; backends advertising false MUST raise `UnsupportedQueryException`.
- Error paths — write failure surfacing.

### 12.2 Coordinator integration tests

Separate suite. Covers:

- Multi-backend fan-out: an entity with one `sql-column` field and one `vector` field (mock vector backend) round-trips correctly.
- Partial-save error: mock vector backend fails after `sql-column` commits; coordinator raises `PartialSaveException` with both backend lists populated.
- Lifecycle event order: `BeforeSaveEvent` fires before any backend write; `AfterSaveEvent` fires after all writes; neither fires on partial-save failure.
- Abort semantics: `BeforeSaveEvent` subscriber throws `AbortOperationException`; no backend is contacted; coordinator re-throws.

### 12.3 Revision integration tests

Separate suite. Covers:

- Default save creates revision; current-revision pointer updates.
- `SaveContext::withoutNewRevision()` save updates current revision in place (no new revision row).
- `loadRevision` returns historical revision unmodified.
- `setCurrentRevision` rolls the entity back; subsequent `load` returns the rolled-back state.
- `view_revision` policy fallback to `view` when policy doesn't declare it.

### 12.4 Behavior-identity test (sql-blob refactor)

Pre-refactor: record baseline outputs for all Minoo entity-type CRUD operations against a fixture database. Post-refactor: replay the same operations through the new coordinator + `sql-blob` backend; outputs MUST be byte-identical.

---

## 13. Work package decomposition

Twelve WPs. Each names its primary FR coverage and its dependencies.

| WP | Title | Primary FRs | Depends on |
|---|---|---|---|
| **WP01** | Backend contract + registration | FR-001..FR-006 | — |
| **WP02** | Coordinator scaffold + fan-out skeleton (no events yet) | FR-017..FR-021 | WP01 |
| **WP03** | `sql-blob` backend refactor + behavior-identity tests | FR-007..FR-010, FR-049, FR-052 (partial) | WP01, WP02 |
| **WP04** | Lifecycle events in coordinator + PartialSaveException | FR-022..FR-027, FR-020, FR-046..FR-048 | WP02 |
| **WP05** | `sql-column` backend (non-revisionable) | FR-011..FR-016 | WP01, WP02 |
| **WP06** | Query support + UnsupportedQueryException at definition validation | FR-021, FR-046, FR-009, FR-014..FR-015 | WP03, WP05 |
| **WP07** | Revision schema design + EntityType opt-in | FR-028..FR-031 | WP05 |
| **WP08** | RevisionableEntityStorageInterface + load/list/setCurrent | FR-034..FR-037 | WP07 |
| **WP09** | Per-revision access (`view_revision` operation + fallback) | FR-038..FR-040 | WP08 |
| **WP10** | Storage-migration generator CLI | FR-041..FR-045 | WP05, WP07, WP08 |
| **WP11** | First Minoo entity migration (validation) + upgrade-guide pilot | FR-056 + validation across all FRs | WP10 |
| **WP12** | Backend-conformance suite + docs (`entity-system.md` update, `field-storage-backends.md` spec, upgrade-guide template) | FR-049..FR-055 | WP04, WP06, WP09 |

### 13.1 Sequencing diagram

```
WP01 ──► WP02 ──► WP03 ──► (behavior identity validated)
            │       │
            │       └────────────────┐
            ├──► WP04                │
            │      │                 │
            └──► WP05 ──► WP06 ◄─────┘
                    │
                    └──► WP07 ──► WP08 ──► WP09
                                    │
                                    └────► WP10 ──► WP11
                                                     │
                  All above ────────────────────────┴──► WP12 (closing)
```

### 13.2 Parallelizable pairs

After WP02 completes, WP04 and WP05 can run in parallel. After WP05, WP06 and WP07 can run in parallel. WP09 and WP10 can run in parallel after their respective dependencies. WP12 closes after all feature WPs.

### 13.3 Validation gate

WP11 is the validation pass: a real Minoo entity type migrated end-to-end through the new stack. The mission is not complete until WP11 ships green in Minoo's CI.

---

## 14. Acceptance criteria

The mission is complete when:

1. All 12 WPs are merged.
2. All FRs in §3 are covered by tests.
3. The backend-conformance test suite is green for `sql-blob` and `sql-column`.
4. WP11's Minoo migration ships in production and serves traffic for at least 7 days without a related incident.
5. Charter §3.2 criterion 8 ("revisions in production") is satisfiable — at least one revisionable entity type is shipping in Minoo.
6. Charter §5.3 stable-surface entries for this mission are reflected in `public-surface-map.md` and `public-surface-map.php` with both tier (`stable`) and mission-status (`present`) labels.
7. The first concrete upgrade guide exists at `docs/upgrades/waaseyaa-alpha-<X>-to-<Y>.md` per FR-056.

---

## 15. Minoo validation entity selection (WP11)

The validation entity type must:

- Have non-trivial query patterns (at least one filter + one sort) so `sql-column` query support is exercised.
- Have ≥5 fields covering at least 3 of the type-mapping rows in §8.2.
- Be small enough that migration completes in <60s on a production-sized dataset.
- Be tolerable to brief read-only mode during migration (no real-time write concurrency required).
- Be a candidate for revisioning (so WP11 also exercises ADR 016 end-to-end).

**Candidate: `teaching`.**

- ~50 fields covering string/text/datetime/int/json/bool.
- Active queries: by community, by category, by published-status, sort by created-at.
- Editorial flow ("Knowledge Keeper edits teaching") is the canonical revision use case in Minoo.
- Volume: low hundreds, not high thousands. Migration ≪60s.
- Decision: WP11 targets `teaching` unless WP10 surfaces a generator limitation that requires a different validation type.

Alternates (in case `teaching` is unsuitable): `event`, `cultural_collection`. Engagement entities (audit's original M3 scope) are explicitly NOT the validation type — too high-volume for a first migration; will be migrated as a follow-up after WP11 proves the pattern.

---

## 16. Open questions

Mission-specific, in addition to charter §11.

1. **Backend-registration order across packages** — the coordinator writes alternate-backend fields "in registration order" (§6.2). How is order determined across multiple packages registering backends? Recommended: package install order (composer's `installed.json`), with an explicit `priority: int` override available. Decide before WP01.
2. **Schema migration framework reuse** — `bin/waaseyaa migrate` already exists. Does the storage-migration generator (WP10) emit migrations that ride that system, or does it need its own runner? Recommended: ride existing system; no parallel runner.
3. **Reversible migration limit** — FR-043 makes reversibility the default. For large entity types, the reverse migration may be unacceptably slow. Recommended: keep default; add a per-migration `expectedReverseSeconds` docblock annotation that warns operators at apply time.
4. **`sql-column` to `sql-column` schema evolution** — once an entity type is on `sql-column`, adding/removing fields requires schema migrations. Is that covered by `schema-evolution-v2.md` (mission #529), or does this mission need to coordinate? Recommended: coordinate; cite #529's migration manifest as the substrate.
5. **Revision pruning service** — ADR 016 ships `RevisionPruner` as a disabled service. This mission lands the class; first apps to configure it are out of scope. Confirm scope.
6. **`SaveContext` object** — FR-033 introduces `SaveContext::withoutNewRevision()`. Is this a new value-object class on the stable surface, or a flags array on existing `save()`? Recommended: dedicated `SaveContext` class — clearer API, extensible to future flags (e.g. `withoutEvents()`).

---

## 17. References

- [`stability-charter.md`](stability-charter.md) — governing API stability rules.
- [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) §1.1, §3.1, §6.1, §6.7 — mission origin.
- [ADR 010](../adr/010-multi-backend-field-storage.md), [ADR 011](../adr/011-entity-lifecycle-events.md), [ADR 016](../adr/016-revisions-first-class.md) — governing decisions.
- [ADR 009](../adr/009-migration-manifest-discovery.md) — migration manifest format; relevant to WP10.
- [`schema-evolution-v2.md`](schema-evolution-v2.md) — sibling mission; spec format template; relevant to open question §16.4.
- 2026-05-11 framework/app audit (`waaseyaa/minoo/docs/audits/2026-05-11-framework-app-audit.md`) — original M3 origin and findings F1 and F8.
- [`public-surface-map.md`](../public-surface-map.md) — Layer 1 `entity-storage` and `entity` packages; updated by this mission per §4.

---

## 18. Mission metadata for Spec Kitty

```yaml
mission:
  id: TBD
  title: Entity Storage v2 — Multi-Backend Storage with Revisions
  status: draft-spec
  governing_adrs: [010, 011, 016]
  charter_dependencies:
    - section: §5.3
      relation: governs
    - section: §3.2.7
      relation: contributes-to-beta-gate
    - section: §3.2.8
      relation: contributes-to-beta-gate
  validation_consumer: minoo
  validation_entity_type: teaching  # provisional; see §15
  work_packages: 12
  parallelizable_after_wp02: true
  estimated_breaking_change_count: 0  # additive surface; sql-blob preserves behavior
  agent_assignments:
    implementer: sonnet
    reviewer: opus
```
