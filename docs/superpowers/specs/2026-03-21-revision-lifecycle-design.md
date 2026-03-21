# Revision Lifecycle Design — v1.7

**Issue:** #510 — Design: define revision lifecycle for RevisionableInterface entities
**Milestone:** v1.7 — Revision System
**Date:** 2026-03-21

## Overview

Defines the lifecycle rules for revision-capable entities in Waaseyaa. This is a framework capability — consumer apps opt in per entity type via `revisionable: true`. The design separates revision history tracking from content moderation (draft/published), which belongs in the `workflows` package.

## Prerequisite Interface Changes

These changes to existing interfaces are required before implementation:

### EntityTypeInterface / EntityType

Add `revisionDefault` property:

```php
// EntityTypeInterface — new method
public function getRevisionDefault(): bool;

// EntityType constructor — new parameter
new EntityType(
    // ... existing params ...
    revisionable: true,
    revisionDefault: true,  // NEW: whether saves create revisions by default
);
```

### RevisionableInterface

Extend with mutation methods for per-save override:

```php
interface RevisionableInterface
{
    public function getRevisionId(): int|null;          // narrowed from int|string|null
    public function isDefaultRevision(): bool;
    public function isLatestRevision(): bool;
    public function setNewRevision(bool $value): void;  // NEW
    public function isNewRevision(): ?bool;             // NEW: null = not set (use type default)
    public function setRevisionLog(?string $log): void; // NEW
    public function getRevisionLog(): ?string;          // NEW
}
```

### RevisionableStorageInterface

All methods that identify revisions require both `entityId` and `revisionId` because revision IDs are unique per entity (composite PK), not globally unique:

```php
interface RevisionableStorageInterface extends EntityStorageInterface
{
    public function loadRevision(int|string $entityId, int $revisionId): ?EntityInterface;
    public function loadMultipleRevisions(int|string $entityId, array $revisionIds): array;
    public function deleteRevision(int|string $entityId, int $revisionId): void;
    public function getLatestRevisionId(int|string $entityId): ?int;
    public function getRevisionIds(int|string $entityId): array;  // NEW: returns int[], ascending
}
```

**Breaking change:** `loadRevision`, `loadMultipleRevisions`, and `deleteRevision` gain an `$entityId` parameter. `getLatestRevisionId` return type narrowed to `?int`. These interfaces have zero implementations, so the break is safe.

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Revision creation default | Configurable per entity type (`revisionDefault`), caller may override per save | Flexibility without ambiguity |
| Rollback semantics | Copy-forward with log annotation | Append-only history, no data loss, auditable |
| State model | Two states: default / non-default | Minimal; editorial workflow deferred to `workflows` package |
| Default pointer | `revision_id` column on base table | Single-row consistency, no multi-row invariant management |
| Revision table PK | Composite `(entity_id, revision_id)` | Database enforces uniqueness-per-entity invariant directly |
| Concurrency model | Last-write-wins (v1.7) | Optimistic locking deferred to future milestone |

## A) Revision Lifecycle States

| State | Meaning |
|---|---|
| **default** | The revision returned by `find($entityId)`. Exactly one per entity, always. |
| **non-default** | Historical. Loadable via `loadRevision($entityId, $revisionId)` but never returned by default queries. |

The default flag is not stored as a boolean on each revision row. Instead, the base table holds a `revision_id` foreign key pointing to the current default revision. This avoids multi-row consistency problems.

## B) State Transition Rules

