<!-- Spec reviewed 2026-06-15 - convention-spec-gate D-10: M-006 shipped 2026-05-13/14; this file is the LIVE canonical single-axis translation surface (referenced as "Full canonical surface" by entity-system.md, access-control.md, api-layer.md, listing-pipeline-v1.md, entity-storage-translatable-revisions.md). It is NOT superseded — only the Draft status line was stale. Corrected the status to SHIPPED; banner matches the ✅ idiom in entity-storage-translatable-revisions.md. -->
# Entity Storage — Single-Axis Translations v1

> **✅ SHIPPED — M-006 closed 2026-05-13/14.** This spec remains the **live,
> canonical** surface for single-axis (per-field) entity translations and is
> referenced as such by entity-system.md, access-control.md, api-layer.md,
> listing-pipeline-v1.md, and entity-storage-translatable-revisions.md. It is
> **not** superseded — build against it. (Two-axis translatable×revisionable
> storage is a separate concern; see
> [`revision-system-unified.md`](revision-system-unified.md).)

**Status:** Shipped (M-006, mission `entity-storage-translations-v1-01KRF0FQ`, 2026-05-13/14); canonical single-axis translation surface. Satisfies stability charter §3.2 beta-entry criterion 9; §5.3 surface ratified.
**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** M-006 (display) / `01KRF0FQ0AA42F434JNAA56WFB` (Spec Kitty)
**Mission slug:** `entity-storage-translations-v1-01KRF0FQ`
**Origin:** [ADR 017](../adr/017-per-field-translation.md) "Per-field translation: declarative flag + entity translation API" (Accepted 2026-05-11). M-001 (`entity-storage-v2-01KRCDDC`, squash `509e31fb7`) shipped revisions but left `EntityTypeInterface::isTranslatable()` as a tombstone flag with no implementation.

**Governing ADR:** [ADR 017](../adr/017-per-field-translation.md) — per-field translation, declarative flag, `TranslatableEntityInterface` surface, language fallback chain, sql-blob and sql-column storage shapes.

**Charter linkage:**
- [`stability-charter.md`](stability-charter.md) §5.3 governs the new entity translation stable surface.
- §3.2 criterion 9 (per-field translation surface) gates beta entry on this mission's delivery. **BETA-GATE.**

**Sibling missions:**
- [`entity-storage-v2.md`](entity-storage-v2.md) (M-001) — **shipped 2026-05-11**. Provides the multi-backend coordinator and revision substrate this mission extends with translation.
- [`entity-storage-translatable-revisions.md`](entity-storage-translatable-revisions.md) (M-004) — **BLOCKED, gated on this mission.** Composes single-axis translation with single-axis revisions; cannot be planned until this mission ships AND the ADR 015 listing-pipeline mission ships.
- [`migration-platform-v1.md`](migration-platform-v1.md) (M-002) — independent. `EntityDestination` writes per-language when target is translatable.
- [`config-management-v1.md`](config-management-v1.md) (M-003) — independent. Config entities are not translatable.

**Comparable mission:** [`entity-storage-v2.md`](entity-storage-v2.md) — shape and rigor template.

## 0. Origin

ADR 017 made per-field translation a first-class framework surface, then sequenced the work into two missions: single-axis translations (this one) and two-axis revisions × translations (M-004). M-001 shipped the revision axis but explicitly deferred the translation axis to a parallel mission.

The deferral is now the blocker. Today's translation surface is:

- `EntityType::isTranslatable(): bool` exists and defaults to `false`. No entity type sets it `true` (verified 2026-05-12 by grep across `packages/*/src/`).
- `EntitySchemaSync.php:31` reads the flag, but there is no schema branch for translatable types.
- No `TranslatableEntityInterface`, no `getTranslation()`, no `__translation` storage table.
- The i18n package (`packages/i18n/`) provides `Language`, `LanguageManager`, `FallbackChain`, `Translator` — language *negotiation* for the SSR layer — but nothing wires it into entity storage.

This mission ships the substrate ADR 017 specified for the single-axis case. M-004 composes it with revisions.

## 1. Goals / non-goals

### 1.1 Goals

1. Define `TranslatableEntityInterface` per ADR 017 §"Stable surface" and wire `EntityType::translatable: true` to a real implementation.
2. Specify storage shape for `sql-blob` and `sql-column` backends per ADR 017 §"Storage shape" — translation table keyed `(entity_id, langcode)`.
3. Specify save semantics: saving the French translation writes the French row only; English row unchanged.
4. Specify load semantics: `$entity->getTranslation('fr')` returns a `TranslatableEntityInterface` instance scoped to the French translation; missing-field reads walk the configurable fallback chain.
5. Implement per-field translatability via `FieldDefinition::translatable()` (option C from ADR 017).
6. Compose access policies: new `translate` operation in `AccessPolicyInterface::access()` for per-translation access decisions.
7. Extend the storage migration generator with `--add-translations` for promoting a non-translatable type.
8. Wire `Waaseyaa\I18n\LanguageManager` to the read path for active-language resolution; the write path remains explicit.
9. Validate end-to-end with a `test_translatable_entity` fixture entity type covering both backends and the fallback chain.

### 1.2 Non-goals

