# Waaseyaa Alpha X → Y Upgrade Guide

> **Filename note:** `X` and `Y` are substituted with the actual alpha tags at
> release-cut time (e.g. `waaseyaa-alpha-0.1.0-alpha.165-to-0.1.0-alpha.170.md`).
> Use the filename as-is during development.

This guide covers the stable-surface additions introduced by mission
`entity-storage-v2-01KRCDDC` (M-001, WP01–WP11). It targets consumers who run
Waaseyaa-backed applications. Framework maintainers will find deeper detail in the
work-package review files under
`kitty-specs/entity-storage-v2-01KRCDDC/reviews/`.

---

## 1. Stable-surface deltas

The following symbols are new `@api`-annotated additions. Existing symbols are
unchanged — see §7 for backwards-compatibility notes.

### 1.1 Backend registration (WP01, WP06)

| Symbol | Package | Notes |
|---|---|---|
| `BackendRegistrar` | `waaseyaa/entity-storage` | Register field-storage backends by id |
| `BackendRegistrarFactory` | `waaseyaa/entity-storage` | Creates a `BackendRegistrar` bound to a specific entity type |
| `IsFrameworkBackendProviderInterface` | `waaseyaa/entity-storage` | Marker for built-in backend providers; do not implement in application code |
| `HasFieldStorageBackendsInterface` | `waaseyaa/entity-storage` | Mix-in for entity types that participate in multi-backend fan-out |
| `UnsupportedQueryException` | `waaseyaa/entity-storage` | Thrown when a query operator is not supported by the active backend |
| `UnsupportedListingException` | `waaseyaa/entity-storage` | Thrown when listing is not supported by the active backend |

### 1.2 Coordinator and resolver (WP02)

| Symbol | Package | Notes |
|---|---|---|
| `EntityStorageCoordinator` | `waaseyaa/entity-storage` | Orchestrates fan-out across all registered backends |
| `BackendResolver` | `waaseyaa/entity-storage` | Resolves the correct backend for a field definition at runtime |
| `UnknownBackendException` | `waaseyaa/entity-storage` | Thrown by `BackendResolver` when no backend is registered for the requested id |

### 1.3 SQL blob backend (WP03)

| Symbol | Package | Notes |
|---|---|---|
| `SqlBlobBackend` | `waaseyaa/entity-storage` | Refactored legacy `_data` JSON blob backend; behaviour-identical drop-in replacement |

### 1.4 Lifecycle events (WP04)

| Symbol | Package | Notes |
|---|---|---|
| `EntityLifecycleEventInterface` | `waaseyaa/entity-storage` | Marker for all four lifecycle events |
| `BeforeSaveEvent` | `waaseyaa/entity-storage` | Dispatched before write; listeners may throw `AbortOperationException` |
| `AfterSaveEvent` | `waaseyaa/entity-storage` | Dispatched after all backends commit; NOT dispatched on partial failure |
| `BeforeDeleteEvent` | `waaseyaa/entity-storage` | Dispatched before delete |
| `AfterDeleteEvent` | `waaseyaa/entity-storage` | Dispatched after all backends confirm delete |
| `AbortOperationException` | `waaseyaa/entity-storage` | Throw from a `BeforeSave`/`BeforeDelete` listener to abort the operation |
| `PartialSaveException` | `waaseyaa/entity-storage` | See §6; note `$errorCode` not `$code` — see §1.4 note below |
| `SaveContext` | `waaseyaa/entity-storage` | Immutable value object passed to save operations; carries revision flags |
| `CoordinatorLifecycleDispatcher` | `waaseyaa/entity-storage` | Dispatches lifecycle events from the coordinator |

