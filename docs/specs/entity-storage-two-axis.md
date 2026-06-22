<!-- Spec reviewed 2026-05-20 - M-006 translation-hardening: added §11 translation access-gate convention -->
<!-- Spec reviewed 2026-06-09 - alpha.201 doc-drift: this spec describes the RETIRED M-004 vid stack (never kernel-wired, removed alpha.196). Added a SUPERSEDED banner pointing to revision-system-unified.md (the live revision_id model). The vid/SaveContext::withTranslations content below is retained for historical/audit context only. -->
# Entity Storage — Two-Axis (Revisionable × Translatable)

> **⚠️ SUPERSEDED (alpha.196).** This spec describes the **M-004 `vid`-based**
> two-axis storage stack (`RevisionableSqlBlobStorage` /
> `RevisionableSqlColumnStorage` / `RevisionRowHydrator` /
> `RevisionableEntityStorageInterface`), which was **never wired into the kernel**
> and was **retired in alpha.196**. The live two-axis model folds the per-language
> design into the single `EntityRepository` revision system: it uses
> **`revision_id`** (not the `vid` surrogate), `<entity>_revision` (single
> underscore) for the revision axis, and `<entity>__translation__revision` keyed
> `(entity_id, langcode, revision_id)` with independent per-`(entity, langcode)`
> sequencing, edited via `EntityRepository::saveTranslation()` /
> `loadTranslation()` / `listTranslationRevisions()`. **Read
> [`revision-system-unified.md`](revision-system-unified.md) for the live, canonical
> model — do not build against the `vid` / `<entity>__revision` /
> `SaveContext::withTranslations` shapes below; they are retained for M-004
> historical/audit context only.**

**Status:** Superseded by [`revision-system-unified.md`](revision-system-unified.md) (alpha.196); retained for historical/audit context (M-004, 2026-05-17).
**Audience:** framework maintainers, application developers declaring two-axis entity types, operators.
**Governing ADRs:** [ADR 016](../adr/016-revisions-first-class.md) (revisions first-class) + [ADR 017](../adr/017-per-field-translation.md) (per-field translation).
**Charter linkage:** [`stability-charter.md`](stability-charter.md) §5.3 (Entity / storage) — two-axis surface block.
**Cookbook:** [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md).
**Upgrade notes:** [`../upgrade-notes/two-axis-storage.md`](../upgrade-notes/two-axis-storage.md).

---

## 1. When to opt in

Declare an entity type as two-axis when **both** of the following are true for at
least one field:

1. The field is **user-edited** and the edit history must survive (revisionable).
2. The field carries **translated content** that needs per-language sequencing
   (translatable).

Reference consumer: Minoo `teaching` (Anishinaabemowin pedagogy + English gloss,
with editorial revision history per language).

If only one axis applies, use the single-axis path:

- Revisionable only → ADR 016 / [`entity-storage-v2.md`](entity-storage-v2.md) §revisions
- Translatable only → ADR 017 / [`entity-storage-translations-v1.md`](entity-storage-translations-v1.md)

**Both flags optional, both default to `false`.** Single-axis output is
byte-for-byte unchanged by M-004 (spec §12.3 R-A regression gate).

---

## 2. Schema shape

Two-axis emits **two coordinated tables**:

```
<entity>                           -- primary (non-translatable + identity)
<entity>__revision                 -- revision archive for non-translatable fields
<entity>__translation__revision    -- per-(entity_id, langcode, vid) translation revisions
```

### 2.1 `<entity>__revision` (non-translatable revisions)

Same shape as the single-axis revision table (ADR 016), but **only carries
non-translatable field columns**. Translatable field changes do NOT bump this
table.

### 2.2 `<entity>__translation__revision` (two-axis only)

```sql
CREATE TABLE <entity>__translation__revision (
    vid                  INTEGER PRIMARY KEY,   -- surrogate; SQLite ROWID / Postgres SERIAL
    <id_col>             <pk_type>,             -- soft FK to primary table
    langcode             TEXT NOT NULL,
    revision_created_at  TEXT,                  -- ISO-8601 / TIMESTAMPTZ
    revision_author      INTEGER,               -- nullable UID; soft FK
    revision_log         TEXT,                  -- nullable
    -- one column per TRANSLATABLE FieldDefinition (sql-column primary)
    -- or a single _data TEXT blob (sql-blob primary)
    UNIQUE (<id_col>, langcode, vid),
    INDEX  (<id_col>, langcode, vid DESC)       -- listRevisions($langcode) hot path
);
```