- **Revisions composition.** `(entity_id, langcode, vid)` keying is M-004; this mission ships translations on non-revisionable types only.
- **Listing pipeline / per-langcode cache tags.** Deferred to the future ADR 015 mission. `EntityRepository::findBy(['langcode' => 'fr'])` works today via the existing column; one `findTranslations(EntityInterface)` helper ships, no further query extension.
- **Admin SPA UI for translations.** Schema and API only.
- **Machine translation / translation provider integrations.** ADR 017 explicitly defers; reaffirmed here.
- **Cross-language workflow / moderation.** Future ADR if demand emerges.
- **Translation-aware GraphQL / JSON:API output shaping.** Existing `langcode` filter on `findBy` suffices; richer output shapes are post-beta.
- **Minoo `teaching` migration.** Consumer-app PR after framework release; this mission's validation uses a framework fixture entity type.

## 2. Scope summary

### 2.1 In scope

- `Waaseyaa\Entity\TranslatableEntityInterface` (new) per ADR 017.
- `FieldDefinition::translatable(bool)` flag with `isTranslatable()` getter.
- New entity key `default_langcode` (per ADR 017).
- `sql-blob` storage shape: `_data` becomes per-langcode map.
- `sql-column` storage shape: sibling `<table>__translation` table.
- `SaveContext::withLangcode(string)` and write-path langcode honoring.
- Read-path fallback chain: per-field walk of `[requested, entity-default, site-default, 'en']` (configurable, de-duplicated).
- `$entity->fieldLangcode(string $fieldName): ?string` observability method.
- `translate` operation in access policy contract.
- `EntityRepository::findTranslations(EntityInterface): array<string, EntityInterface>` helper.
- Schema migration generator `--add-translations` flag.
- Lifecycle events with `langcode` in payload.
- `EntityTranslationException` exception hierarchy.
- `LanguageManager` wire-up on read path (optional DI).
- Contract test suite for `TranslatableEntityInterface` implementations.
- `test_translatable_entity` fixture entity type for backend validation.
- Documentation: spec, cookbook recipe, upgrade guide note, charter cross-reference.

### 2.2 Out of scope

See §1.2.

## 3. Functional requirements

Normative requirements use **MUST / SHOULD / MAY** per RFC 2119. Status legend: **NEW** (introduces surface), **EXTENDS** (modifies existing surface), **REFINES** (clarifies existing behavior).

### 3.1 Entity type declaration

| ID | Status | Requirement |
|---|---|---|
| FR-001 | NEW | `EntityType` MUST accept `translatable: true` as a constructor parameter (today the parameter exists at `packages/entity/src/EntityType.php:75` but produces no runtime effect). When `true`, the entity type's storage backend MUST allocate a translation table. |
| FR-002 | NEW | When `translatable: true`, the entity type MUST declare `langcode` in `entityKeys`. Boot MUST throw `InvalidEntityTypeException::missingLangcodeKey()` if missing. |
| FR-003 | NEW | When `translatable: true`, the entity type MUST declare `default_langcode` in `entityKeys`. Boot MUST throw `InvalidEntityTypeException::missingDefaultLangcodeKey()` if missing. |
| FR-004 | NEW | The entity class registered for a `translatable: true` type MUST implement `TranslatableEntityInterface`. Boot MUST throw `InvalidEntityTypeException::translatableEntityClassNotImplementingInterface()` if not. |
| FR-005 | NEW | A bundle entity type (`bundleEntityType` set) MAY itself be `translatable`; bundles are not auto-translatable, the flag is per-entity-type. |

### 3.2 TranslatableEntityInterface surface

| ID | Status | Requirement |
|---|---|---|
| FR-006 | NEW | Interface `Waaseyaa\Entity\TranslatableEntityInterface extends EntityInterface` MUST be declared in `packages/entity/src/`. |
| FR-007 | NEW | Method `defaultLangcode(): string` MUST return the entity's `default_langcode` value; MUST throw `EntityTranslationException::langcodeRequired()` if unset (D1: fail-fast). |
| FR-008 | NEW | Method `activeLangcode(): string` MUST return the langcode of the loaded translation. For an entity loaded via `find($id)` without a `getTranslation()` call, this MUST equal `defaultLangcode()`. |
| FR-009 | NEW | Method `hasTranslation(string $langcode): bool` MUST return `true` iff a row exists for `(entity_id, langcode)`. MUST be O(1) on cache hit; backed by translation table on miss. |
| FR-010 | NEW | Method `getTranslation(string $langcode): static` MUST return a `TranslatableEntityInterface` instance whose `activeLangcode()` equals `$langcode`. MUST throw `EntityTranslationException::translationNotFound()` if `hasTranslation($langcode)` is `false`. |
| FR-011 | NEW | Method `addTranslation(string $langcode): static` MUST allocate an empty translation row in memory (not yet persisted) and return the new translation instance. MUST throw `EntityTranslationException::translationAlreadyExists()` if `hasTranslation($langcode)` is already `true`. |
| FR-012 | NEW | Method `removeTranslation(string $langcode): void` MUST throw `EntityTranslationException::cannotRemoveDefault()` if `$langcode === defaultLangcode()` (D2: cannot remove default; caller must `$repository->delete($entity)` instead). MUST mark the translation row for deletion; actual delete happens on `$repository->save()`. |
| FR-013 | NEW | Method `translations(): iterable<string>` MUST return all extant langcodes for this entity, including default. Order: default first, then ascending lex. |
| FR-014 | NEW | `ContentEntityBase` (existing) MUST implement `TranslatableEntityInterface`; methods MUST throw `EntityTranslationException::notTranslatable()` when called on an entity whose type has `translatable: false`. |
| FR-015 | NEW | `$entity->fieldLangcode(string $fieldName): ?string` MUST return the langcode where the field's value was actually resolved by fallback. Returns `null` when fallback exhausted and no value found. |

