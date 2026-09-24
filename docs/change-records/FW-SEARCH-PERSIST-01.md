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
| Any other definition (names compare case-insensitively, as SQLite does; triggers have their own namespace and are ignored), a `search_index_retired_porter` leftover, or an orphaned FTS5 shadow table | `[SEARCH-DB001]` naming each difference; the coordinator rolls back and nothing changes. |

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

Dedicated `search.database` file:

- Only `removeAll()` (`search:reindex`) provisions it, in one transaction with
  the row deletes, so an interrupted rebuild leaves the file as it was. Its
  `[SEARCH-DB001]` refusal says to move the file aside and reindex.
- A `search.database` that resolves to the application database file (the
  same canonical path, or the same device and inode, as with a hard link) is
  not a dedicated file. The provider shares the application connection, so
  `search:reindex` doesn't provision the authoritative database. One gap
  remains: a hard link on a filesystem that reports no inode numbers is still
  treated as dedicated.

## Evidence

The code under qualification is `896b234bf` on base `6359a4428`. Later commits
change only this record.

**Failing before, passing after.** The drift regression
`SearchServingPathSchemaAuthorityTest` was committed red in `64553c0d9`, before
the fix. That commit's first test called a helper added with the fix, so the
discriminator was reconstructed without touching the worktree: a scratch
PHPUnit bootstrap loads the candidate's autoloader, declares the base
(`6359a4428`) `Fts5SearchIndexer` before the candidate's copy can load, and
runs the candidate's test.

- Pre-fix (WSL, PHP 8.5.9): `withoutTheMigrationTheServingPathsCreateNothing`
  fails "strict verification: no schema drift", with the recorded `4b19b1af…`
  against the live `e987c3e9…` (2 tests, 1 failure).
- Candidate: 2 tests, 14 assertions, OK.

**Tests.**

- `SearchServingPathSchemaAuthorityTest`: with the migration applied,
  lifecycle save and delete, `removeAll()` plus `reindexBatch()`, search and
  the catalogue leave the manifest valid, and the next coordinated transition
  succeeds. Without the migration they create nothing.
- `SearchProjectionSchemaMigrationTest`:
  - create, with stored SQL identical to the runtime-created projection;
  - the package declaration and a single `Migrator` install;
  - in-place adoption with rows, and partial completion;
  - the Porter rebuild, with rows kept and re-tokenized;
  - `[S1-DB109]` for drift outside the manifest, and the documented recovery
    on a drifted database;
  - nine refusal shapes, and a trigger that shares an owned name;
  - rollback of a created projection, and of a Porter rebuild, when a later
    step of the same transition fails.
- `Fts5SearchIndexerSchemaBoundaryTest`:
  - no DDL on construction or on serving writes, and the warning;
  - `[SEARCH-DB002]`, and a later migration is picked up;
  - a dedicated file is provisioned only by `removeAll()`;
  - a dedicated-file refusal gives file recovery, and a failed dedicated
    rebuild rolls back.
- `SearchServiceProviderSchemaAuthorityTest`, through the provider:
  - no `search.database`, one naming the application file (two spellings), and
    a hard link to it all refuse with `[SEARCH-DB002]` and create nothing;
  - a dedicated file is provisioned and the application database is untouched.
- `SchemaDeclarationBoundaryTest`: only `Fts5SearchSchema` declares DDL.

**Independent review.** A separate reviewer read the immutable diff at
`5bdd548a8`, then each repair delta (`7a6bc2fbd`, `896b234bf`) and this
record. No pass found a blocker.

| Finding | Disposition |
| --- | --- |
| A `search.database` naming the application file let `search:reindex` run DDL outside the coordinator | Fixed in `7a6bc2fbd`; hard links fixed in `896b234bf` |
| No test of the provider's dedicated-file wiring | Fixed: `SearchServiceProviderSchemaAuthorityTest` |
| Dedicated-file provisioning was not atomic, and its refusal pointed at the migration | Fixed: one transaction, and file recovery text |
| A case-variant owned name gave a raw DBAL error | Fixed: `[SEARCH-DB001]` |
| No test of rollback after the migration's DDL ran | Fixed: two rollback tests |
| A trigger with an owned name was a false `[SEARCH-DB001]` | Fixed in `896b234bf` |
| The Porter rebuild's `ALTER TABLE … RENAME` fails when an unrelated view is stale | Residual: it fails closed with a raw SQLite error and rolls back |
| A Porter rebuild renumbers rowids | Residual: nothing reads rowid; NULL `document_id` rows survive |
| `Fts5SearchProvider` throws if `search_index` exists without `search_metadata` | Residual and pre-existing: neither the migration nor the now-atomic dedicated rebuild can produce that state |
| On Linux, a `../` spelling through a missing directory is not recognized as the application file | Residual: it fails closed at the existing directory creation, before any connection opens |

I checked the new tests with the same scratch-bootstrap technique against
`5bdd548a8` and `7a6bc2fbd`. Each behaviour fix has a test that fails on the
code before its repair: the same-file and hard-link cases, dedicated-file
recovery, the dedicated-rebuild rollback, the case-variant refusal and the
trigger case. Two kinds of test pass on the older code too. The provider test's
no-`search.database` and dedicated-file cases, and the two rollback tests,
cover behaviour that was already correct. They guard it against regression,
for example a forced dedicated flag.