**Why `$errorCode` and not `$code`:** PHP forbids redeclaring `\Exception::$code`
(a non-readonly `protected int`) with a typed property in any subclass — the
compiler rejects `public readonly string $code` with "Type of X::$code must be
omitted to match the parent definition". The typed `public readonly string
$errorCode` is therefore the canonical name on the stable surface (spec §6.5,
`contracts/partial-save-error.md`).

### 1.5 SQL column backend (WP05)

| Symbol | Package | Notes |
|---|---|---|
| `SqlColumnBackend` | `waaseyaa/entity-storage` | Typed-column backend; replaces JSON blob storage per field |
| `SqlColumnSchemaBuilder` | `waaseyaa/entity-storage` | Builds DDL for typed columns |
| `SqlColumnQueryTranslator` | `waaseyaa/entity-storage` | Translates `EntityQuery` conditions to DBAL expressions |
| `TypeMapping` | `waaseyaa/entity-storage` | Maps Waaseyaa field types to DBAL column types (spec §8.2) |

### 1.6 Definition validator (WP06)

| Symbol | Package | Notes |
|---|---|---|
| `DefinitionValidator` | `waaseyaa/entity-storage` | Validates `EntityType` + `FieldDefinition` graphs at boot |

### 1.7 Revisionable entities (WP07)

| Symbol | Package | Notes |
|---|---|---|
| `RevisionableEntityInterface` | `waaseyaa/entity` | Implement on entity classes that support revisions |
| `RevisionableEntityTrait` | `waaseyaa/entity` | Default implementations for `revisionId()`, `isCurrentRevision()`, `revisionMetadata()` |
| `RevisionMetadata` | `waaseyaa/entity` | Value object: author, timestamp, log message |
| `EntityType::$revisionable` | `waaseyaa/entity` | New opt-in constructor param; see §4 |
| `EntityType::$primaryStorageBackend` | `waaseyaa/entity` | New opt-in constructor param; names the primary backend id |
| `RevisionTableBuilder` | `waaseyaa/entity-storage` | Builds the `{entity_type}_revision` DDL table |

### 1.8 Revisionable storage (WP08)

| Symbol | Package | Notes |
|---|---|---|
| `RevisionPruningPolicy` | `waaseyaa/entity-storage` | Value object: retention rules for the pruner |
| `RevisionPruningReport` | `waaseyaa/entity-storage` | Value object: outcome of a prune run |

### 1.9 Revision access (WP09)

| Symbol | Package | Notes |
|---|---|---|
| `GateInterface::VIEW_REVISION` | `waaseyaa/access` | New constant (`'view_revision'`) for revision access |
| `PolicyAttribute::$operations` | `waaseyaa/access` | New constructor param; enables boot-time method validation |
| `RevisionAccessRouter` | `waaseyaa/access` | Routes `view_revision` checks; falls back to `view()` if not declared |

### 1.10 Migration CLI (WP10)

| Symbol | Package | Notes |
|---|---|---|
| `make:storage-migration` command | `waaseyaa/cli` | Generates a typed-column migration file for an entity type |

---

## 2. No changes required for most consumers

If your application has entity types with none of `revisionable: true`,
`primaryStorageBackend`, or multi-backend `FieldDefinition::storedIn()` calls, you
do not need to change anything. The default storage path (sql-blob + single-backend
coordinator) is behaviour-identical to the pre-mission path. See §7 for details.

---

## 3. sql-blob → sql-column migration recipe

This section walks you through migrating an entity type from the legacy `_data` JSON
blob backend to the new `sql-column` backend. Run the steps in order.

### 3.1 Prerequisites

- Waaseyaa CLI available at `bin/waaseyaa`.
- Entity type registered with named `FieldDefinition` objects (not bare arrays).
- A writable migrations directory (default: `migrations/`).

### 3.2 Generate the migration

```bash
bin/waaseyaa make:storage-migration <entity_type_id>
```

Inspect what will be generated without writing a file:

```bash
bin/waaseyaa make:storage-migration <entity_type_id> --dry-run
```

Example dry-run output:

```
[dry-run] Would write: migrations/20260512_000000_teaching_sql_column.php
  - Add column: community_id (integer, nullable)
  - Add column: category_id (integer, nullable)
  - Add column: published_at (datetime, nullable)
  - Add index: community_id
  - Add index: category_id
  - Add index: published_at
  - Backfill: 3 field(s) from _data JSON blob
  - Row-count validation: expected N rows before and after
