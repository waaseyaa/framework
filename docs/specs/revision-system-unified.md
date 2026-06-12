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
`entity_id`, `langcode`, `revision_id`, `revision_created`, `revision_log`,
`revision_author`, and the field values (a `_data` JSON blob for sql-blob
entities, the framework default). A composite `UNIQUE (entity_id, langcode, revision_id)`
plus an index `(entity_id, langcode, revision_id DESC)` expresses the logical
key and serves the per-language tip/list hot paths.

### 2a. Revision metadata columns (both live tables)

Every revision row on **both** live tables carries the same metadata block:

| Column | Type | Nullability | Semantics |
|---|---|---|---|
| `revision_created` | varchar(32) | NOT NULL | write timestamp |
| `revision_log` | text | NULL | optional log message |
| `revision_author` | int | **NULL, no default** | acting account uid that created the revision. Soft FK — no FK constraint, no ON DELETE: revision history survives user deletion. `0` if and only if the anonymous account acted; SQL NULL = no acting context (never coerced to 0). |

`revision_author` was added by mission `revision-audit-provenance-01KTWY5V`
(FR-001/FR-003). Its name and nullable-int soft-FK definition are adopted
verbatim from the dormant `RevisionTableBuilder` dialect (see §6) so exactly
one authoritative author definition exists — the live one described here.

**Additive sync for pre-existing tables.** `ensureRevisionTable()` and
`ensureTranslationRevisionTable()` no longer pure-early-return when the table
exists: they additively add `revision_author` if missing (`fieldExists` →
`addField`, the `ensureBundleSubtable()` pattern). Idempotent; no other column
is touched and no row is rewritten. The sync runs at kernel boot / `db:init`
(both production callers — the kernel repository factory and
`EntitySchemaSync` — flow through `SqlSchemaHandler`), never per save.
Pre-existing rows keep SQL NULL and read back a `null` author with zero
migration.

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

## 4a. Revision authorship (provenance)

