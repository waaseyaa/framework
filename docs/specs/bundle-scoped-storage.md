# Bundle-scoped storage

**Status:** Active — lands with `waaseyaa/groups` extraction.

**Audience:** Framework contributors modifying `waaseyaa/entity-storage` and the `waaseyaa/foundation` diagnostic layer; authors of multi-bundle entity types beyond `waaseyaa/groups` (future candidates include `waaseyaa/node`, `waaseyaa/taxonomy`, `waaseyaa/media`).

## Principle

Multi-bundle `EntityType`s — those declaring `bundleEntityType` on construction — store bundle-scoped columns in per-bundle **subtables** rather than as nullable union columns on the base table, and not in the `_data` JSON blob.

A field is bundle-scoped if its `FieldDefinition::$targetBundle` is non-null; see [`bundle-scoped-fields.md`](./bundle-scoped-fields.md) for the field-resolution semantics that produce this partition.

This spec defines the storage shape only. Field registration, query-time routing, and access scoping are covered in the companion spec.

## Naming

Subtable name: `{base_table}__{bundle}`.

Examples:

- `group__business` — stores bundle-scoped fields for `Group(type=business)`.
- `group__organization` — would store bundle-scoped fields for `Group(type=organization)`.

The double-underscore delimiter is reserved for this purpose. Bundle identifiers must not contain `__`.