```

If a migration file already exists for this entity type, pass `--force` to
overwrite:

```bash
bin/waaseyaa make:storage-migration <entity_type_id> --force
```

Exit codes: `0` ok, `1` unknown entity type, `2` invalid `--target`, `3` file
already exists (use `--force`), `4` field type with no §8.2 mapping (route it via
`FieldDefinition::storedIn('<backend_id>')`).

Full exit-code reference:
`kitty-specs/entity-storage-v2-01KRCDDC/contracts/migration-generator-cli.md`

### 3.3 Review the generated migration

Open the emitted file under `migrations/`. Verify:

- All expected fields appear in `up()`.
- Indexed fields have `ADD INDEX` statements.
- `down()` drops the typed columns and leaves `_data` intact — the backfill data
  stays in the blob column for rollback safety.
- The row-count assertion matches your fixture data.

### 3.4 Apply the migration

```bash
bin/waaseyaa migrate
```

The migration backfills data from `_data` into the new typed columns. Existing rows
are updated; the `_data` blob is not deleted (it is the rollback safety net).

### 3.5 Verify

Query the entity type via `EntityStorageCoordinator` (or `EntityRepository`) and
confirm results match your pre-migration baseline. The sql-column backend reads from
typed columns, not `_data`, so indexed field queries should show improved
performance.

### 3.6 Rollback

```bash
bin/waaseyaa migrate:rollback
```

The generated `down()` drops typed columns. The coordinator re-resolves the backend
at next boot and falls back to `_data` JSON blob. See §8 for more detail.

---

## 4. Revision opt-in steps

Revisions are opt-in. Existing entity types without `revisionable: true` are
unaffected.

### 4.1 Update the EntityType definition

```php
use Waaseyaa\Entity\EntityType;

new EntityType(
    id: 'teaching',
    label: 'Teaching',
    class: Teaching::class,
    keys: [
        'id'       => 'tid',
        'uuid'     => 'uuid',
        'revision' => 'vid',   // REQUIRED when revisionable: true
    ],
    revisionable: true,
    primaryStorageBackend: 'sql-column',  // optional; defaults to 'sql-blob'
)
```

`EntityType` throws `\InvalidArgumentException` at construction time if
`revisionable: true` is set but `entityKeys['revision']` is absent or empty.

### 4.2 Implement `RevisionableEntityInterface` on the entity class

```php
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\RevisionableEntityInterface;
use Waaseyaa\Entity\RevisionableEntityTrait;

final class Teaching extends ContentEntityBase implements RevisionableEntityInterface
{
    use RevisionableEntityTrait;

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'teaching', [
            'id'       => 'tid',
            'uuid'     => 'uuid',
            'revision' => 'vid',
        ]);
    }
}
```

`RevisionableEntityTrait` provides default implementations for `revisionId()`,
`isCurrentRevision()`, and `revisionMetadata()`. You do not need to write those
methods manually.

### 4.3 Generate and apply the schema migration

Enabling revisions adds a `{entity_type}_revision` table. The migration generator
emits revision table DDL automatically when the entity type is revisionable:

```bash
bin/waaseyaa make:storage-migration teaching
bin/waaseyaa migrate
```

### 4.4 In-place save vs. new-revision save

By default every `save()` creates a new revision row. To update the current
revision in place — for auto-save, status transitions, or bulk imports — without
cutting a new revision record, pass a `SaveContext`:

```php
use Waaseyaa\EntityStorage\SaveContext;

// Default: creates a new revision row on every save.
$coordinator->save($entity);