**Composite PK semantics.** The `(entity_id, langcode, vid)` triple is the
**logical primary key**. The surrogate `vid PRIMARY KEY` exists to make
`loadRevision($vid)` ergonomic (one round-trip, no compound lookup). The
`UNIQUE (entity_id, langcode, vid)` index expresses the logical PK and is
load-bearing for the listing-pipeline filter resolver. See R-01 in
`kitty-specs/entity-storage-translatable-revisions-01KRCDEE/contracts/composite-pk.md`.

### 2.3 Forbidden-backend guard (FR-006)

Translatable fields routed to backends that cannot host per-language storage
(`vector`, `remote`, custom non-sql backends) raise
{@see Waaseyaa\\EntityStorage\\Exception\\StorageMigrationException::unsupportedTwoAxisField()}
at boot. Only `sql-column` and `sql-blob` are allowed for translatable fields in
two-axis entity types.

---

## 3. Save contract

### 3.1 Single-language write

```php
$repository->save($entity, (new SaveContext())->withLangcode('en'));
```

Equivalent to M-006 behaviour: writes one row to
`<entity>__translation__revision` for `('en', vid)`. Non-translatable fields
write a new row to `<entity>__revision` **only if they changed**. (Independent
sequencing — see §5.)

### 3.2 Atomic multi-language write

```php
$repository->save($entity, (new SaveContext())->withTranslations([
    'en'  => ['title' => 'Teaching about turtles', 'body' => '...'],
    'oj'  => ['title' => 'Mikinaak-gikinoo\'amaadiwin', 'body' => '...'],
]));
```

`SaveContext::withTranslations(array $langcodes): self` is an immutable copy
carrying a `[langcode => values]` map. Empty arrays are rejected. The driver
opens a single transaction, writes one row per langcode to
`<entity>__translation__revision`, and commits atomically. If any row fails the
entire write rolls back.

### 3.3 Historical-revision guard

Writes targeting a historical (non-tip) revision raise
{@see Waaseyaa\\Entity\\Exception\\EntityTranslationException::historicalRevisionWrite(int $vid, string $langcode)}.
Stable `errorCode`: `'historical_revision_write'`.

---

## 4. Load contract

### 4.1 Tip-load (default)

`$repository->find($id)` returns the entity hydrated from the **tip** of every
language: latest `vid` per `(entity_id, langcode)`. Non-translatable fields read
from the latest `<entity>__revision` row.

### 4.2 Specific revision

`$storage->loadRevision($vid)` returns the entity at that `vid`. For two-axis
types the surrogate `vid` is global across languages; the row's `langcode`
column carries the language the revision was authored in.

### 4.3 Non-translatable field fallback

When a request asks for a langcode that has no translation, non-translatable
fields are returned from `<entity>__revision`. Translatable fields fall back via
the M-006 fallback chain (`translation.fallback_chain` config key).

### 4.4 Revision listing

`$storage->listRevisions(RevisionableEntityInterface $entity): iterable` yields
all revisions in monotonic `vid` order. Each yielded row carries `langcode`
metadata so the consumer can filter. For two-axis types, languages have
**independent sequencing** — see §5.

---

## 5. Independent per-language sequencing

Editing English **does not** bump Anishinaabemowin's revision count, and vice
versa. The `<entity>__translation__revision` table has its own surrogate `vid`
sequence; each language tracks its own latest revision via the
`(entity_id, langcode, vid DESC)` index.

**Example timeline (Minoo `teaching` E2E, FR-043):**

| Step | Action               | English revisions | Anishinaabemowin revisions |
|------|----------------------|-------------------|----------------------------|
| 1    | Create (en)          | 1                 | 0                          |
| 2    | Add `oj` translation | 1                 | 1                          |
| 3    | Edit `en` ×3         | 4                 | 1                          |
| 4    | Edit `oj` ×2         | 4                 | 3                          |

Total revisions across the table: 7 rows in `<entity>__translation__revision`.
Non-translatable field edits at any step bump `<entity>__revision` by 1.

---

## 6. Exception surface