### 3.3 Per-field translatability

| ID | Status | Requirement |
|---|---|---|
| FR-016 | NEW | `FieldDefinition` MUST gain a `translatable(bool): static` builder method and an `isTranslatable(): bool` getter. Default `false`. |
| FR-017 | NEW | A field with `translatable: true` on a `translatable: false` entity type MUST raise `InvalidFieldDefinitionException` at boot. |
| FR-018 | NEW | On a `translatable: true` entity type, fields MUST default to `translatable: false`. Translatability is opt-in per field. |
| FR-019 | NEW | The entity key fields (`id`, `uuid`, `langcode`, `default_langcode`, `revision`) MUST be non-translatable regardless of `translatable()` setting. Boot MUST raise if marked translatable. |

### 3.4 sql-blob backend storage shape

| ID | Status | Requirement |
|---|---|---|
| FR-020 | EXTENDS | For `translatable: true` types on `sql-blob`, the primary table primary key MUST become `(entity_id, langcode)` instead of `(entity_id)`. |
| FR-021 | EXTENDS | The `_data` JSON blob on each row MUST contain ONLY the values of translatable fields for that `(entity_id, langcode)`. Non-translatable field values MUST be stored once on the `default_langcode` row only. |
| FR-022 | NEW | Read of a non-translatable field on a non-default-langcode row MUST single-step fallback to the value on the default-langcode row. |
| FR-023 | NEW | Read of a translatable field with no value at the requested langcode MUST walk the configured fallback chain (FR-037 et seq.). |
| FR-024 | NEW | Write of a non-translatable field on any translation MUST update the default-langcode row's `_data` blob, regardless of which translation is active. |
| FR-025 | REFINES | Schema columns for system fields (`id`, `uuid`, `langcode`, `default_langcode`) remain materialized columns and are NOT in the `_data` blob. |

### 3.5 sql-column backend storage shape