**FETDER qualification (local, native Windows host, PHP 8.5.5, candidate
`896b234bf`).**

- **Setup:**
  - Scratch copies of the FETDER app at `874e4c8` (`git archive`), with
    `.env.example` (fake provider, no secrets).
  - The *old* copy uses FETDER's installed `0.1.0-alpha.301` packages. The
    *new* copy resolves every `waaseyaa/*` package from a `git archive` of
    `896b234bf` through Composer path repositories (copies, not links). Every
    installed package's `src/` is byte-identical to the archive.
  - Each case starts from a fresh copy of FETDER's local database
    (`var/fetder-local.sqlite`, SHA-256 `ef3932e2…`).
- **Sequence** (issue acceptance 7): `migrate`, the probe, `migrate --verify`,
  `install:init`, `migrate --verify`, the probe again, `migrate --verify`,
  `install:init`, `migrate --verify`.
- **The probe** boots the HTTP kernel, then:
  - saves and deletes a `fetder_room` through the kernel's entity repository,
    so the storage dispatches `POST_SAVE` and `POST_DELETE` through the kernel
    dispatcher;
  - dispatches `POST_DELETE` for a search-indexable document (the #3138
    trigger);
  - indexes two documents through the kernel-resolved indexer and deletes one
    through the lifecycle;
  - searches and lists the catalogue through the kernel-resolved provider and
    catalogue;
  - reports the recorded and live schema fingerprints and the row counts.
- **The real entity save** needs an activated configuration generation, which
  FETDER's local database gets from `install:init`. It therefore fails in the
  first probe, as FETDER's own runtime would, and runs in the second.
- **FETDER's own data** is unchanged: its checkout is still `874e4c8` and
  clean, and its database is still `ef3932e2…`.

| Case | Result |
| --- | --- |
| Control: old copy, same sequence | The first probe created the projection and `embeddings` (290 → 303 objects), and the live fingerprint moved from `8c4a54bd…` to `300229b5…`. Every `migrate --verify` reported `schema_drift`, and both `install:init` runs refused with `[S1-DB109]`. |
| A: clean database, new copy | `migrate` applied the ai-vector and search migrations (303 objects). Both probes left the live fingerprint unchanged and equal to the manifest, and the second saved and deleted room 1. Each projection table held 1 row after the probes. All four `migrate --verify` runs reported STATUS OK (42 matched), and both `install:init` runs succeeded. The sequence took 19.2 s. |
| B: FETDER production's likely state. On the old copy, `embeddings` was re-recorded (the 2026-09-23 unblock), then a later probe drifted only the search projection (`c8874276…` → `f03162a9…`). | `migrate` refused with `[S1-DB109]`. The documented recovery followed: backup integrity `ok`; the proof copy showed `source_catalog_mismatch` with equal `schema=` and `ledger=`; re-adoption; then the sequence above. `migrate` adopted the projection with no DDL: the definitions hash was `7405ac99…` before and after, and the live fingerprint stayed at the runtime-created `f03162a9…`. Each projection table kept 1 row throughout, the second probe saved and deleted a room, all four verifies reported STATUS OK, and both `install:init` runs succeeded. The sequence took 12.4 s. |

Setting up each copy took about 200 s (Composer). Earlier evidence on
`6ae88b768` also covered adopting a projection that was inside the manifest,
and an HTTP smoke of five routes; that candidate predates the review repairs.

**Local evidence on the candidate.**

- **WSL Ubuntu, PHP 8.5.9, read-only on the Windows worktree** (local Linux
  evidence):
  - `packages/search/tests` pass on `896b234bf`: 173 tests, 600 assertions.
  - The callers pass on `7a6bc2fbd`: the api content-search integration,
    `MakeSearchProjectionCustodyTest`, `SearchReindexHandlerTest`,
    `SearchProjectionReindexTest`, `tests/Integration/Search` and
    `packages/ai-vector/tests`. The second repair touches only
    `Fts5SearchSchema`'s lookup and the provider's same-file check, which the
    search package covers.
  - These Architecture contracts pass: `SearchIndexTrustBoundaryTest` and
    `TestQualityInventoryTest` (named files with a scoped, read-only Git
    environment), `RecursiveRemoverContractTest`,
    `SubprocessHarnessContractTest`, `S1RosterSchemaV2Test`,
    `S1SupportContractTest`, `S1UpgradeCompatibilityContractTest`,
    `CoversNothingCompanionDiagnosticTest`,
    `LockedPackageDiscoveryMetadataTest`,
    `SplitPackageTestDependencyBoundaryTest` and `CheckPackageLayersGateTest`.
- **Host limits:** `S1SchemaAuthorityContractTest` and
  `S1SqliteTopologyContractTest` can't enumerate a Windows linked worktree from
  WSL, and natively they stop at `is_executable()` on an extensionless
  checker. The checkers themselves (`check-s1-schema-authority` and
  `check-s1-sqlite-contract`) pass natively. Hosted CI owns those tests.
- **Native:** `php bin/check-pr-preflight` (45 gates, 0 failed) and PHPStan on
  `packages/search` pass.

## Out of scope

- #2763: the lifecycle subscriber still logs to `NullLogger`.
- #3110: general adoption of drifted databases.
- Checking FETDER production's tables against its manifest is a separately
  authorized deploy step.
