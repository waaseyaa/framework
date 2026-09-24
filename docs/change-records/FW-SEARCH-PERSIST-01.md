# FW-SEARCH-PERSIST-01 — search projection under schema authority

- Forge mirror: `waaseyaa/framework#3146`
- Base: `6359a4428` (`origin/main`)
- Branch: `claude/search-projection-schema-3146`
- Related: #3138 (the ai-vector equivalent, FW-AIV-PERSIST-01), #3110 (general
  drift adoption), #2763 (search failure observability),
  `jonesrussell/fetder-waaseyaa#160` (the production release the ai-vector
  drift blocked)
- Authority: repository source, tests and PR. No release, publication or
  deployment.

## Problem

`Fts5SearchIndexer::ensureSchema()` created the FTS5 projection lazily on
every write path, including `remove()`. With no `search.database` configured,
that is the application's authoritative database. The first indexed save or
entity delete therefore created `search_index` (and its FTS5 shadow tables),
`search_metadata` and three indexes outside `SchemaMutationCoordinator`. After
that, `migrate --verify` reported `schema_drift`, and the next coordinated
transition refused with `[S1-DB109]`.

This was observed on 2026-09-23 during #3138's FETDER qualification: one
`POST_DELETE` through the booted kernel drifted a freshly verified database.

## Decision (2026-09-24)

- **Migration-owned on the authoritative database**, following the #3138
  pattern. Requiring a dedicated `search.database` was rejected: FETDER and the
  default skeleton use the shared file; a second file forces a config change,
  a new file and a full reindex on every application; and a second file still
  needs its schema created somewhere.
- **A dedicated `search.database` stays optional.** It is a non-authoritative
  projection file with no manifest (`s1-sqlite-topology.md`). Only
  `search:reindex` provisions it, through `removeAll()`. The migration still
  runs on the authoritative database, so the recorded schema doesn't depend on
  configuration.
- **Already-drifted databases** get a documented, proven bounded recovery
  that uses the S1 spec's existing governed re-adoption. Reusable adoption
  tooling stays with #3110.

## Contract

`Fts5SearchSchema` (internal) owns the five objects. The package migration
`packages/search/migrations/2026_09_24_000001_search_projection_schema.php`
calls it under the coordinator.

| Live state | Result |
| --- | --- |
| Object absent | Created with the runtime code's exact DDL text, so a migrated and a runtime-created projection store identical SQL and share a logical fingerprint. |
| Expected definition (whitespace-insensitive) | Adopted in place, every row kept. |
| `search_index` with the retired `porter unicode61` tokenizer (before 0.1.0-alpha.263) | Renamed aside, recreated with the current tokenizer, rows copied across and re-tokenized, old table dropped, all inside the transition. |
| Any other definition, a `search_index_retired_porter` leftover, or an orphaned FTS5 shadow table | `[SEARCH-DB001]` naming each difference; the coordinator rolls back and nothing changes. |

Serving paths:

- `index()`, `remove()` and `reindexBatch()` check for both tables with a
  read-only `sqlite_master` query, caching only a positive answer. If absent,
  they log a warning (now through the kernel logger) and do nothing.
- `removeAll()` on the authoritative database refuses a missing projection
  with `[SEARCH-DB002]` and otherwise deletes rows only. The old
  `DROP TABLE search_index` tokenizer upgrade moved into the migration.
- `Fts5SearchProvider` and `Fts5SearchContentCatalogue` were already
  read-only and are unchanged.
- `Fts5SearchIndexer::ensureSchema()` is removed. The class isn't a declared
  public symbol; `SearchIndexerInterface` is unchanged.

## Evidence

- `SearchServingPathSchemaAuthorityTest`, written first and seen red on the
  base: the recorded fingerprint `4b19b1af…` against the live `e987c3e9…`
  after the serving paths ran. With the migration applied, lifecycle save and
  delete, `removeAll()` plus `reindexBatch()`, search and the catalogue leave
  the manifest valid, and the next coordinated transition succeeds. Without the
  migration they create nothing.