// Suppress revision creation for this one save.
$ctx = SaveContext::default()->withoutNewRevision();
$coordinator->save($entity, $ctx);
```

`SaveContext` is immutable — `withoutNewRevision()` returns a new instance and
leaves the original unchanged.

---

## 5. `view_revision` policy template

If you want per-policy control over revision access, add `'view_revision'` to the
`#[PolicyAttribute]` declaration. `RevisionAccessRouter` then calls your
`viewRevision()` method; without that declaration access falls back to `view()`
(open-by-default, no implicit deny).

### 5.1 Policy with explicit `view_revision`

```php
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\GateInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\RevisionMetadata;

#[PolicyAttribute(entityType: 'teaching', operations: ['view_revision'])]
final class TeachingPolicy implements AccessPolicyInterface
{
    public function access(
        EntityInterface $entity,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($operation === GateInterface::VIEW) {
            return $entity->get('published') ? AccessResult::allowed() : AccessResult::neutral();
        }

        return AccessResult::neutral();
    }

    /**
     * Decide whether $account may view a specific revision of $entity.
     *
     * Called by RevisionAccessRouter when GateInterface::VIEW_REVISION is checked.
     * Return Neutral (not Forbidden) when you have no opinion — the framework
     * will not interpret Neutral as a deny.
     */
    public function viewRevision(
        EntityInterface $entity,
        AccountInterface $account,
        RevisionMetadata $revision,
    ): AccessResult {
        // Example: only the author or an admin may view non-current revisions.
        if ($account->hasPermission('administer teaching')) {
            return AccessResult::allowed();
        }

        if ((string) $account->id() === (string) $entity->get('uid')) {
            return AccessResult::allowed();
        }

        return AccessResult::neutral();
    }
}
```

### 5.2 Fallback behaviour

If your policy does NOT declare `viewRevision()` (or does not list `'view_revision'`
in `operations`), `RevisionAccessRouter` silently falls back to `view()` and emits a
structured log line on channel `entity.lifecycle` with
`outcome=view_revision_fallback`. This is not an error — it is the correct
open-by-default behaviour for policies that have not yet been updated to declare
revision access rules.

### 5.3 Boot-time validation

Declaring `operations: ['view_revision']` in `#[PolicyAttribute]` triggers a
boot-time check via `PolicyAttribute::validate()`. If `viewRevision()` is missing,
the kernel throws `\LogicException` at boot — not at access-check time. This makes
misconfiguration fail loudly during development rather than silently in production.

---

## 6. Partial-save recovery patterns

When an entity type has multiple backends registered (e.g. sql-column + a custom
vector backend) a backend failure mid-fan-out raises `PartialSaveException`.

```php
use Waaseyaa\EntityStorage\Exception\PartialSaveException;

try {
    $coordinator->save($entity);
} catch (PartialSaveException $e) {
    // Backends that completed before the failure:
    $committed   = $e->committedBackends;    // e.g. ['sql-column']
    // Backends that did not run (includes the failing one):
    $uncommitted = $e->uncommittedBackends;  // e.g. ['vector']

    // The original throwable from the failing backend:
    $cause = $e->causedBy;

    // Machine-readable code is always 'PARTIAL_SAVE':
    $code = $e->errorCode;

    $logger->error('Partial save', [
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id'   => (string) $entity->id(),
        'committed'   => $committed,
        'uncommitted' => $uncommitted,
        'error'       => $cause->getMessage(),
    ]);

    // Recovery is application responsibility.
    // The coordinator does NOT attempt rollback.
}
```

**Key invariants:**

- `AfterSaveEvent` is NOT dispatched when `PartialSaveException` is thrown.
- The coordinator does not roll back committed backends — cross-backend atomicity
  is not achievable for arbitrary backends such as vector stores or remote services.
- A structured log line on `entity.lifecycle` with `outcome=partial_save` is
  always emitted, even if your catch block does nothing.
