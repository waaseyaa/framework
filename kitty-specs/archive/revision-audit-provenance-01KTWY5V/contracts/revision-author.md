# Contract: Revision Author Recording & Readback

**Mission**: revision-audit-provenance-01KTWY5V | **Requirements**: FR-001..FR-003, FR-009, NFR-001, C-001

Applies to every revision created through `EntityRepository` (the only production caller of `RevisionableStorageDriver::writeRevision()` — all seven sites).

## Recording

1. **Every standard save path records the resolved actor**: `doSave()` (immediate and deferred-id revision writes), `rollback()`, `saveTranslationRevision()` / `saveTranslationRevisions()`, `saveTranslation()`, and `backfillInitialRevisions()` all pass the same resolved `?int $author` into `writeRevision()`. No path constructs a revision row without going through the resolution.
2. **Resolution order** (per operation, computed once): `SaveContext` override when `actorOverridden` → `AccountContextInterface::current()?->id()` → `null`. See data-model.md.
3. **Null is null**: absence of an acting context writes SQL NULL into `revision_author`. The value `0` is written if and only if the resolved actor IS the anonymous account (id 0) — never as a fallback or default.
4. **Revert authorship**: a revision created by `rollback()` carries the actor who performed the revert. The reverted-to (target) revision row is never modified.
5. **Override semantics**: `SaveContext::withActorUid($uid)` wins over the ambient context, including `withActorUid(null)` which forces a null author inside an authenticated request (e.g. a system-attributed maintenance write). A context with `actorOverridden === false` (the default) defers to the ambient holder.
6. **In-place revision updates** (`SaveContext::withoutNewRevision()` → `updateRevision()`) do NOT touch `revision_author` — it is immutable revision metadata, like `revision_created`/`revision_log` (existing exclusion list in `RevisionableStorageDriver::updateRevision()` extends to cover it).

## Readback

7. `EntityRepository::loadRevision()` (and the translation-revision loads) hydrate `RevisionMetadata(revisionCreatedAt, revisionAuthor, revisionLog)` from the row and call `setRevisionMetadata()` on entities implementing `RevisionableEntityInterface`. `listRevisions()` inherits this via `loadRevision()`.
8. `revisionMetadata()->revisionAuthor` round-trips exactly what was recorded: `int` for an account (including `0` for anonymous), `null` for no actor.
9. Rows whose `revision_author` is SQL NULL — including ALL rows created before this mission — hydrate `revisionAuthor: null`. No error, no sentinel.
10. Entities not implementing `RevisionableEntityInterface` are unaffected (hydration is `instanceof`-guarded, matching the existing `setRevisionId` pattern).

## Additive schema

11. **New tables**: `buildRevisionTableSpec()` and `buildTranslationRevisionTableSpec()` include `revision_author` (int, `not null => false`, no default, no FK constraint — soft FK so history survives user deletion).
12. **Existing tables**: `ensureRevisionTable()` / `ensureTranslationRevisionTable()` no longer pure-early-return — when the table exists they additively add `revision_author` if missing (`fieldExists` → `addField`). Idempotent; no other column is touched; no row is rewritten (C-001). Covers both production callsites (kernel repository factory, `EntitySchemaSync`).
13. **Pre-existing rows** read back NULL author (scenario 8 / SC-004). A migration-shaped test creates a pre-mission-shaped revision table with rows, runs the sync, and asserts: column present, old rows null, new revisions authored.
14. **Column is physical**: a pinning test asserts `revision_author` is a real column after sync — guarding against `foldData()` silently folding it into `_data` if the sync arm regressed.
15. **Dialect convergence (FR-009)**: `revision_author` name + nullable-int + soft-FK semantics are the single authoritative author definition, identical to the dormant `RevisionTableBuilder` dialect's column; the dormant emission path itself is documented as superseded in `docs/specs/revision-system-unified.md`, and the `RevisionMetadata` docblock references the live table/columns.

## Performance (NFR-001)

16. Author resolution performs no I/O (in-memory holder + optional `id()` call). The revision INSERT grows by one column. Median revisionable-save overhead ≤5%, pinned by the integration perf smoke (saves with vs without an ambient account).

## Verification

- Unit: schema-spec tests (column present in both specs; additive arm adds it; idempotency), `SaveContextTest` (override states), driver tests (author written on both single-axis and per-langcode paths; `updateRevision` immutability).
- Integration: `packages/entity-storage/tests/Integration/RevisionAuthor/RevisionAuthorTest.php` (record/readback matrix incl. anonymous-0 vs null, revert authorship, override precedence, pre-existing-table migration shape).
- Kernel-booted: `tests/Integration/Provenance/KernelRevisionAuthorTest.php` — a save through a kernel-built repository with an account in context reads the author back via `revisionMetadata()` (SC-001) + NFR-001 smoke.