| Exception                                          | Stable `errorCode`              | When raised                                                                                |
|---------------------------------------------------|----------------------------------|--------------------------------------------------------------------------------------------|
| `StorageMigrationException::unsupportedTwoAxisField()` | `'unsupported_two_axis_field'` | Translatable field routed to `vector`/`remote`/etc. (FR-006).                              |
| `StorageMigrationException::noOpPromotion()`      | `'no_op_promotion'`             | `make:storage-migration --add-translations`/`--add-revisions` on an already-two-axis type. |
| `EntityTranslationException::historicalRevisionWrite()` | `'historical_revision_write'` | Save targets a historical (non-tip) revision.                                              |

Both exception classes are `final readonly` on stable surface (charter §5.3 +
public-surface-map). Construction is restricted to factory methods.

---

## 7. Listing integration

`Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver` extends the M-007
listing pipeline (`Waaseyaa\Listing\ListingResolver`) for two-axis entity
types. The resolver:

1. Joins `<entity>__revision` to `<entity>__translation__revision` on
   `(entity_id, vid)` matching the **latest-by-langcode** per row.
2. Applies the langcode filter (`Filter::langcode($lc)`) and the active
   `language.content` cache context.
3. Emits `entity:<type>:<id>:<langcode>` cache tags from
   `AfterSaveEvent::affectedLangcodes()`.

See [`listing-pipeline-v1.md`](listing-pipeline-v1.md) and
[`../conventions/cache-tags-and-contexts.md`](../conventions/cache-tags-and-contexts.md)
for the surrounding cache + context vocabulary.

---

## 8. Access policy composition

Entity-level access (`view`, `update`, `delete`) is reused across both axes.
Revision-level access (`view_revision`, `revert_revision`) composes with
entity-level via `Waaseyaa\Access\Policy\RevisionPolicyComposition`. For
per-language access (e.g. Coordinator role sees English-only history,
Knowledge-Keeper sees both languages), implement
`ContextAwareAccessPolicyInterface` and inspect `$context['langcode']` for the
`view_revision` operation.

Reference fixture: FR-044 in
`tests/Integration/Phase29/MinooTeachingTwoAxisE2ETest.php` exercises the
Coordinator vs Knowledge-Keeper policy.

---

## 9. Performance notes

- **Non-translatable field fallback** costs one extra read against
  `<entity>__revision` per load. Hot-path loads should be cached at the listing
  resolver layer; per-entity reads are negligible.
- **Atomic multi-language save** holds a single transaction across N langcodes.
  Lock footprint scales with langcode count; keep
  `SaveContext::withTranslations()` writes to < 5 langcodes per call for
  predictable latency.
- **Pruning is near-mandatory** for high-edit two-axis entities. The
  `Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` value object expresses
  per-language retention (e.g. "keep last 20 English + last 20 Anishinaabemowin").
  Without pruning the translation revision table grows O(edits × langcodes).
- **Composite-PK index utilisation.** Always filter by `(entity_id, langcode)`
  before `vid` in custom queries; the `(entity_id, langcode, vid DESC)` index is
  optimised for `listRevisions($langcode)` and tip-lookup.

---

## 10. Migration generator support

`bin/waaseyaa make:storage-migration --add-revisions` (handler:
`Waaseyaa\CLI\Handler\AddRevisionsMigrationGenerator`) promotes an existing
translatable entity type to two-axis by emitting the `<entity>__revision` +
`<entity>__translation__revision` schemas. No-op promotion raises
`StorageMigrationException::noOpPromotion()`.

Companion: `--add-translations` (handler:
`Waaseyaa\CLI\Handler\AddTranslationsMigrationGenerator`) promotes a
revisionable-only type to two-axis. The two handlers are interchangeable on the
input side — they converge on the same two-table output.

---

## 11. Translation access-gate convention

M-006 (`translation-hardening-01KS3RY9`) added `TranslationController` which gates every
translation sub-endpoint via `EntityAccessHandler::check()`. The five HTTP methods map to
the following abilities:

| HTTP method | Route                                           | Ability checked |
|-------------|-------------------------------------------------|-----------------|
| `GET`       | `/api/{type}/{id}/translations`                 | `view`          |
| `GET`       | `/api/{type}/{id}/translations/{langcode}`      | `view`          |
| `POST`      | `/api/{type}/{id}/translations/{langcode}`      | `create`        |
| `PATCH`     | `/api/{type}/{id}/translations/{langcode}`      | `update`        |
| `DELETE`    | `/api/{type}/{id}/translations/{langcode}`      | `delete`        |

Access failures always return a generic `403 Forbidden` JSON:API error document (anti-enumeration:
the same shape is returned regardless of whether the entity exists or the account lacks the
required ability).

### Langcode validation