- Recovery options: retry the save (safe if committed backends use upsert
  semantics), reconcile the partial state offline, or alert and skip.

**Idempotent retry guidance:** `sql-column` and `sql-blob` both use upsert
semantics on `save()` — retrying an already-committed backend is safe. Vector and
remote backends may not be idempotent; check their contracts before retrying.

For the full coordinator contract see
`kitty-specs/entity-storage-v2-01KRCDDC/contracts/partial-save-error.md`.

---

## 7. Backwards-compatibility notes

The following things do NOT change in this upgrade:

- Entity types registered without `revisionable: true` continue to work with no
  modification. No revision tables are created, no revision keys are required.
- `PolicyAttribute` without an `operations` array continues to work. The new
  `operations` param defaults to `[]` — no boot-time method validation runs.
- `EntityRepository`, `EntityTypeManager`, and `SqlEntityStorage` (the pre-coordinator
  path) continue to work for codebases that have not yet adopted the coordinator.
  The coordinator is additive, not a replacement for the existing storage layer.
- The `_data` JSON blob column is not removed by the migration. It stays as a
  rollback safety net and remains the active storage for fields not explicitly
  configured for sql-column.
- `AccessPolicyInterface` without `viewRevision()` works correctly — revision
  access falls back to `view()` (see §5.2).
- The `make:storage-migration` command only generates files; it does not run them.
  Existing migrations are unaffected.

---

## 8. Rollback plan

### 8.1 Rollback a storage migration

```bash
bin/waaseyaa migrate:rollback
```

The generated `down()` drops typed columns added by `up()`. The coordinator
re-resolves the backend at next boot and falls back to `_data` JSON blob. Data loss
does not occur because `_data` was not modified by the migration.

### 8.2 Backfill mismatch causes auto-rollback

The generated migration includes a row-count assertion. If the row count after
backfill does not match the count before, the migration runner rolls back
automatically and exits non-zero. Check the migration log for `backfill_mismatch`
before retrying.

### 8.3 Reverting `revisionable: true`

Reverting a revisionable entity type back to non-revisionable requires:

1. Generate a manual migration to drop the `{entity_type}_revision` table.
2. Apply it: `bin/waaseyaa migrate`.
3. Remove the `revision` key from `entityKeys` in the `EntityType` definition.
4. Remove `revisionable: true` from the `EntityType` constructor call.
5. Remove `RevisionableEntityInterface` and `RevisionableEntityTrait` from the
   entity class.

This is a destructive operation — revision history is not preserved once the
revision table is dropped.

---

## 9. Lessons from the first Minoo rollout — pending live cycle

The first real-world validation of this upgrade path (running the above steps
against Minoo's `teaching` entity type in a production environment) is tracked
separately. That validation cycle could not be completed in this session because it
involves a separate repository (`waaseyaa-minoo`) and requires a 7-day monitoring
window.

Deferred tasks and their exit criteria are documented in:

```
kitty-specs/entity-storage-v2-01KRCDDC/validation/pending-minoo-cycle.md
```

Until that file is marked closed, treat this upgrade guide as validated against
the framework test suite only, not against a live production environment.

---

## References

- `kitty-specs/entity-storage-v2-01KRCDDC/contracts/migration-generator-cli.md`
- `kitty-specs/entity-storage-v2-01KRCDDC/contracts/lifecycle-events.md`
- `kitty-specs/entity-storage-v2-01KRCDDC/contracts/revisionable-entity.md`
- `kitty-specs/entity-storage-v2-01KRCDDC/contracts/partial-save-error.md`
- `kitty-specs/entity-storage-v2-01KRCDDC/contracts/field-storage-backend.md`
- `kitty-specs/entity-storage-v2-01KRCDDC/spec.md` §6.5 (partial save), §8.2 (type mappings), §11.2 (revision access)
