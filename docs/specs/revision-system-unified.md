# Revision system (unified, with an optional translation axis)

**Status:** Design (2026-06-09). Supersedes the parallel two-axis storage stack
described in [`entity-storage-two-axis.md`](entity-storage-two-axis.md): that
mission (M-004) built a *separate* `vid`-based storage stack
(`RevisionableSqlBlobStorage` / `RevisionableSqlColumnStorage` /
`RevisionRowHydrator` / `RevisionableEntityStorageInterface`) that was never
wired into the kernel. This doc folds its per-language design into the single
live revision system and retires that parallel stack.

## 1. One system, an optional second axis

There is **one** revision system: `EntityRepository` + `RevisionableStorageDriver`
+ `SqlSchemaHandler`. It has two axes; the translation axis is optional.

- **Revision axis (always, when `revisionable: true`).** The base row carries a
  `revision_id` pointer (and, since alpha.195, a `published_revision_id`
  pointer). Each save snapshots the entity into `<entity>_revision`
  (single underscore), `revision_id` monotonic per entity. **Unchanged from
  today.**
- **Translation axis (only when also `translatable: true`).** The entity
  additionally keeps **per-language revision history** in a sibling table
  `<entity>__translation__revision`, with **independent per-language
  sequencing** (editing English does not bump the Anishinaabemowin revision
  count, and vice-versa).

A `revisionable`-only entity (FNPI's `page`, `identity_pillar`, `document`,
`drive_asset` today) is the **zero-translation default** and behaves exactly as
it does now.

> **Hard guardrail.** Every change for the translation axis is additive and
> gated on `EntityTypeInterface::isTranslatable()`. The single-axis code path is
> byte-for-byte unchanged (FNPI page-parity depends on it). A single-axis entity
> never gets a `<entity>__translation__revision` table.

## 2. Schema

| Table | When | Key | Carries |
|---|---|---|---|
| `<entity>` | always | `id` (+ `revision_id`, `published_revision_id` pointers) | current/tip values |
| `<entity>_revision` | `revisionable` | `(entity_id, revision_id)` | full snapshot per default-language revision (unchanged) |
| `<entity>__translation__revision` | `revisionable` **and** `translatable` | `(entity_id, langcode, revision_id)` | per-language snapshot; `revision_id` monotonic **per `(entity_id, langcode)`** |

We keep A's `revision_id` idiom on both tables (not the M-004 `vid` surrogate),
so single-axis and the translation axis read the same way and the existing
single-axis path is untouched. `<entity>__translation__revision` columns:
`entity_id`, `langcode`, `revision_id`, `revision_created`, `revision_log`, and
the field values (a `_data` JSON blob for sql-blob entities, the framework
default). A composite `UNIQUE (entity_id, langcode, revision_id)` plus an index
`(entity_id, langcode, revision_id DESC)` expresses the logical key and serves
the per-language tip/list hot paths.

## 3. Save contract

`EntityRepository::save($entity, SaveContext $ctx)` — `SaveContext` already
carries `withLangcode()` / `withTranslations()`.

- **Single-axis (no langcode):** unchanged — one row to `<entity>_revision`,
  base pointer advanced.
- **One language (`withLangcode('oj')`)** on a two-axis entity: one row to
  `<entity>__translation__revision` for that langcode, its own `revision_id`
  sequence; the per-`(entity, langcode)` current pointer advances. Other
  languages are untouched.
- **Atomic multi-language (`withTranslations(['en' => ..., 'oj' => ...]))`:** one
  transaction, one row per langcode; all-or-nothing (rolls back as
  `PartialSaveException` on any failure).

Driver support already exists (`RevisionableStorageDriver::writeRevision(..., ?string $langcode)`
→ `writePerLangcodeRevision`); Phase 1 makes the schema, the repository wiring,
and the load side real and tested rather than dormant.

### 3a. Unified two-axis write (`saveTranslation`)

Phase 1 records per-language *revisions*; it does not by itself move the peer
*base row* that holds a language's current value. `EntityRepository::saveTranslation($entityId, $langcode, array $values, ?string $log)`
closes that gap: in **one transaction** it both

1. upserts the peer `(id, langcode)` base row (the language's current value —
   blob entities ride `_data`; the label column mirrors the label field; a new
   peer row copies the shared `uuid` from the default row so the partial-unique
   UUID index, which only constrains default-langcode rows, is satisfied), and
2. writes the per-language revision (`writeRevision(..., $langcode)`).

The base row and its history therefore move together: a language is a true peer
with its own base row and its own independent `revision_id` sequence, not an
overlay on another language's row. The default-language row and any
non-translatable fields are untouched. This is the single repository entry point
for editing a translation; storage logic stays in one place (the repository),
not orchestrated across two storage APIs by the application. `loadTranslation($id, $langcode)`
reads a language's current value back from its peer base row (the driver's
`read(..., $langcode)` selects the peer row directly on a widened-PK base table).

## 4. Load contract

- `find($id)` — tip of each language (latest `revision_id` per `(entity, langcode)`),
  single-axis unchanged.
- `loadRevision($id, $revisionId, ?$langcode)` — a specific revision; `langcode`
  null = the single-axis `<entity>_revision` path (unchanged), set = the
  per-language table.
- `listRevisions($id, ?$langcode)` — newest-first; per-language when `langcode`
  is given (independent sequence), the whole single-axis history when not.
- Published pointer (`loadPublishedRevision` / `setPublishedRevision`) is
  unchanged and remains on the revision axis.

## 5. Access

Per-language revision access composes through
`Waaseyaa\Access\Policy\RevisionPolicyComposition` (kept): a policy may inspect
`$context['langcode']` for `view_revision` / `revert_revision` so, e.g., one
role sees English-only history and another sees both languages.

## 6. What retires

The unwired parallel `vid` stack is removed once the capability above lands and
is tested in the live system:

- `RevisionableSqlBlobStorage`, `RevisionableSqlColumnStorage` (standalone vid storages)
- `RevisionRowHydrator` (their hydrator)
- `RevisionableEntityStorageInterface` (their contract)
- `RevisionPruner` if it only served the above (else kept and pointed at A)

Kept: `SaveContext`, the typed exceptions, `RevisionPolicyComposition`, the
`make:storage-migration --add-revisions/--add-translations` generators, and
`RevisionTableBuilder`/`TranslationSchemaHandler` where still used by the
translation substrate.

## 7. Acceptance

- Framework suite green; the existing single-axis revision tests pass
  **unchanged** (byte-for-byte regression gate).
- A new two-axis E2E proves independent per-language sequencing (the M-004
  FR-043 timeline: create en → add oj → edit en ×3 → edit oj ×2 ⇒ en at
  revision 4, oj at revision 3).
- FNPI full suite + page-parity green after the framework pin bump.
