# Upgrade Notes — Two-Axis Storage (Revisionable × Translatable)

**Introduced in:** alpha train shipping M-004 (`entity-storage-translatable-revisions-01KRCDEE`), closed 2026-05-17.
**Charter linkage:** [`../specs/stability-charter.md`](../specs/stability-charter.md) §5.3, §7 (upgrade guide requirement).
**Canonical doctrine (LIVE):** [`../specs/revision-system-unified.md`](../specs/revision-system-unified.md) — the M-004 `vid` model below is retired (alpha.196); declare `'revision' => 'revision_id'` (not `'vid'`).
**Cookbook:** [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md).

---

## Summary

This release adds **two-axis storage**: entity types may now declare BOTH
`revisionable: true` AND `translatable: true` on the same `EntityType`. The
substrate is additive — single-axis entity types continue to use the M-006
translation path and the M-001 revision path with byte-for-byte unchanged
output (spec §12.3 R-A regression gate).

**Net effect for existing apps:** zero. No breaking changes; no required action
unless you want to opt in.

**Net effect for apps that opt in:** new tables (`<entity>__translation__revision`),
new save semantics (atomic multi-language writes via
`SaveContext::withTranslations()`), new exception types
(`StorageMigrationException`, `EntityTranslationException::historicalRevisionWrite()`),
new access-policy composition seam (`RevisionPolicyComposition`), and a new
migration generator flag (`make:storage-migration --add-revisions`).

---

## When you need to read this guide

You need to act on this upgrade if you maintain an application that:

1. Already declares one or more entity types as `revisionable: true` OR
   `translatable: true`, AND
2. Wants to extend that entity type to two-axis (both flags).

If your app has no such entity types, no action is required. The substrate is
shipped but inactive until opted in.

---

## What's new (stable surface)

The following FQCNs are added to the public surface (charter §5.3 + public-surface-map):

- `Waaseyaa\EntityStorage\Exception\StorageMigrationException` (with factories `noOpPromotion()`, `unsupportedTwoAxisField()`)
- `Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver`
- `Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler`
- `Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver`
- `Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` (note `Revision\` subnamespace — distinct from the M-001 `Waaseyaa\EntityStorage\RevisionPruningPolicy`)
- `Waaseyaa\Access\Policy\RevisionPolicyComposition`
- `Waaseyaa\EntityStorage\SaveContext::withTranslations(array $langcodes): self` (additive method on existing class)
- `Waaseyaa\Entity\Exception\EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode): self` (additive factory on existing class)

See [`../specs/entity-storage-two-axis.md`](../specs/entity-storage-two-axis.md) for full contracts.

---

## How to opt an entity type into two-axis

### Path A — already translatable, add revisions

```bash
bin/waaseyaa make:storage-migration --add-revisions <entity_type>
```

This emits a migration that adds `<entity_type>__revision` and converts
`<entity_type>__translation` to `<entity_type>__translation__revision` with the
required `vid` column and `(entity_id, langcode, vid)` unique constraint.

### Path B — already revisionable, add translations

```bash
bin/waaseyaa make:storage-migration --add-translations <entity_type>
```

This emits a migration that extracts translatable fields into a new
`<entity_type>__translation__revision` table, leaving non-translatable fields
in `<entity_type>__revision`.

### Path C — both flags from scratch

Declare both flags on the `EntityType` constructor and let the SchemaSync run
on first boot. No migration required.

```php
new EntityType(
    id: 'teaching',
    label: 'Teaching',
    class: Teaching::class,
    keys: [..., 'revision' => 'revision_id', 'default_langcode' => 'default_langcode'],
    revisionable: true,
    translatable: true,
)
```

### No-op promotion

If the entity type is already two-axis, the generator raises
`StorageMigrationException::noOpPromotion()` with stable
`errorCode` `'no_op_promotion'`. Catch and ignore, or run a status check
before invoking the generator.

---

## Forbidden-backend guard (boot-time)

After opt-in, any registered `FieldDefinition` that is **translatable** AND
routed to a non-`sql-*` backend (`vector`, `remote`, or any custom backend not
in `ReservedBackendIds::SQL_COLUMN` / `SQL_BLOB`) raises
`StorageMigrationException::unsupportedTwoAxisField()` at boot.

**Triage:**

- Drop the `->translatable()` flag if the field doesn't need per-language storage.
- Change `->storedIn('sql-column')` or `->storedIn('sql-blob')` for translatable fields.
- Split the field into two: one translatable (sql-*), one denormalised vector.

---

## Behavioural changes for callers

### `SaveContext::withTranslations()` is new

Existing calls to `SaveContext::withLangcode()` continue to work unchanged.
`withTranslations()` is additive. If your code constructs `SaveContext`
instances directly (without using `withLangcode`/`withTranslations`), no
changes are needed.

### `listRevisions()` semantics for two-axis types

The interface signature is unchanged
(`listRevisions(RevisionableEntityInterface $entity): iterable`), but for
two-axis types the yielded rows carry per-revision `langcode` metadata.
Consumers that previously iterated under the assumption of a single language
must now filter on `langcode`.

### Listing pipeline + cache tags

`TwoAxisFilterResolver` integrates with the M-007 listing pipeline. The
`language.content` cache context auto-injects when the entity type is
translatable; `AfterSaveEvent::affectedLangcodes()` emits per-langcode
`entity:<type>:<id>:<langcode>` cache tags. No changes are required for
consumers using the canonical listing API.

---

## Performance and pruning

Two-axis storage grows the revision archive in O(edits × langcodes). For
high-edit entities, schedule a pruning job using
`Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` (per-language
retention counts).

See [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md) §"Performance guidance" for full discussion.

---

## Rollback

The substrate is additive: removing the `translatable: true` flag (or removing
`revisionable: true`) reverts the entity type to single-axis. The
`<entity>__translation__revision` table is **not** dropped automatically —
operators must drop it manually if they want to reclaim the storage. Existing
data in `<entity>__revision` is preserved across the rollback.

---

## Cross-references

- Canonical spec: [`../specs/entity-storage-two-axis.md`](../specs/entity-storage-two-axis.md)
- Cookbook: [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md)
- Charter §5.3: [`../specs/stability-charter.md`](../specs/stability-charter.md)
- M-004 mission planning archive: `kitty-specs/entity-storage-translatable-revisions-01KRCDEE/`
- ADRs: [016 — revisions first-class](../adr/016-revisions-first-class.md), [017 — per-field translation](../adr/017-per-field-translation.md)