The naming rule and the `__`-in-bundle-id guard are codified in a single helper consumed by `SqlSchemaHandler`, `SqlEntityStorage`, and `SqlEntityQuery`; see [`entity-system.md` §EntityTypeManagerInterface](./entity-system.md) for the registration-time guard at `EntityTypeManager::addBundleFields()`. (Mission #1257 K1.)

## Ownership

The **base table** owns:

- All entity keys as declared in `EntityType::$keys` (`id`, `uuid`, `bundle`, `label`, `langcode`).
- The `_data` JSON blob column.
- All fields declared with `targetBundle: null` (core fields — apply to every bundle of the entity type).
- Timestamp columns (`created`, `changed`) when present.

Each **subtable** owns:

- The entity's id key as its own primary key (1:1 correspondence with the base row).
- All fields declared with `targetBundle: '{bundle}'` matching the subtable's bundle.

A Data-backed bundle field named by a declared bundle unique key is promoted to
column storage in the process-local field registry. Schema sync backfills its
old base `_data` values into the typed subtable column; all subsequent query and
write routing uses that one canonical column, preserving the no-dual-source
invariant.

Two invariants are enforced:

1. A core field name and a bundle field name must not collide on the same entity type (see [`bundle-scoped-fields.md`](./bundle-scoped-fields.md#collision-rules)).
2. A bundle field name may be reused across different bundles of the same entity type. Each subtable is its own namespace; `group__business.email` and `group__organization.email` are independent columns with possibly different semantics.

## Cardinality

**1:1** — at most one subtable row per entity per bundle.

- Primary key of the subtable uses the same column name as the base table's id key (e.g., `gid` for `group`). No auto-increment; the value is supplied at insert from the base row.
- Foreign key from the subtable's id key to `{base_table}.{idKey}`, `ON DELETE CASCADE`.
- An entity's bundle is determined by `base_table.{bundleKey}`. Only the matching subtable holds its extended data. An entity never has rows in subtables of bundles other than its own.

## Creation (install-time)

`SqlSchemaHandler::ensureTable(EntityTypeInterface $type)` is the canonical install path.

```php
public function ensureTable(EntityTypeInterface $type): void
{
    $this->ensureBaseTable($type);                // existing behavior
    foreach ($this->registeredBundlesFor($type) as $bundle) {
        $bundleFields = $this->fieldRegistry->bundleFieldsFor($type->id(), $bundle);
        if ($bundleFields === []) {
            continue;                              // no subtable for empty bundles
        }
        $this->ensureBundleSubtable($type, $bundle, $bundleFields);
    }
}
```

`ensureBundleSubtable()` creates `{base_table}__{bundle}` with the PK + FK + CASCADE, one column per `FieldDefinition`, and any declared indexes. The method is idempotent — repeated calls add missing columns but never drop. Column types derive from the field type via the existing field-type → column-type map.

A bundle with zero registered fields has no subtable. This is both the install-time default state for a newly-registered bundle and a legitimate steady state: a bundle may exist solely as a discriminator with all shared behavior on core.

**Bundle-enumeration source.** `registeredBundlesFor()` resolves the bundle list in two ways. When `SqlSchemaHandler` is constructed with an explicit `bundleEnumerator` closure, that closure is authoritative — it is the escape hatch for callers that need to enumerate bundles beyond the registry (e.g. a declared-but-empty bundle from the bundle-entity-type config that still wants a subtable pre-created, or a pre-flight schema rebuild driven by config rather than registry). When no enumerator is passed, the handler falls back to `FieldDefinitionRegistry::bundleNamesFor($type->id())`. This is the same source `SqlEntityStorage` uses for save-time partitioning, so schema materialization and write-path routing agree on which bundles are "known". The kernel path — `AbstractKernel::bootEntityTypeManager()` — constructs handlers without an enumerator and relies on the registry fallback. Callers passing an explicit enumerator (currently only test fixtures enumerating intentionally-scoped bundle sets) keep their override behavior unchanged.

Per-bundle subtable creation is the first consumer of foreign-key DDL in the framework and the driver of an additive extension to the schema spec: `SchemaInterface::createTable()` now honors a `foreign keys` entry in the spec array, forwarded by `DBALSchema` to DBAL's `Table::addForeignKeyConstraint()`. The interface itself is unchanged — the extension is purely in the accepted spec shape.

### Bundle-scoped unique keys (#2603)

`BundleStorageUniqueKeyRegistryInterface` records named key definitions by
`(entityTypeId, bundle)`. Every named field must already be registered on that
same bundle. `ContentTypeModel::$uniqueKeys` is the application/import model
declaration surface and `ContentModelRegistrar` replays it idempotently.

Schema sync promotes Data-backed key fields into the bundle subtable, backfills
them from base `_data`, scans for pre-existing duplicate fully-non-null tuples,
and only then adds the database unique key. Existing duplicates throw
`BundleUniqueKeyMigrationException` with stable error code
`bundle_unique_key_duplicates`; an opaque driver index error is not the
operator contract. Runtime `assertRuntimeSchema()` verifies both promoted
columns and named indexes without DDL and refuses missing state with S1-DB106.

The storage gateway preflights an existing conflict for a precise response and
relies on the unique index to close the concurrent-write race. A collision is
mapped to `BundleUniqueKeyConflictException` (`BUNDLE_UNIQUE_KEY_CONFLICT`),
including bundle, key, fields, and submitted values; unrelated database unique
violations remain untouched. A tuple participates only when every component is
non-null. Null-containing tuples may repeat; empty strings are ordinary values
and conflict. These semantics are portable across SQLite, MySQL, and
PostgreSQL.

A bundle declaring a key fails closed when its subtable is unavailable; the
ordinary missing-subtable `_data` fallback remains only for bundles without a
storage uniqueness invariant. Under default-revision discipline the key applies
to served/base state: draft-only saves do not touch the subtable and may
temporarily share a value, while publication performs the indexed upsert and
refuses a collision atomically.

## Lifecycle

The empty→non-empty transition is a first-class case, triggered whenever a release adds the first field to a previously-empty bundle.

**Trigger.** A bundle that was previously registered with zero fields gains its first field when a release calls `EntityTypeManager::addBundleFields($entityTypeId, $bundle, [...])`. At the moment of registration the in-memory field registry reflects the new state; storage does not.

**Resolution path.** Deferred to the next `EntitySchemaSync::syncAll()` invocation or explicit schema migration. `addBundleFields()` is a registration call, not a DDL call — it performs no runtime schema mutation. The `ensureTable()` iteration (above) is idempotent and enumerates the current registered bundle set; when it next runs, the now-non-empty bundle takes the `ensureBundleSubtable()` branch for the first time and creates the subtable.

**Same path as new-bundle creation.** From the schema handler's perspective, "new bundle with fields" and "existing bundle gains first field" are indistinguishable — both are a `(entityType, bundle)` pair with non-empty registered fields that has no subtable yet. One code path handles both.

**Runtime notice on save-path mismatch (K4, mission #1257).** `SqlEntityStorage::save()` is the first deterministic runtime hook that can truthfully tell whether bundle-scoped values are about to be dropped because a required `{base_table}__{bundle}` subtable is still missing. When bundle values are present, the entity has a concrete bundle, and the subtable does not exist, storage emits a `LoggerInterface::warning()` with diagnostic code `MISSING_BUNDLE_SUBTABLE` **once per `(entity_type, bundle)` per process** (memoized on the bundle-subtable cache) and continues the base-row write without the bundle-field values. **No throw** — preserves the open-by-default, diagnostic-driven model from [`operator-diagnostics.md`](./operator-diagnostics.md). The same once-per-pair memoization applies to `mergeBundleSubtableRow()` / `mergeBundleSubtableRowsBatch()` on the load path. This is intentionally later than `addBundleFields()`: healthy boots can register bundle fields before the schema materializes, so warning there would false-positive.

**Recommendation for products.** A release that ships new fields against a previously-empty bundle **should** ship an accompanying schema migration that calls `ensureBundleSubtable()` explicitly (or an equivalent manual DDL). Relying on `syncAll()` for structural changes works but is non-deterministic with respect to release and boot ordering; an explicit migration is reviewable, failure-loud on skipped upgrades, and consistent with how the project treats every other schema evolution.

**Reverse transition not supported.** Removing all fields from a bundle does not drop its subtable. Dropping bundle data is an explicit operator decision that must be a separate migration — never an implicit consequence of field-registry state.

## Drift diagnostic

`operator-diagnostics` enumerates `(base_table + {base_table}__{bundle} for each registered non-empty bundle)` per multi-bundle EntityType.

- Missing base table — reported as before.
- Missing subtable when the bundle has non-empty registered fields — reported with table name `{entity_type}__{bundle}`, diagnostic code extended to distinguish subtable-missing from base-missing.
- Column drift on a subtable — reported with the subtable name in the drift entry.
- A subtable present in storage but no longer matching any registered bundle is reported as `ORPHAN_BUNDLE_SUBTABLE` (informational; may be left in place pending an operator-authored cleanup migration).

See [`operator-diagnostics.md`](./operator-diagnostics.md) for the algorithm and diagnostic-code registry.

**Dialect portability (K5, mission #1257; landed via #1301).** `HealthChecker::findOrphanSubtables()` enumerates tables via `SchemaInterface::listTableNames()` (delegating to Doctrine's `AbstractSchemaManager::listTableNames()`) and filters by `{base}__` prefix in PHP using `str_starts_with`. Portable across SQLite, MySQL, PostgreSQL, and any other DBAL-supported driver — no `sqlite_master` fast-path remains. The production code path itself is dialect-portable as of #1301; non-SQLite test matrix coverage (docker-compose env-gated) is tracked separately as a CI extension.

**Layer placement (K6, mission #1257).** `HealthChecker` lives in `packages/foundation/src/Diagnostic/` and imports from L1 (`Waaseyaa\Entity\*`, `Waaseyaa\EntityStorage\SqlSchemaHandler`, etc.) — kernel-adjacent because it is wired only from `ConsoleKernel`. The cross-layer privilege is codified in `bin/check-package-layers` `KERNEL_EXEMPT_FILES` per mission #824 WP02 surface C; entry rationale cites K6(c). See [`infrastructure.md` §Kernel exemption surface](./infrastructure.md).

## Non-goals

- **No automatic migration of existing flat-fielded multi-bundle entities.** `node`, `taxonomy_term`, and `media` continue using flat-table storage with all fields on the base. Adoption of per-bundle storage for these is a deliberate future project tracked in [`extraction-log.md`](./extraction-log.md).
- **No schema federation across bundles.** Each subtable is independent; no inheritance, no shared views, no cross-bundle foreign keys beyond the per-subtable FK to the base.
- **No runtime DDL.** All schema changes go through the migration path.

## References

- Field resolution and query semantics: [`bundle-scoped-fields.md`](./bundle-scoped-fields.md).
- Extraction rationale and commit plan: [`../plans/2026-04-18-groups-extraction-design.md`](../plans/2026-04-18-groups-extraction-design.md).
- Future candidates for adoption: [`extraction-log.md`](./extraction-log.md).
- Upstream: [`entity-system.md`](./entity-system.md), [`infrastructure.md`](./infrastructure.md), [`operator-diagnostics.md`](./operator-diagnostics.md).
- Tenancy declaration on `EntityType`: [`entity-system.md` §Community Scoping](./entity-system.md).
- Mission ratification: `kitty-specs/1257-entity-storage-hardening/spec.md`.
