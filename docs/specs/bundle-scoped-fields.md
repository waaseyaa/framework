# Bundle-scoped fields

**Status:** Active — lands with `waaseyaa/groups` extraction.

**Audience:** Framework contributors working on `waaseyaa/field`, `waaseyaa/entity`, `waaseyaa/entity-storage`, and `waaseyaa/access`; authors of multi-bundle entity types.

## Principle

`FieldDefinition::$targetBundle` narrows a field definition's scope from `(entityTypeId)` to `(entityTypeId, bundle)`.

- `targetBundle === null` — **core field**, applies to every bundle of its entity type.
- `targetBundle === 'business'` — **bundle field**, applies only to entities of that bundle.

This mechanism is dormant in the codebase prior to the `waaseyaa/groups` extraction: the constructor parameter has existed on `FieldDefinition` since its introduction, but no resolver, storage handler, query path, or access-policy filter consulted it. This spec defines the behavior that brings it live.

Storage partitioning for bundle fields is covered in the companion spec, [`bundle-scoped-storage.md`](./bundle-scoped-storage.md).

## Registration

Fields are registered via `EntityTypeManager::addBundleFields()`:

```php
public function addBundleFields(
    string $entityTypeId,
    string $bundle,
    array $fields,   // FieldDefinition[]
): void;
```

Constraints enforced at registration time:

- The `EntityType` identified by `$entityTypeId` must already be registered and must declare `bundleEntityType` on construction — i.e., must be a multi-bundle entity type.
- Each `FieldDefinition` must satisfy `$def->getTargetEntityTypeId() === $entityTypeId` and `$def->getTargetBundle() === $bundle`. Mismatches throw `\InvalidArgumentException` — the declaration must be self-describing so that bulk registration and per-field construction are interchangeable.
- Field names within one `(entityTypeId, bundle)` pair must be unique. Duplicate registration throws.
- Collisions with core fields of the same entity type throw — see [collision rules](#collision-rules) below.

Core field registration continues via `EntityType::fieldDefinitions` on construction, unchanged. A new registry, `FieldDefinitionRegistry`, keys all registered fields by `(entityTypeId, targetBundle)` and exposes `coreFieldsFor($entityTypeId)` and `bundleFieldsFor($entityTypeId, $bundle)`. The contract — `FieldDefinitionRegistryInterface` — lives in `packages/entity/src/Field/` so `EntityTypeManager` can consult it without importing from `waaseyaa/field`; the concrete `FieldDefinitionRegistry` lives in `packages/field/src/`. This follows the existing layer graph (`waaseyaa/field` depends on `waaseyaa/entity`, not the reverse).

In a booted kernel, the `EntityTypeManager` owns the one canonical registry. `FieldServiceProvider` adopts that exact instance from `KernelServicesInterface` for both its registry binding and `BundleTemplateCompiler`; generated template fields, schema materialization, provider-local resolution, and HTTP resolution therefore observe one authority. A standalone provider constructs an isolated built-in registry only when the kernel supplies none, and a non-null service of the wrong type fails closed.

## Collision rules

Three cases, two outcomes:

1. **Core × Core on the same entity type.** Forbidden — the existing `EntityTypeManager` guarantee that an entity type's field definitions are unique by name.
2. **Core × Bundle on the same entity type.** Forbidden — registering a core field named `status` and a bundle field named `status` on entity type `group` throws at bundle-field registration.
3. **Bundle × Bundle across different bundles of the same entity type.** Allowed — `group__business.email` and `group__organization.email` are independent columns in independent subtables.

The rule in one sentence: **a core field name occupies the entity type's global namespace; bundle field names occupy their subtable's local namespace.**

Collision errors surface as `\InvalidArgumentException` with message shape:

`Field "status" on entity type "group" bundle "business" collides with core field "status" on entity type "group".`

## Resolution

`ContentEntityBase::getFieldDefinitions()` becomes bundle-aware:

```php
public function getFieldDefinitions(): array
{
    $registry = $this->entityTypeManager->getFieldRegistry();
    return $registry->coreFieldsFor($this->entityTypeId)
         + $registry->bundleFieldsFor($this->entityTypeId, $this->bundle());
}
```

The union is safe because core × bundle name collisions are forbidden at registration. The merged result is the entity's effective schema for the rest of its lifecycle — validation, serialization, access checks, JSON Schema output.

The registry is authoritative for any entity type whose registration has run. For entity types whose registration has *not* run — bootstrap, isolated unit tests that construct entity objects without going through `EntityTypeManager::registerEntityType()` — `ContentEntityBase::getFieldDefinitions()` falls back to the per-instance `$fieldDefinitions` passed through the legacy constructor. This is a transitional convenience, not a permanent dual-source contract: the fallback exists so pre-registration call sites and contract tests keep working, and it disappears silently the moment the type registers. Code that wants the registry to be the source of truth must route through registration; no caller should treat the legacy array as a supplemental path.

Both `coreFieldsFor()` and `bundleFieldsFor()` return `FieldDefinition` objects; core fields are synthesized from `EntityType::fieldDefinitions` metadata arrays at registration time. The registry is the normalization boundary — `EntityType::getFieldDefinitions()` and the rest of the codebase retain the metadata-array shape, and this spec does not require a sweeping consumer migration.

For entity types with no `bundleEntityType` declared, `bundleFieldsFor()` returns empty and behavior matches the pre-spec status quo. This guarantees backward compatibility for `node`, `taxonomy`, `media`, and any other multi-bundle entity that has not yet adopted per-bundle fields.

## Save

`SqlEntityStorage::splitForStorage()` partitions an entity's value bag by each resolved field definition's `targetBundle`:

- `targetBundle === null` — value routes to the base table. If mapped to a schema column it goes there; otherwise it serializes into `_data`.
- `targetBundle` matches the entity's bundle — value routes to the bundle subtable row.
- `targetBundle` does not match the entity's bundle — impossible under correct resolution (the field is not in the resolved definition set); treated as a programming error and thrown.

The save executes inside a single `UnitOfWork`:

1. Base row INSERT or UPDATE.
2. Subtable row upsert (INSERT or UPDATE keyed on the entity id; DBAL abstracts the dialect-specific syntax).
3. Transaction commit. POST_SAVE event dispatch.

If either leg fails, the transaction rolls back and no event is dispatched.

## Load

The load path is two primary-key-lookup queries, not a JOIN:

```php
// 1. Read base row — yields the bundle value.
$baseRow = $this->db->fetchAssociative(
    'SELECT * FROM "group" WHERE gid = ?', [$id]
);

// 2. If the bundle has a registered subtable, read it.
$bundle = $baseRow['type'];
$bundleFields = $this->fieldRegistry->bundleFieldsFor('group', $bundle);
if ($bundleFields !== []) {
    $bundleRow = $this->db->fetchAssociative(
        "SELECT * FROM \"group__{$bundle}\" WHERE gid = ?", [$id]
    );
    $baseRow = array_merge($baseRow, $bundleRow ?? []);
}

// 3. Hydrate.
return $this->mapRowToEntity($baseRow);
```

Rationale: two queries avoid column-name collision in a single SELECT, both hit primary keys, and the second query is trivially elided when the bundle has no subtable. Batch loads (`findBy()`, `loadMultiple()`) perform the subtable lookup once per distinct bundle in the result set, not per row.

Filtered loads — anything producing a WHERE against a bundle-scoped field — follow the [query](#query) path and rely on JOINs.

## Query

`SqlEntityQuery` resolves conditions per-field:

```
for each condition(field, operator, value):
    def = registry.find(entityTypeId, field)
    if def is null:
        throw UnknownFieldException
    if def.targetBundle is null:
        where.add("base.{field} {operator} {value}")
    else:
        required_joins.add(def.targetBundle)
        where.add("base__{def.targetBundle}.{field} {operator} {value}")

for each B in required_joins:
    sql.addInnerJoin("{base_table}__{B}", "base.{idKey} = base__{B}.{idKey}")
```

**INNER JOIN, not LEFT JOIN.** Subtable rows exist only for entities of the matching bundle (see [`bundle-scoped-storage.md`](./bundle-scoped-storage.md)), so an inner join with the subtable implicitly narrows the query to that bundle. An explicit `condition(bundleKey, $bundle)` in the caller's query is redundant but harmless.

Ordering (`orderBy`) and grouping follow the same routing: a reference to a bundle-scoped field adds the required join.

### Ambiguity policy (contract)

When a field name exists in multiple bundles (allowed by the collision rules above — `group__business.email` and `group__organization.email` are legal siblings), a query that references that field name **must constrain the bundle** either via an explicit `condition(bundleKey, ...)` or via another bundle-scoped condition in the same query that implies the bundle uniquely.

If the query does not constrain the bundle and the field name is ambiguous, `SqlEntityQuery` **throws** `BundleAmbiguousFieldException`:

`Field "email" is bundle-scoped and exists in bundles [business, organization] of entity type "group". Constrain the bundle via ->condition('type', '<bundle>') before referencing this field.`

Silent resolution ("pick the first registered bundle" or "union across all bundles") is explicitly rejected. It is the class of bug where a cross-product consumer of a multi-bundle entity inadvertently reads semantically different data with the same column name. The ambiguity policy is locked as a framework contract and applies to every bundle-scoped field across every multi-bundle entity type.

## Access

`#[AccessPolicy]` gains an optional `bundles:` parameter:

```php
#[AccessPolicy(
    id: 'business_access',
    entityTypes: ['group'],
    bundles: ['business'],
)]
final class BusinessAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    // ...
}
```

Semantics:

- `bundles: []` (empty, default) — policy applies to all bundles of the listed entity types. This preserves the semantics of every existing policy in the codebase; no migration needed.
- `bundles: ['business']` — policy applies only when `$entity->bundle() === 'business'`.
- `bundles: ['business', 'organization']` — applies when bundle is either.

`EntityAccessHandler` filters candidate policies at evaluation:

```php
$applicable = array_filter($this->policies, fn(AccessPolicyInterface $p) =>
    $p->appliesTo($entity->getEntityTypeId())
    && ($this->bundlesFor($p) === []
        || in_array($entity->bundle(), $this->bundlesFor($p), true))
);
```

`checkCreateAccess($entityTypeId, $bundle, $account)` — the method already takes `$bundle`; the same filter applies before policy dispatch.

Field-level access (`checkFieldAccess`) requires no attribute change. Policies implementing `FieldAccessPolicyInterface` already receive the entity, so bundle is accessible via `$entity->bundle()`; bundle-scoped field-access logic dispatches in the policy body.

## Validation

`EntityValidator` builds its constraint map from the bundle-resolved field list. Constraints declared on core fields apply to every bundle; constraints declared on bundle fields fire only for entities of that bundle. No special-casing beyond what `getFieldDefinitions()` already produces.

## API-layer shape (v1)

For the initial activation, `SchemaController` (JSON Schema output) and GraphQL schema generation continue to emit **one merged shape per EntityType**. The merged shape unions every bundle's registered fields; consumers that need to distinguish "which fields apply to which bundle" must cross-reference the entity's bundle discriminator against field metadata.

This is a deliberate pragmatic choice — the initial extraction's API-layer byte-equivalence gate (verified against Minoo's Business surfaces) forbids shape changes. Per-bundle resource shapes (e.g., `group--business` and `group--organization` as distinct JSON:API resource types, following the Drupal JSON:API precedent) are a future consideration, flagged in the extraction design document as "downstream of this extraction."

## References

- Storage partitioning and lifecycle: [`bundle-scoped-storage.md`](./bundle-scoped-storage.md).
- Extraction rationale and commit plan: [`../plans/2026-04-18-groups-extraction-design.md`](../plans/2026-04-18-groups-extraction-design.md).
- Related: [`access-control.md`](./access-control.md), [`field-access.md`](./field-access.md), [`entity-system.md`](./entity-system.md).