| Transition | Creates new revision? | Updates default pointer? | Legal? |
|---|---|---|---|
| Create entity | Yes (rev 1) | Yes → rev 1 | Always |
| Save, new revision = true | Yes (rev N+1) | Yes → N+1 | Always |
| Save, new revision = false | No | No (stays at current) | Legal — updates current default revision in place |
| Rollback to rev X | Yes (rev N+1, copy of X's values) | Yes → N+1 | X must exist and belong to this entity |
| Delete revision R | No | No | Only if R is non-default |
| Delete entity | No | N/A — all revisions removed | Always |

### Illegal transitions

- **Delete the default revision.** Delete the entity instead.
- **Set a non-default revision as default without copy-forward.** Use rollback.
- **Create a revision for a non-revisionable entity type.** Storage must throw `\LogicException`.

## C) Revision Creation Semantics

### Resolution logic

```
if entity type is not revisionable:
    newRevision = false (always, regardless of caller)

if entity is new (first save):
    newRevision = true (always, creates rev 1)

otherwise:
    newRevision = entity->isNewRevision()    // caller override, if set
                  ?? entityType->revisionDefault  // type default
```

### Per-trigger behavior

| Trigger | New revision? | revision_log |
|---|---|---|
| Initial creation | Always yes → rev 1 | Caller-provided or empty |
| Normal save (default=true, no override) | Yes | Caller-provided or empty |
| Normal save (default=false, no override) | No — updates in place | N/A |
| Caller sets `setNewRevision(true)` | Yes, regardless of type default | Caller-provided or empty |
| Caller sets `setNewRevision(false)` | No, regardless of type default | N/A |
| Rollback to rev X | Always yes | Auto: `"Reverted to revision {X}"` — caller may append but not replace |
| Delete entity | No revision created | N/A |

### Confirmation: rollback + in-place update

When `setNewRevision(false)` is used after a rollback, the updated-in-place semantics apply to the *new* default revision (the copy-forward result). Historical rows remain immutable.

## D) Invariants

The storage layer **must** enforce all of these. Application code cannot violate them.

1. **Single default.** Every revisionable entity has exactly one default revision at all times. No zero, no two.
2. **Monotonic IDs.** Revision IDs are monotonically increasing integers per entity, starting at 1. Gaps are allowed (after deletion), reuse is forbidden.
3. **Immutable history.** Field values and metadata (`revision_created`, `revision_log`) in non-default revisions are immutable. Only the current default revision may be updated in place (when `newRevision = false`). In-place updates modify field values only — `revision_created` and `revision_log` are preserved even on the default revision.
4. **Atomic pointer update.** Creating a new revision and updating the default pointer happen in a single transaction. No intermediate state where the pointer is stale.
5. **Timestamp fidelity.** `revision_created` is set once when the revision row is inserted, never updated.
6. **Log fidelity.** `revision_log` is set once at creation. Rollback auto-annotates; the annotation is part of the created value, not a later mutation.
7. **Default resolution.** `find($entityId)` returns the entity hydrated from the default revision. `loadRevision($entityId, $revisionId)` returns any revision. `findBy()` queries operate on default revisions only.
8. **Protected default.** The default revision cannot be deleted. To remove it, delete the entity (which cascades all revisions).
9. **Type gating.** Calling revision methods on a non-revisionable entity type throws `\LogicException`. The storage layer checks `entityType->isRevisionable()`.
10. **Rollback is creation.** Rollback produces a new revision. It never mutates or reorders existing revision rows.

## E) Storage Responsibilities

### Contract

| Responsibility | Contract |
|---|---|
| Revision table management | Separate `{entity_table}_revision` table holding all revision rows. Base table holds current field values + `revision_id` pointer. |
| Atomic save | `save()` wraps revision-row INSERT + base-table UPDATE (pointer) in a transaction. |
| Revision ID generation | `MAX(revision_id) + 1` within the transaction, scoped to the entity ID. |
| Rollback | `rollback($entityId, $targetRevisionId)` lives on `EntityRepository` (not storage interface). Loads target revision, creates new revision with those values, sets log annotation, updates pointer. Single transaction. |
| Load semantics | `find($id)` reads base table (default). `loadRevision($entityId, $revId)` reads revision table. `loadMultipleRevisions($entityId, $ids)` batch reads from revision table. |
| Retrieval helpers | `getLatestRevisionId($entityId)` returns MAX revision_id (int) for entity. `getRevisionIds($entityId)` returns all revision IDs as `int[]` in ascending order. |
| Delete revision | `deleteRevision($entityId, $revId)` removes row from revision table. Throws if revision is current default. |
| Delete entity | Cascade — delete all revision rows, then base row. |
| Concurrency | v1.7: last-write-wins. No optimistic locking. |
| Event timing | `REVISION_CREATED` and `REVISION_REVERTED` fire **after** the transaction commits. Listeners must not assume they can roll back the revision. |

### Schema shape

```sql
-- Base table (existing, add column)
ALTER TABLE {entity_table}
  ADD COLUMN revision_id INTEGER NOT NULL;

-- Revision table (new)
CREATE TABLE {entity_table}_revision (
  entity_id    VARCHAR(128) NOT NULL,   -- FK to base table PK
  revision_id  INTEGER NOT NULL,         -- monotonic per entity, starting at 1
  revision_created DATETIME NOT NULL,    -- immutable, set on INSERT
  revision_log TEXT NULL,                -- immutable, set on INSERT
  -- all field columns (mirrors base table structure)
  _data TEXT NULL,                       -- JSON blob (same pattern as base table)
  PRIMARY KEY (entity_id, revision_id),
  FOREIGN KEY (entity_id) REFERENCES {entity_table}(id) ON DELETE CASCADE
);

CREATE INDEX idx_{entity_table}_rev_entity
  ON {entity_table}_revision (entity_id, revision_id DESC);
```

The composite PK `(entity_id, revision_id)` directly encodes the invariant "revision IDs are unique per entity" in the schema. The descending index supports efficient "get latest revision" queries.

## F) Minimal Example

Entity type: `node` with `revisionable: true, revisionDefault: true`.

### Step 1 — Create

```
Action:  $repo->save(new Node(['title' => 'Hello']))
Result:  base row created, revision row created

base:      { nid: 1, revision_id: 1, title: 'Hello' }
rev table: [
  { entity_id: 1, revision_id: 1, title: 'Hello', revision_log: null, revision_created: T1 }
]
default → rev 1
```

### Step 2 — Edit (new revision, type default)

```
Action:  $node->set('title', 'Hello World'); $repo->save($node);
Result:  new revision row, base pointer updated

base:      { nid: 1, revision_id: 2, title: 'Hello World' }
rev table: [
  { entity_id: 1, revision_id: 1, title: 'Hello',       revision_created: T1 },
  { entity_id: 1, revision_id: 2, title: 'Hello World',  revision_created: T2 },
]
default → rev 2
```

### Step 3 — Edit again

```
Action:  $node->set('title', 'Greetings'); $repo->save($node);

base:      { nid: 1, revision_id: 3, title: 'Greetings' }
rev table: [rev 1, rev 2,
  { entity_id: 1, revision_id: 3, title: 'Greetings', revision_created: T3 }
]
default → rev 3
```

### Step 4 — Rollback to rev 1

```
Action:  $repo->rollback($node->id(), targetRevisionId: 1);
Result:  new rev 4 created with rev 1's field values, log auto-annotated

base:      { nid: 1, revision_id: 4, title: 'Hello' }
rev table: [rev 1, rev 2, rev 3,
  { entity_id: 1, revision_id: 4, title: 'Hello',
    revision_log: 'Reverted to revision 1', revision_created: T4 }
]
default → rev 4
```

### Step 5 — Edit with explicit no-revision override

```
Action:  $node->setNewRevision(false); $node->set('title', 'Hello!'); $repo->save($node);
Result:  rev 4 updated in place, no new revision row

base:      { nid: 1, revision_id: 4, title: 'Hello!' }
rev table: [rev 1, rev 2, rev 3,
  { entity_id: 1, revision_id: 4, title: 'Hello!', ... }
]
default → rev 4 (same pointer, updated values)
```

## Implementation Contract for #512

The implementation issue must deliver:

0. **Interface changes** — apply all changes from the "Prerequisite Interface Changes" section above: extend `EntityTypeInterface`/`EntityType` with `revisionDefault`, extend `RevisionableInterface` with mutation methods, update `RevisionableStorageInterface` signatures to require `$entityId`
1. **`RevisionableEntityTrait`** — implements `RevisionableInterface` on entity classes (`getRevisionId()`, `isDefaultRevision()`, `isLatestRevision()`, `setNewRevision()`, `isNewRevision()`, `setRevisionLog()`, `getRevisionLog()`)
2. **`SqlRevisionableStorageDriver`** (or extension of `SqlStorageDriver`) — implements `RevisionableStorageInterface` with the schema and transaction semantics defined above
3. **`EntityRepository` revision awareness** — delegates to revisionable driver when `entityType->isRevisionable()`, dispatches revision events. Owns the `rollback($entityId, int $targetRevisionId): EntityInterface` method (orchestrates load → copy → save → event)
4. **`SqlSchemaHandler` revision table creation** — auto-creates `{table}_revision` when entity type is revisionable
5. **Two new events**: `EntityEvents::REVISION_CREATED` and `EntityEvents::REVISION_REVERTED`
6. **Migration path** — `TableBuilder::revisionColumns()` already exists; add a migration helper for creating revision tables from existing base tables. **Data migration for existing rows:** when enabling revisions on an entity type with existing data, the migration must (a) create the `{table}_revision` table, (b) seed a revision 1 row for each existing base row by copying current field values, (c) add `revision_id = 1` to each base row. This is a one-time data migration, not an ongoing concern.

## Non-goals for v1.7

Explicitly deferred to future milestones:

- Content moderation / draft-published workflow (→ `workflows` package)
- Optimistic locking / conflict detection
- Revision pruning / retention policies
- Revision diffing
- Revision access control (all revisions readable if entity is readable)