- `SearchProjectionSchemaMigrationTest`: create; stored SQL identical to the
  runtime-created projection; package declaration and single `Migrator`
  install; in-place adoption with rows; partial completion; Porter rebuild
  with rows and re-tokenization; `[S1-DB109]` for drift outside the manifest;
  the documented recovery on a drifted database; eight refusal shapes.
- `Fts5SearchIndexerSchemaBoundaryTest`: no DDL on construction or serving
  writes; the warning; `[SEARCH-DB002]`; a later migration is picked up; a
  dedicated file is provisioned only by `removeAll()`.

**FETDER qualification (local, native Windows host, candidate `6ae88b768`):**

- **Method:**
  - Two scratch copies of the FETDER app at `874e4c8`, with `.env.example`
    (fake provider). The *old* copy keeps its installed `0.1.0-alpha.301`
    packages. The *new* copy resolves every `waaseyaa/*` package from a
    `git archive` of the candidate through Composer path repositories.
  - Each case uses its own copy of FETDER's local database.
  - FETDER's deploy sequence: `schema:sync`, `install:init`,
    `migrate --verify`.
  - A probe boots the HTTP kernel, dispatches `POST_DELETE` for a
    search-indexable entity through the kernel dispatcher (the trigger
    observed in #3138), and indexes one document through the kernel-resolved
    indexer.
- **FETDER's own data:** its checkout and database are unchanged (database
  SHA-256 `ef3932e2…` before and after).

| Case | Result |
| --- | --- |
| Control: old copy | The probe created the projection (and, from alpha.301's ai-vector, `embeddings`). `migrate --verify` went from `authority:match` to `schema_drift`. |
| Clean database, new copy | `install:init` applied the search and ai-vector migrations; STATUS OK (42 matched). After the probe, the schema fingerprint was unchanged, a second `install:init` succeeded, and STATUS stayed OK. |
| Runtime projection inside the manifest (re-recorded on the old copy) | `install:init` adopted it in place: 1 row in each table before and after; STATUS OK. |
| FETDER production's likely state: `embeddings` re-recorded (the 2026-09-23 unblock), then only the search projection drifted from a later delete | `schema:sync` and `install:init` refused with `[S1-DB109]`. The documented recovery then worked: backup integrity `ok`; the proof copy showed `source_catalog_mismatch` with equal `schema=` and `ledger=`; after re-adoption, `migrate` adopted the projection with no DDL (live fingerprint equal to the runtime-created one); 1 row each before and after; the deploy sequence then reported STATUS OK. |

- **Boot and smoke on the recovered database:** after the probe, the schema
  stayed verified. `/health`, `/`, `/create`, `/signup` and `/discover` all
  returned 200.

**Local Linux evidence (WSL Ubuntu, PHP 8.5, read-only on the Windows worktree):**

- The search package, the api, CLI, ai-vector and Search/Generation
  integration tests pass (296 tests).
- These Architecture contracts pass: `SearchIndexTrustBoundaryTest`,
  `RecursiveRemoverContractTest`, `SubprocessHarnessContractTest`,
  `TestQualityInventoryTest`, `S1RosterSchemaV2Test`, `S1SupportContractTest`,
  `S1UpgradeCompatibilityContractTest`, `LockedPackageDiscoveryMetadataTest`,
  `SplitPackageTestDependencyBoundaryTest`, `CheckPackageLayersGateTest` and
  `CoversNothingCompanionDiagnosticTest`.
- `S1SchemaAuthorityContractTest` and `S1SqliteTopologyContractTest` need Git
  to enumerate the worktree, which WSL can't do for a Windows linked worktree.
  Their native gates (`check-s1-schema-authority`, `check-s1-sqlite-contract`)
  pass in preflight. Hosted CI owns the installed-artifact variants.
- Dead-code on this host reports seven findings in `config` and `scheduler`,
  outside this change. Hosted CI owns that gate.

## Out of scope

- #2763: the lifecycle subscriber still logs to `NullLogger`.
- #3110: general adoption of drifted databases.
- Checking FETDER production's tables against its manifest is a separately
  authorized deploy step.
