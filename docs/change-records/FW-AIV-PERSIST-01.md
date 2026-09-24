# FW-AIV-PERSIST-01 — ai-vector persistence under schema authority

- Forge mirror: `waaseyaa/framework#3138` (parent #3137, program #3118)
- Findings: AIV-PERSIST-001, AIV-PERSIST-002 in `docs/audits/packages/ai-vector.md`
- Base: `03ffdb530` (`origin/main`); designed at `0f3592d65`. The two commits between don't touch this change's files.
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
- `SearchController` is unchanged.

## Recovery for already-drifted databases

This applies to a database where the runtime code created `embeddings` after
the manifest was recorded, so `migrate --verify` reports `schema_drift`. The
procedure is in `docs/specs/ai-integration.md` ("Adopting a runtime-created
embeddings table"). In outline:

1. Back up the database.
2. On a copy, drop `embeddings` and run `migrate --verify`. The recorded and
   live `schema=` and `ledger=` values must be equal. The authority kind must
   be `match`, or `source_catalog_mismatch` when the new release has pending
   migrations. Anything else stops the procedure.
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
- Store, search and delete pass on SQLite through portable query-builder code.
  Server-database qualification belongs to #3140.
- Migration: create, adopt in place with rows preserved, and fail closed on an
  incompatible shape, each with a regression test.
- The recovery procedure is proven on a drifted SQLite database, and FETDER is
  qualified against the candidate.
- Exact-head hosted CI and independent review.

## Candidate evidence (native Windows host)

**Framework tests:**

- The `packages/ai-vector` tests pass:
  - migration create, adopt in place, Migrator run, drift refusal, the recovery procedure, and seven refused shapes;
  - storage behaviour with and without the migration;
  - serving paths on a real SQLite file leave the manifest valid and let the next transition succeed;
  - the source boundary (no raw PDO or DDL in `src/`).
- The committed drift probe reports all five cases (the control, delete, draft-node save, non-node save and semantic search) as no table, no drift, next transition succeeded.
- The affected foundation, CLI, ai-tools and Phase 14/15/24 integration tests pass, apart from two `HttpKernelTest` Windows teardown errors (SQLite WAL file locks). Those also occur on unmodified `main`.

**FETDER qualification (local):**

- **Method:**
  - a temporary copy of the FETDER app, with this change's `src/` diff applied to its installed `alpha.301` packages (the touched files are identical between `v0.1.0-alpha.301` and the base);
  - the committed `.env.example` (fake provider, no worker);
  - copies of FETDER's local database;
  - FETDER's deploy sequence: `schema:sync`, `install:init`, `migrate --verify`.
- **FETDER's own data:** its checkout and database were unchanged (same hash before and after).

| Starting state | Result |
| --- | --- |
| Clean | `install:init` applied the migration and created the table. `migrate --verify` STATUS OK (40 matched). |
| Runtime table created by `alpha.301`, manifest re-recorded (FETDER production's state) | Adopted in place; 2 rows before and after; STATUS OK. |
| Runtime table created by `alpha.301`, not re-recorded (drifted) | `install:init` refused. The recovery procedure then worked: backup integrity `ok`; on the copy, `source_catalog_mismatch` with equal `schema=` and `ledger=`; `table_info` matched. After re-adoption, `install:init` adopted the table; 2 rows before and after; STATUS OK. |

- **Boot and smoke:** `/health`, `/`, `/create`, `/signup` and `/discover` all returned 200, and the schema stayed verified afterwards.
- **Out of scope, found during qualification:** dispatching an entity delete through FETDER's booted kernel drifted the schema. The objects created were the search package's FTS5 projection (`search_index*`, `search_metadata`); `embeddings` was untouched. It's the other drift source the S1 spec names, now tracked in #3146 (related: #2763, #3110).

**Host limits:** the full Architecture suite aborts on this host. Its targeted tests fail identically on unmodified `main`. Dead-code reports three findings in `config` and `scheduler` that also occur on unmodified `main`. Hosted Linux CI owns these.

**Server databases (moved to #3140, maintainer decision 2026-09-23):** #3138
qualifies SQLite and FETDER. The storage uses only the portable query builder
and the migration uses the portable schema builder. Qualifying on MySQL or
PostgreSQL, which `SECURITY.md` lists as unsupported and CI doesn't
provision, is an acceptance item of #3140.