Operator-supplied langcodes (e.g. in `AddTranslationsMigrationGenerator`) are validated against
the BCP-47 pattern constant:

```
Waaseyaa\Entity\LangcodeValidator::BCP47_PATTERN
```

This regex accepts language tags with optional script (`-Latn`) and region (`-CA`) subtags (e.g.
`en`, `fr-CA`, `zh-Hant-TW`). Tags that do not match are rejected with a descriptive validation
error before any storage operation is attempted.

### fieldLangcode() compile-time enforcement

`TranslatableInterface` now declares `fieldLangcode(): string` (added in WP03) so
non-trait implementors get compile-time enforcement of the per-field language accessor.
See `packages/entity/src/TranslatableInterface.php`.

---

## 12. Cross-references

- Charter §5.3 — Two-axis stable surface (added by M-004).
- ADR 016 — Revisions first-class.
- ADR 017 — Per-field translation.
- [`entity-storage-v2.md`](entity-storage-v2.md) — Revisions + multi-backend foundation.
- [`entity-storage-translations-v1.md`](entity-storage-translations-v1.md) — M-006 per-field translation substrate.
- [`entity-storage-translatable-revisions.md`](entity-storage-translatable-revisions.md) — M-004 planning spec / audit trail.
- [`listing-pipeline-v1.md`](listing-pipeline-v1.md) — Listing pipeline + cache tags.
- [`../cookbook/translatable-revisionable-entities.md`](../cookbook/translatable-revisionable-entities.md) — Operator cookbook.
- [`../upgrade-notes/two-axis-storage.md`](../upgrade-notes/two-axis-storage.md) — Upgrade guide.

---

## 13. Mission post-mortem (M-004, 2026-05-17)

All eight WPs landed on lane-a (sequential single-lane execution).

| WP   | Subject                                                   | Net new public FQCNs                                              |
|------|-----------------------------------------------------------|-------------------------------------------------------------------|
| WP01 | sql-column two-axis schema + boot guard (FR-006)          | (extends `RevisionTableBuilder::buildTwoAxis()`)                  |
| WP02 | sql-blob two-axis schema                                  | `Waaseyaa\EntityStorage\Schema\TranslationSchemaHandler`          |
| WP03 | `SaveContext::withTranslations()` + atomic multi-lang save | (extends `SaveContext`)                                           |
| WP04 | Two-axis load + deletion + typed exception surface         | `StorageMigrationException`, `EntityTranslationException::historicalRevisionWrite()` |
| WP05 | Two-axis access policy composition                         | `Waaseyaa\Access\Policy\RevisionPolicyComposition`                |
| WP06 | `make:storage-migration --add-revisions` flag              | (CLI surface; handlers internal)                                  |
| WP07 | Listing-pipeline integration                                | `Waaseyaa\EntityStorage\Listing\TwoAxisFilterResolver`            |
| WP08 | Validation + docs closure (this spec)                      | —                                                                 |

Driver-level orchestrator added across WP03/WP04:
`Waaseyaa\EntityStorage\Driver\RevisionableStorageDriver`.

Two-axis pruning policy added in WP05:
`Waaseyaa\EntityStorage\Revision\RevisionPruningPolicy` (distinct from the
M-001 `Waaseyaa\EntityStorage\RevisionPruningPolicy` single-axis policy).

Cross-WP cleanup landed in WP08: the WP01 `\RuntimeException` marker in
`RevisionTableBuilder::assertNoTranslatableFieldsOnUnsupportedBackend()` was
swapped for the typed `StorageMigrationException::unsupportedTwoAxisField()`
factory introduced in WP04. The literal `unsupportedTwoAxisField` token is
preserved in the factory message so contract tests continue to pass.

## Related: Versioned blob media abstraction (DIR-005)

`MediaVersion` (mission `versioned-blob-media-abstraction-01KSEFTJ`) is a
**non-revisioned, non-translatable** entity that sits alongside the two-axis
storage shape rather than inside it. Each `MediaVersion` row represents one
immutable CAS blob pointer (`blob_uri`, `sha256`) for a parent `media` entity.
Version identity is a monotonic `vid` integer scoped to the parent UUID rather
than the core-entity revision integer, deliberately avoiding the translatable ×
revisioning lifecycle so the CAS lineage remains append-only and auditable.

See `docs/specs/` for the versioned-blob-media spec (to be created in a
follow-up spec pass) and `packages/media/src/Version/MediaVersionRepository.php`
for the storage implementation.