| ID | Status | Requirement |
|---|---|---|
| FR-026 | EXTENDS | For `translatable: true` types on `sql-column`, schema MUST add a sibling table `<table>__translation` keyed on `(entity_id, langcode)`. The primary table retains one row per `entity_id`. |
| FR-027 | NEW | Translatable field columns MUST live on `<table>__translation`; non-translatable field columns MUST live on `<table>`. |
| FR-028 | NEW | Read MUST left-join `<table>` and `<table>__translation` on `(entity_id, langcode)`, with `default_langcode` resolution if the requested langcode row is absent. |
| FR-029 | NEW | The `<table>__translation` row for `default_langcode` MUST exist iff the entity exists (the entity's "primary" translation is the default). `INSERT` of the entity MUST atomically insert both rows. |
| FR-030 | NEW | Write of a non-translatable field MUST `UPDATE <table>` only; write of a translatable field MUST `UPDATE <table>__translation` keyed on the active langcode. |
| FR-031 | NEW | Foreign-key relationships from related tables (e.g. `<table>__field_target`) MUST point at `<table>(entity_id)`, not at the translation table. Multi-cardinality field tables join the translation table only when the field itself is translatable. |
| FR-032 | NEW | Translatable multi-cardinality fields MUST key on `(entity_id, langcode, delta)`; non-translatable multi-cardinality fields keep `(entity_id, delta)`. |

### 3.6 SaveContext and write semantics

| ID | Status | Requirement |
|---|---|---|
| FR-033 | EXTENDS | `SaveContext` MUST gain a `withLangcode(string $langcode): self` builder. Default context has no langcode (write goes to the entity's `activeLangcode()`). |
| FR-034 | NEW | On save of a translatable entity, the coordinator MUST refuse to persist if `default_langcode` is unset (D1: fail-fast). Exception: `EntityTranslationException::langcodeRequired()`. |
| FR-035 | NEW | The coordinator MUST honor `SaveContext::langcode` if set: writes go to that translation row. If unset, writes go to `$entity->activeLangcode()`. |
| FR-036 | NEW | Saving a translation that has been marked for deletion via `removeTranslation()` MUST issue a DELETE on the translation row in the same UnitOfWork transaction as the primary save. |

### 3.7 Load semantics + fallback chain

| ID | Status | Requirement |
|---|---|---|
| FR-037 | NEW | Configuration key `translation.fallback_chain` MUST accept a callable `fn (string $requested, EntityInterface $entity): array<string>` returning langcode candidates. Default: `[$requested, $entity->defaultLangcode(), $siteDefault ?? 'en', 'en']` (de-duplicated). |
| FR-038 | NEW | Translatable field read MUST walk the chain returned by the configured callable until a non-null value is found. If exhausted, MUST return `null`. |
| FR-039 | NEW | The walked-langcode for each read MUST be retrievable via `$entity->fieldLangcode($fieldName)`. |
| FR-040 | NEW | `EntityRepository::find($id)` on a translatable type MUST load the `default_langcode` translation. To load another, callers use `find($id)->getTranslation($langcode)`. |
| FR-041 | NEW | When `LanguageManagerInterface::getCurrent()` returns a non-default langcode AND the request is in HTTP context, `EntityRepository::find()` MAY return that translation if available, falling back to default if not. This MUST be opt-in via config `translation.read_active_language: true` (default `false`). |
| FR-042 | NEW | `EntityRepository::findTranslations(EntityInterface $entity): array<string, EntityInterface>` MUST return a map of `langcode => translation instance` for all extant translations of the given entity. Single-query implementation (no N+1). |

### 3.8 Lifecycle events

| ID | Status | Requirement |
|---|---|---|
| FR-043 | EXTENDS | `EntityEvent` payloads MUST gain a `?string $langcode` public readonly property. For non-translatable entities, value is `null`. For translatable, value is the langcode being saved/deleted. |
| FR-044 | NEW | Saving a new translation (via `addTranslation()` + `save()`) MUST dispatch `PRE_TRANSLATION_INSERT` and `POST_TRANSLATION_INSERT` events in addition to entity-level save events. |
| FR-045 | NEW | Saving an existing translation MUST dispatch `PRE_TRANSLATION_UPDATE` and `POST_TRANSLATION_UPDATE`. |
| FR-046 | NEW | Removing a translation MUST dispatch `PRE_TRANSLATION_DELETE` and `POST_TRANSLATION_DELETE`. Removing the entity itself MUST dispatch translation-delete events for every extant translation before the entity-delete event. |

### 3.9 Access policy translate operation

| ID | Status | Requirement |
|---|---|---|
| FR-047 | EXTENDS | `AccessPolicyInterface::access()` MUST recognize `translate` as a valid operation name alongside `view`, `update`, `delete`, `create`. |
| FR-048 | NEW | Default behavior: when no policy answers `translate`, the entity-level `update` decision MUST apply (translate ⊆ update). |
| FR-049 | NEW | The `AccessResult` for a `translate` op MUST be able to introspect the candidate langcode: passed to `access()` via context array `['langcode' => 'fr']`. Field-level policies follow same convention. |

### 3.10 Migration generator

| ID | Status | Requirement |
|---|---|---|
| FR-050 | EXTENDS | `MakeMigrationCommand` MUST accept an `--add-translations` flag taking an entity-type id. |
| FR-051 | NEW | Generated migration for `--add-translations`: (a) creates `<table>__translation` for `sql-column`; (b) rewrites primary table primary key to `(entity_id, langcode)` for `sql-blob`; (c) backfills existing rows with `langcode = default_langcode = (--default-langcode value)`. |
| FR-052 | NEW | The `--default-langcode` argument MUST be required when `--add-translations` is used; the generator MUST refuse to run without it. |
| FR-053 | NEW | Reverse migration MUST be supported (drops translation rows except default-langcode); MUST emit a data-loss warning in the migration docblock. |

### 3.11 Error model

| ID | Status | Requirement |
|---|---|---|
| FR-054 | NEW | `Waaseyaa\Entity\EntityTranslationException extends \DomainException` MUST be declared. |
| FR-055 | NEW | Static factories: `::translationNotFound(string $langcode)`, `::cannotRemoveDefault(string $langcode)`, `::langcodeRequired()`, `::notTranslatable(string $entityTypeId)`, `::translationAlreadyExists(string $langcode)`. |
| FR-056 | NEW | All translation-related exceptions thrown from coordinator / repository / entity MUST be `EntityTranslationException` subclasses or instances; raw `RuntimeException` is forbidden. |
| FR-057 | NEW | `InvalidEntityTypeException` (existing) MUST be extended with translation-specific factories: `::missingLangcodeKey()`, `::missingDefaultLangcodeKey()`, `::translatableEntityClassNotImplementingInterface()`. |

### 3.12 Testing surface

| ID | Status | Requirement |
|---|---|---|
| FR-058 | NEW | A contract-test base class `Waaseyaa\Entity\Testing\TranslatableEntityContractTest` MUST be shipped under `packages/entity/testing/`. Backend implementations subclass to validate compliance against the interface. |
| FR-059 | NEW | A fixture entity type `test_translatable_entity` MUST be shipped under `packages/entity-storage/tests/Fixtures/` with both a `title` (translatable) field and a `created_at` (non-translatable) field. |
| FR-060 | NEW | Backend-specific tests MUST run the `TranslatableEntityContractTest` against both `sql-blob` and `sql-column` configurations. |
| FR-061 | NEW | Integration tests MUST cover: (a) fallback chain exhaustion returns `null`; (b) `fieldLangcode()` reports the actual resolved langcode; (c) UnitOfWork rolls back translation writes atomically on save failure. |

### 3.13 Documentation

| ID | Status | Requirement |
|---|---|---|
| FR-062 | NEW | A cookbook recipe `docs/cookbook/translating-an-entity-type.md` MUST be authored covering: declaring `translatable: true`, marking fields, writing a migration, save/load examples. |
| FR-063 | NEW | The stability charter §5.3 stable surface table MUST be updated to list `TranslatableEntityInterface`, `FieldDefinition::translatable()`, `EntityType::translatable` constructor parameter, `default_langcode` entity key. |
| FR-064 | NEW | `docs/specs/entity-storage-translatable-revisions.md` and `kitty-specs/entity-storage-translatable-revisions-01KRCDEE/spec.md` MUST have their single-axis-translation BLOCKED bullet removed in the mission-close commit (the ADR 015 listing-pipeline bullet remains; M-004 stays BLOCKED on that prerequisite). |

## 4. Non-functional requirements

| ID | Status | Requirement | Threshold |
|---|---|---|---|
| NFR-001 | NEW | Translation table read on `sql-column` MUST NOT regress single-entity load latency on non-translatable types. | p95 load time delta ≤ 0% on non-translatable entity types vs. pre-mission baseline (measured via existing PHPUnit performance suite at mission close). |
| NFR-002 | NEW | Fallback chain walk MUST be bounded. | Maximum chain length 8 langcodes; chain longer than 8 MUST raise `InvalidConfigurationException` at boot. |
| NFR-003 | NEW | Memory footprint of a loaded translatable entity with N translations MUST be O(N) in field-count, not O(N × entity-field-count). | Translations share field-definition arrays by reference; verified by reference-equality assertion test. |
| NFR-004 | NEW | Contract test suite MUST execute against both backends in under 10 seconds wall time on CI hardware. | Verified via CI duration check in WP14 acceptance gate. |
| NFR-005 | NEW | `findTranslations()` MUST be a single query, not N+1. | Asserted via query-count assertion in repository contract test. |

## 5. Constraints

| ID | Status | Requirement |
|---|---|---|
| C-001 | NEW | This mission MUST NOT introduce the `(entity_id, langcode, vid)` revision-keying shape — that is M-004's surface. Single-axis translations only. |
| C-002 | NEW | This mission MUST NOT extend `EntityQuery` with per-langcode filters or langcode in cache tags — that is the future ADR 015 listing-pipeline mission's surface. |
| C-003 | NEW | Backwards compatibility: entities of types with `translatable: false` (the existing universe) MUST behave identically to today. No schema migration is required for non-translatable types. |
| C-004 | NEW | `Waaseyaa\I18n\LanguageManager` MUST NOT be a hard dependency of `Waaseyaa\Entity` or `Waaseyaa\EntityStorage`. Wire-up is via optional dependency-injection (nullable constructor parameter); absence yields `'en'` as site-default. |
| C-005 | NEW | No vendor framework parity contract beyond ADR 017's stated scope; e.g. content translation moderation, language detection per request body, BCP-47 validation are NOT promised. |
| C-006 | NEW | The mission MUST land before any consumer (Minoo, sibling apps) declares an entity type `translatable: true`. The flag was a tombstone; flipping it pre-release would break boot (FR-002, FR-003, FR-004 throw at boot when prerequisites missing). |

## 6. Stable surface deliverables

The following symbols become **stable surface** per `stability-charter.md` §5.3 on merge:

- `Waaseyaa\Entity\TranslatableEntityInterface` (interface)
- `Waaseyaa\Entity\EntityTranslationException` (exception class with static factories)
- `Waaseyaa\Entity\EntityType::__construct(... translatable: bool ...)` (already exists; behavior now load-bearing)
- `Waaseyaa\Entity\FieldDefinition::translatable(bool): static` (new method)
- `Waaseyaa\Entity\FieldDefinition::isTranslatable(): bool` (new method)
- `Waaseyaa\EntityStorage\SaveContext::withLangcode(string): self` (new method)
- `Waaseyaa\Entity\EntityRepository::findTranslations(EntityInterface): array` (new method)
- Entity key string `'default_langcode'` (new well-known key in `entityKeys` map)
- Configuration keys `'translation.fallback_chain'`, `'translation.read_active_language'`
- Event names `PRE_TRANSLATION_INSERT`, `POST_TRANSLATION_INSERT`, `PRE_TRANSLATION_UPDATE`, `POST_TRANSLATION_UPDATE`, `PRE_TRANSLATION_DELETE`, `POST_TRANSLATION_DELETE`
- Access policy operation literal `'translate'`

The following surfaces are **internal** and may change without notice:

- Layout of `_data` blob (sql-blob) — opaque JSON
- Internal SQL schema shape of `<table>__translation` — accessible only via coordinator
- Internal cache keys for translation lookup

## 7. Behavior specs (normative)

### 7.1 Read

```
EntityRepository::find($id, $context = []):
  1. Load primary row for $id (sql-column) or default-langcode row (sql-blob).
  2. Construct EntityInterface with activeLangcode = default_langcode.
  3. Return; consumer calls ->getTranslation($lc) for non-default access.
```

### 7.2 getTranslation

```
$entity->getTranslation($lc):
  1. if $lc === activeLangcode: return $this
  2. if !hasTranslation($lc): throw EntityTranslationException::translationNotFound($lc)
  3. Fetch <table>__translation row for ($id, $lc) (sql-column) or _data fragment for $lc (sql-blob).
  4. Clone $this with field values for translatable fields replaced; activeLangcode = $lc.
  5. Non-translatable field values shared by reference with $this (FR-022, FR-029).
  6. Return cloned instance.
```

### 7.3 Write

```
EntityRepository::save($entity, SaveContext $ctx = SaveContext::default()):
  1. If entity is translatable and default_langcode unset: throw langcodeRequired (FR-034).
  2. lc = $ctx->langcode ?? $entity->activeLangcode()
  3. If entity is new:
     a. INSERT primary row + INSERT __translation row for default_langcode (sql-column)
        OR INSERT _data row for default_langcode (sql-blob)
     b. Dispatch PRE/POST_INSERT (entity-level)
     c. If $lc !== default_langcode: also INSERT __translation row for $lc (or sql-blob equivalent),
        dispatch PRE/POST_TRANSLATION_INSERT.
  4. If entity is existing:
     a. UPDATE primary row for non-translatable field deltas
     b. UPDATE __translation row for translatable field deltas at $lc
        (INSERT if !hasTranslation($lc), dispatching PRE/POST_TRANSLATION_INSERT;
         else dispatch PRE/POST_TRANSLATION_UPDATE)
  5. Apply pending removeTranslation() deletions in same UoW.
  6. Commit.
```

### 7.4 Field read with fallback

```
$entity->get($fieldName):
  1. If field is non-translatable: return value from default_langcode row (single source).
  2. If field is translatable:
     a. chain = config('translation.fallback_chain')($entity->activeLangcode(), $entity)
     b. for $lc in chain (de-duplicated):
          $value = $entity->loadFieldValue($fieldName, $lc)
          if $value !== null:
              record $entity->fieldLangcode[$fieldName] = $lc
              return $value
     c. record fieldLangcode = null
     d. return null
```

## 8. Migration semantics spec

### 8.1 Generator command

```
bin/waaseyaa make:migration --add-translations <entity_type_id> --default-langcode <lc>
```

Generates a migration file under `migrations/` that:

- For `sql-column`:
  - `CREATE TABLE <table>__translation` with columns: `entity_id`, `langcode`, plus all translatable fields, plus PK `(entity_id, langcode)` and FK to `<table>(entity_id)`.
  - `INSERT INTO <table>__translation SELECT entity_id, '<default-langcode>', <translatable fields...> FROM <table>` (backfill).
  - `ALTER TABLE <table> DROP COLUMN <translatable fields>` (post-backfill).
  - `ALTER TABLE <table> ADD COLUMN default_langcode VARCHAR(12) NOT NULL DEFAULT '<default-langcode>'`.

- For `sql-blob`:
  - `ALTER TABLE <table> ADD COLUMN default_langcode VARCHAR(12) NOT NULL DEFAULT '<default-langcode>'`.
  - `UPDATE <table> SET langcode = '<default-langcode>' WHERE langcode IS NULL OR langcode = ''`.
  - `ALTER TABLE <table> DROP PRIMARY KEY; ADD PRIMARY KEY (entity_id, langcode)`.

### 8.2 Failure modes

- **Existing data has `langcode` values that don't match `--default-langcode`**: generator MUST emit a warning and proceed with the data as-is (preserves multilingual data that has been hand-loaded).
- **Existing `langcode` column is missing**: error `MissingLangcodeColumnException`; user must add it first via a prior migration.
- **Backfill row count mismatch**: post-migration assertion fails; transaction rolls back.

## 9. Test surface

### 9.1 Contract suite

`packages/entity/testing/TranslatableEntityContractTest.php` exercises:

- T01: `defaultLangcode()` returns expected; throws when unset.
- T02: `activeLangcode()` matches loaded translation.
- T03: `hasTranslation($lc)` truthy/falsy.
- T04: `getTranslation($lc)` returns instance; throws on missing.
- T05: `addTranslation($lc)` allocates; throws on duplicate.
- T06: `removeTranslation($defaultLc)` throws.
- T07: `removeTranslation($otherLc)` succeeds; row gone after save.
- T08: `translations()` lists all extant langcodes, default first.
- T09: `fieldLangcode($field)` reports correct resolved langcode.
- T10: Non-translatable field reads identical across translations.
- T11: Translatable field reads fall through configured chain.
- T12: Fallback exhaustion returns `null`, `fieldLangcode()` returns `null`.

### 9.2 Backend conformance

Two subclasses run T01–T12 against `sql-blob` and `sql-column` backends with the `test_translatable_entity` fixture.

### 9.3 Integration tests

- I01: Saving the French translation writes only the French row (`sql-column`: only `__translation WHERE langcode='fr'` row updated).
- I02: Removing the French translation deletes only the French row; English row preserved.
- I03: `findTranslations()` returns all translations in single query (query-count assertion).
- I04: Translation-aware lifecycle events fire with langcode in payload.
- I05: Access policy `translate` operation receives langcode in context.
- I06: Migration generator `--add-translations` produces a valid, reversible migration.
- I07: `LanguageManager` wire-up: setting `translation.read_active_language: true` causes `find()` to return the active translation when present.

## 10. Work package decomposition (sketch)

Final WP decomposition lives in `/spec-kitty.plan` and `/spec-kitty.tasks-outline`. This sketch informs sequencing thinking only.

| WP | Title | Gates on | Notes |
|---|---|---|---|
| WP01 | `TranslatableEntityInterface` + exception hierarchy | — | Pure surface; no storage |
| WP02 | `EntityType` translatable boot validation | WP01 | Throws on missing keys / wrong interface |
| WP03 | `FieldDefinition::translatable()` | WP01 | Field-level flag |
| WP04 | `sql-blob` translation storage | WP01, WP02 | `_data` per-langcode; PK widening |
| WP05 | `sql-column` translation storage | WP01, WP02, WP03 | `__translation` sibling table |
| WP06 | Fallback chain + `fieldLangcode()` | WP04 OR WP05 | Read-path resolution |
| WP07 | `SaveContext::withLangcode` + write path | WP04, WP05 | Coordinator write semantics |
| WP08 | Lifecycle events with langcode | WP07 | `PRE/POST_TRANSLATION_*` |
| WP09 | Access policy `translate` op | WP01 | Independent surface |
| WP10 | `EntityRepository::findTranslations()` | WP04, WP05 | Single-query helper |
| WP11 | Migration generator `--add-translations` | WP04, WP05 | CLI extension |
| WP12 | `LanguageManager` wire-up + `read_active_language` config | WP06 | Optional DI |
| WP13 | Contract suite + fixtures | WP01, WP04, WP05 | Test infrastructure |
| WP14 | Documentation + charter update + M-004 unblock | All above | Mission close |

### 10.1 Parallelizable lanes

- **Lane A**: WP01 → WP02 → WP04 (sql-blob critical path)
- **Lane B**: WP03 → WP05 (sql-column, in parallel with Lane A from WP03)
- **Lane C**: WP09 (access policy, independent after WP01)
- **Sync point at WP06/WP07**: both backends present
- **Lane D**: WP11 (migration generator), parallel with WP07/WP08

### 10.2 Validation gate

WP14 acceptance requires:

1. All contract tests pass on both backends.
2. M-004 single-axis-translation BLOCKED bullet removable (ADR 015 listing-pipeline bullet remains).
3. Stability charter §5.3 updated.
4. PHPStan and PHPUnit gates green.

## 11. Acceptance criteria

Mission ships when:

1. **FR-001..FR-064 implemented** — surface complete, contract tests green.
2. **NFR-001..NFR-005 measurable** — performance and resource thresholds verified.
3. **C-001..C-006 honored** — no scope creep into M-004 / ADR 015 territory.
4. **Stability charter §5.3** lists new stable surface.
5. **M-004 single-axis-translation BLOCKED bullet** removable.
6. **Cookbook recipe** authored.
7. **Public surface map** updated.
8. **CHANGELOG `[Unreleased]`** bullet added with M-006 reference.

## 12. Success criteria (technology-agnostic)

| ID | Criterion |
|---|---|
| SC-01 | A developer can flip an entity type to `translatable: true` and save the same entity in two languages independently in ≤10 lines of consumer code (verified by cookbook recipe). |
| SC-02 | A non-translatable field read from any translation returns the same value (single-source-of-truth verified by contract test T10). |
| SC-03 | A translatable field with no value at the requested langcode resolves via the configured fallback chain in a single resolver pass (verified by contract tests T11/T12). |
| SC-04 | The mission's contract suite passes on both `sql-blob` and `sql-column` backends in CI in under 10 seconds wall time (NFR-004). |
| SC-05 | M-004 (`entity-storage-translatable-revisions`) single-axis-translation BLOCKED bullet is removed; M-004 remains BLOCKED only on ADR 015 listing pipeline. |

## 13. Validation entity selection (fixture)

This mission validates against a framework-internal fixture, not a consumer entity type.

**Fixture entity type:** `test_translatable_entity`

- Location: `packages/entity-storage/tests/Fixtures/TestTranslatableEntity.php`
- Translatable fields: `title` (string), `body` (text)
- Non-translatable fields: `created_at` (timestamp), `author_id` (entity_reference)
- Bundles: none (no bundle entity type)
- Tested against both `sql-blob` and `sql-column` backends.

Consumer-app migration (e.g., Minoo `teaching`) is a follow-up PR after this mission ships, not part of mission validation. (D4)

## 13a. Mission-close reconciliation (2026-05-13)

Implementation deviated from the original spec in a handful of intentional ways. Each delta below is honest about what shipped vs. what the spec text says, so future readers can read FRs in §3 without confusion.

- **`TranslatableInterface` (not `TranslatableEntityInterface`).** The spec FRs originally referred to `TranslatableEntityInterface` as a new interface to introduce. Shipped reality: the existing minimal `Waaseyaa\Entity\TranslatableInterface` stub was *expanded in place* rather than replaced. Affected FRs that name `TranslatableEntityInterface`: FR-006, FR-010, FR-014 — read those with `TranslatableEntityInterface` → `TranslatableInterface` mentally substituted.
- **`EntityEvent` is no longer `final`.** The spec listed `EntityEvent` as `final class`. Shipped reality removed `final` so `Waaseyaa\Entity\Event\TranslationEvent` can extend it. This is a documented, intentional minor public-surface change with no consumer breakage at the framework level (no first-party code extended `EntityEvent`; behavior is unchanged for users that did not extend). Surface map and charter §5.3 both record the change.
- **`language()` retained as a deprecated alias for `activeLangcode()`.** Not in the original FR list. Per research note R1, the prior single-method `language()` accessor remains on `TranslatableInterface` as a deprecated alias delegating to `activeLangcode()`. Removal is deferred to a future deprecation cycle per the stability charter.
- **`ContextAwareAccessPolicyInterface` companion (not signature change).** FR-049 originally implied extending `AccessPolicyInterface::access()` with a `$context` parameter. Shipped reality introduces `Waaseyaa\Access\ContextAwareAccessPolicyInterface` as a companion interface — existing policies stay binary-compatible; policies that need langcode context implement the companion. Pattern mirrors the existing `FieldAccessPolicyInterface` companion split.
- **`EntityRepository::__construct` gains two optional params.** Beyond what §6 spelled out, the constructor accepts two new optional parameters (a language manager and a fallback-chain resolver). Both default to `null` and the repo behaves identically to alpha.177 when omitted — no consumer-breaking change.
- **Scope expansion — `ContentEntityKeys` + `EntityMetadataReader`.** `default_langcode` plumbing required threading through the attribute-driven entity metadata path (`Waaseyaa\Entity\ContentEntityKeys`, `Waaseyaa\Entity\Metadata\EntityMetadataReader`) that the original spec did not list under "files changed". Both are internal-tier; no public surface impact.
- **`findTranslations()` lifted to interface level on storage SPI too.** The spec sketched `findTranslations()` only on `EntityRepository`. Shipped reality also declares it on `EntityRepositoryInterface` and on `EntityStorageDriverInterface` so alternative driver implementations carry the contract. Stable surface in both places.

These deltas were caught and documented in WP14 (mission close). No FR's *behavior* changed — the renames and structural splits do not loosen any acceptance criterion.

## 14. Open questions

None at draft time — all decisions resolved during discovery (D1–D4 below). New open questions discovered during `/spec-kitty.plan` will be logged here.

**Decision record:**

- **D1** Default-langcode source: **fail-fast.** Translatable entity types throw `EntityTranslationException::langcodeRequired()` on save if no langcode set (FR-034).
- **D2** `removeTranslation($defaultLc)`: **throws `EntityTranslationException::cannotRemoveDefault()`** — caller must `$repository->delete($entity)` instead (FR-012).
- **D3** Query surface: **minimum useful** — `findBy()` already filters by `langcode` column; this mission adds only `findTranslations()` (FR-042). Listing pipeline deferred to ADR 015 mission.
- **D4** Validation: **framework-only.** Fixture `test_translatable_entity` in tests (FR-059, §13). Minoo `teaching` migration is a post-release consumer-app PR.

## 15. Assumptions

- ADR 017 §"Storage shape" is accepted as-is; this mission implements it, doesn't revise it.
- `EntityType::__construct` parameter `translatable: bool` (already present at `packages/entity/src/EntityType.php:75`) keeps the same default `false`.
- `Waaseyaa\I18n\LanguageManager` does not require modification; this mission only consumes it.
- No existing entity type sets `translatable: true` today (verified 2026-05-12 by grep across `packages/*/src/`); mission is therefore additive, not migration-required for first-party code.
- Spec Kitty mission_id `01KRF0FQ0AA42F434JNAA56WFB` is assigned and immutable for this mission.

## 16. References

- [ADR 017](../adr/017-per-field-translation.md) — governing per-field translation decision.
- [ADR 010](../adr/010-multi-backend-field-storage.md) — multi-backend storage, extended here for translation.
- [ADR 011](../adr/011-entity-lifecycle-events.md) — lifecycle event contract, extended with translation events.
- [`stability-charter.md`](stability-charter.md) §3.2 criterion 9 — beta gate this mission clears.
- [`entity-storage-v2.md`](entity-storage-v2.md) — shipped sibling mission (M-001); structural template for this spec.
- [`entity-storage-translatable-revisions.md`](entity-storage-translatable-revisions.md) — M-004, BLOCKED on this mission's substrate.
- [`public-surface-map.md`](public-surface-map.md) — updated by this mission per §6.
- [`packages/i18n/README.md`](../../packages/i18n/README.md) — language negotiation surface this mission wires into the read path.

## 17. Mission metadata for Spec Kitty

```json
{
  "mission_id": "01KRF0FQ0AA42F434JNAA56WFB",
  "mission_slug": "entity-storage-translations-v1-01KRF0FQ",
  "mission_type": "software-dev",
  "target_branch": "main",
  "friendly_name": "Entity Storage: Translations v1",
  "display_mission_id": "M-006",
  "estimated_work_packages": 14,
  "agent_assignments": {
    "implementer": "sonnet",
    "reviewer": "opus",
    "arbiter": "opus"
  },
  "escalation_target": "@jonesrussell",
  "escalation_after_n_rejections": 3,
  "governing_adrs": ["017-per-field-translation"],
  "charter_dependencies": ["stability-charter#3.2.9", "stability-charter#5.3"],
  "external_dependencies": [],
  "downstream_unblocks": ["M-004 (single-axis-translation prerequisite of 2)"],
  "validation_entity_type": "test_translatable_entity (fixture)",
  "validation_consumer": "framework-only"
}
```