Mission `revision-audit-provenance-01KTWY5V` (#1644). Every revision-creating
operation records the **acting account** into `revision_author`, and revision
loads finally hydrate the `RevisionMetadata` read model.

### Recording

- **Coverage:** all production revision writes flow through
  `EntityRepository` → `RevisionableStorageDriver::writeRevision(..., ?int $author)`:
  `doSave()` (immediate and deferred-id writes), `rollback()`,
  `saveTranslationRevision()` / `saveTranslationRevisions()`,
  `saveTranslation()`, and `backfillInitialRevisions()`. No path constructs a
  revision row without going through the resolution. The driver writes what it
  is given; resolution is the repository's job.
- **Resolution order** (computed once per operation, never per row):
  1. `SaveContext::withActorUid(?int)` override when set — including an
     explicit `withActorUid(null)`, which forces a NULL author inside an
     authenticated request (system-attributed maintenance writes). The
     override is a `(actorUid, actorOverridden)` pair: a context that never
     called `withActorUid()` defers to the ambient holder.
  2. The ambient request-scoped acting-account context
     (`Waaseyaa\Access\Context\AccountContextInterface`, attached to the
     repository by the kernel factory via `setAccountContext()`) —
     `current()?->id()` cast to int.
  3. `null`.
- **Null-vs-0 rule:** `0` is recorded if and only if the resolved actor IS the
  anonymous account (id 0). Absence of an acting context (CLI, queue,
  bootstrap — anywhere nothing set the context) records SQL NULL. No fallback
  or default ever coerces null to 0.
- **Revert authorship:** a revision created by `rollback()` is authored by
  whoever performed the revert, resolved at revert time. The reverted-to
  (target) revision row is never modified, and its original author never
  leaks onto the new revision (the copied row's `revision_author` is
  stripped before the write; the explicit author parameter is authoritative).
- **Immutability:** in-place revision updates
  (`SaveContext::withoutNewRevision()` → `updateRevision()`) never touch
  `revision_author` — it is immutable revision metadata, like
  `revision_created` / `revision_log`.

### Readback

`loadRevision()` and the translation-revision load paths (hence
`listRevisions()` / `listTranslationRevisions()` transitively) hydrate

```
RevisionMetadata(
    revisionCreatedAt: \DateTimeImmutable   ← revision_created
    revisionAuthor:    ?int                 ← revision_author (SQL NULL → null)
    revisionLog:       ?string              ← revision_log
)
```

and attach it via `setRevisionMetadata()` on entities implementing
`RevisionableEntityInterface` (instanceof-guarded — non-revisionable entity
classes are unaffected). `revisionMetadata()->revisionAuthor` round-trips
exactly what was recorded: `int` for an account (including `0` for
anonymous), `null` for no actor. Rows whose `revision_author` is SQL NULL —
including every row created before the column existed — hydrate
`revisionAuthor: null`; no error, no sentinel.

### Pointer-move event (`RevisionPointerMovedEvent`)

`Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent` is dispatched (by
FQCN) when a revision **pointer** moves without creating a new revision:

- `setPublishedRevision()` — operation `'publish'`; `fromRevisionId` is the
  prior `published_revision_id` (null when previously unpublished).
- `setCurrentRevision()` — operation `'revert'`; `fromRevisionId` is the
  prior base `revision_id` pointer.

Payload: `entityTypeId`, `entityId`, `operation`, `fromRevisionId: ?int`,
`toRevisionId: int`, plus `actorUid: ?int` — the actor resolved at dispatch
time (same resolution and null-vs-0 semantics as above), carried so listeners
need not re-resolve. Both dispatches happen **after** the pointer transaction
commits (a rolled-back move produces no event) and ride alongside — not
replacing — the legacy `EntityEvents::REVISION_REVERTED` dispatch.
`rollback()` dispatches **no** pointer event: it creates a new revision
(authorship covered by `revision_author`) and flows through
`REVISION_CREATED`. The audit subsystem subscribes via
`PublishPointerAuditListener` (`revision.publish` / `revision.revert` kinds —
see `docs/specs/ocap-audit-log.md`).

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

### 6a. Dormant `__revision` emission dialect — explicitly retired (FR-009)

Mission `revision-audit-provenance-01KTWY5V` closes the two-dialect ambiguity
the M-004 leftovers created:

- **`RevisionTableBuilder`'s `<entity>__revision` (double-underscore) vid
  emission dialect is non-live and superseded** — including its
  `revision_created_at` / `revision_author` / `revision_log` metadata block
  and its surrogate `vid` primary key. No production code creates or reads
  those tables. Its only production reference,
  `TranslationSchemaHandler::syncTwoAxis()`, **has no production callers**
  (the kernel and `EntitySchemaSync` call `sync()` only); everything else is
  tests.
- **The single authoritative author definition is the live dialect's
  `revision_author` column** on `<entity>_revision` /
  `<entity>__translation__revision` (§2a) — the dormant dialect's column
  definition (name, nullable int, soft FK) was adopted verbatim into the live
  tables, so its author semantics now live exclusively there. The
  `RevisionMetadata` docblock references the live table/columns
  (`revision_created`, not the dormant `revision_created_at`).
- **Deletion of the dormant stack is NOT part of this retirement.** It stays
  conditioned on this section's staged plan (live two-axis capability tests
  before removal). Do not wire `syncTwoAxis()` into production to "reconcile
  by activation" — it would create second, parallel `__revision` tables
  alongside the live `_revision` ones.

## 7. Acceptance

- Framework suite green; the existing single-axis revision tests pass
  **unchanged** (byte-for-byte regression gate).
- A new two-axis E2E proves independent per-language sequencing (the M-004
  FR-043 timeline: create en → add oj → edit en ×3 → edit oj ×2 ⇒ en at
  revision 4, oj at revision 3).
- FNPI full suite + page-parity green after the framework pin bump.

<!-- Spec reviewed 2026-06-12 - mission revision-audit-provenance-01KTWY5V WP05: added §2a (revision_author column + additive sync on both live revision tables), §4a (authorship recording/resolution order/null-vs-0/revert authorship, RevisionMetadata hydration on loads, RevisionPointerMovedEvent), §6a (explicit FR-009 retirement of the dormant RevisionTableBuilder `<entity>__revision` vid dialect incl. its revision_created_at metadata block; live revision_author is the single authoritative author definition). Refs #1644, #1645. -->

