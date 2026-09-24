# FW-AIV-PERSIST-01 — ai-vector persistence under schema authority

- Forge mirror: `waaseyaa/framework#3138` (parent #3137, program #3118)
- Findings: AIV-PERSIST-001, AIV-PERSIST-002 in `docs/audits/packages/ai-vector.md`
- Base: `0f3592d65c5bfa87c6bb5c60a1d4f39c7a9aa3f4`
- Branch: `claude/ai-vector-persistence-3138`
- Related: #3110 (general drift adoption), #3139 (one composition owner), #3140
  (alternative backends and separate projection storage),
  `jonesrussell/fetder-waaseyaa#160` (the production release this blocked)
- Authority: repository source, tests and PR. No release, publication or
  deployment. Landing needs the maintainer's approval.

## Problem

`SqliteEmbeddingStorage` created the `embeddings` table lazily on the
application's authoritative database, outside `SchemaMutationCoordinator`. That
happened on an entity delete, on saving a node that isn't publicly served, and
on a semantic search. The next coordinated transition then refused with
`[S1-DB109]`. This is what blocked FETDER's production release on 2026-09-23;
it was unblocked by a guarded, one-time manifest re-record.

The storage also used raw `\PDO`, changed the shared connection's error mode,
and used SQLite-only `INSERT OR REPLACE`.

## Decision (maintainer, 2026-09-23)

- **Storage model:** `embeddings` lives in the application's main database. It
  is owned by an ai-vector package migration and accessed only through
  `DatabaseInterface`. Alternative backends and separate projection storage
  belong to #3140.
- **Already-drifted databases:** #3138 documents and proves a bounded recovery
  for the case where `embeddings` is the only drift, using the S1 spec's
  existing governed re-adoption step. The reusable adoption command or API
  belongs to #3110. #3138 builds no second adoption mechanism.

## Migration contract

`packages/ai-vector/migrations/2026_09_24_000001_embeddings_schema.php`, run
through the coordinator like every package migration:

| Live state | Result |
| --- | --- |
| No `embeddings` table | Create it: `entity_type`, `entity_id`, `vector`, `updated_at`, all NOT NULL, primary key `(entity_type, entity_id)`. |
| Table with the expected schema (including one the old runtime code created) | Adopt in place. No DDL, every row kept. |
| Table with any other schema | Fail closed: the migration throws `[AIV-DB001]` with the exact differences and recovery instructions, and the coordinator rolls the whole transition back. |

"Expected schema" means exactly those four columns, text affinity for the
first three, integer affinity for `updated_at`, all NOT NULL, and exactly that
primary key in that order. Extra columns, a different key, or nullable columns
are incompatible. The migration never drops, recreates, renames or empties the
table.

## Storage contract

`DatabaseEmbeddingStorage` replaces `SqliteEmbeddingStorage`. The old class
was not a declared public symbol; `EmbeddingStorageInterface` is, and it is
unchanged.

- It takes `DatabaseInterface`, issues no DDL, and doesn't touch connection
  attributes.
- `store()` deletes and inserts inside one transaction, which is portable
  across drivers.
- `findSimilar()` and `delete()` use the query builder.
- **Migration not yet applied:** `store()` and `delete()` log a warning and do
  nothing, and `findSimilar()` logs and returns no matches. The check is a
  read-only table lookup. Nothing on the save, delete or search path creates
  schema.
- `SearchController`'s ranking and response metadata are out of scope and
  unchanged.

## Recovery for already-drifted databases

This applies to a database where the runtime code created `embeddings` after
the manifest was recorded, so `migrate --verify` reports `schema_drift`. The
procedure is in `docs/specs/ai-integration.md` ("Adopting a runtime-created
embeddings table"). In outline:

1. Back up the database.
2. On a copy, drop `embeddings` and run `migrate --verify`. `STATUS: OK` proves
   `embeddings` is the only drift and the ledger matches. Any other result
   stops the procedure.
3. On the live database, check the table's shape against the expected schema.
   Stop if it differs.
4. Apply the S1 spec's governed re-adoption: clear the recorded fingerprints.
5. Run `migrate`. The ai-vector migration adopts the table in place and the
   transition records a fresh manifest. Confirm `migrate --verify` reports
   `STATUS: OK` and the row count is unchanged.

## Acceptance

Tracks #3138:

- The committed drift probe's delete and draft-node-save cases no longer
  create schema or drift. The probe's expectations are updated in this change.
- A semantic search with a provider configured leaves the manifest fingerprint
  unchanged.
- Strict schema verification and the next coordinated transition stay green on
  a real SQLite file after save, delete and search.
- Migration: create, adopt in place with rows preserved, and fail closed on an
  incompatible shape, each with a regression test.
- The recovery procedure is proven on a drifted SQLite database, and FETDER is
  qualified against the candidate.
- Exact-head hosted CI and independent review.

**Open acceptance item:** #3138 asks for store, search and delete on "at least
one server database". CI has no MySQL or PostgreSQL service, the local host
has no server database, and `SECURITY.md` lists both as unsupported. The
storage uses only the portable query builder. The server-database run is left
for the maintainer to decide.
