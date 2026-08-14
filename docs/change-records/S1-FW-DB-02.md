# S1-FW-DB-02 — schema mutation authority

- Parent: `17bb866242c63885b6d6cc5373098d0ddb7f4e22`
- Parent tree: `2512f6abe4ca1f39378fc972e8202e90a8857c63`
- Contract: `docs/specs/s1-schema-authority.md`
- Findings: `F-DB-006` through `F-DB-010`
- Authority: local source changes only; forge-neutral

## Sequence

1. Commit this contract and stable change record.
2. Commit executable retained-red failures without rewriting history.
3. Implement the coordinator, unique ledger, strict verification, and
   read-only/runtime refusal boundary in reviewable slices.
4. Mechanically classify the complete DDL surface.
5. Prove exact installed form and reconcile independent review.

## External interlock

No issue, pull request, hosted check, forge API, merge, split, publication,
release, deployment, production operation, backup, restore, or recovery action
is authorized by this record.

## Entity schema-authority slice

The entity storage transition is explicit and read-only at runtime:

- `EntitySchemaSyncRunner` and direct schema-handler calls enter the singular
  re-entrant `SchemaMutationCoordinator`; nested definition, translation, and
  domain transitions share one transaction and one authority generation.
- ordinary repository resolution validates required tables and declared unique
  keys without creating or repairing them. Missing schema fails with
  `S1-DB106` and directs the operator to `waaseyaa schema:sync`.
- entity classes can declare storage unique keys and versioned domain schema
  transitions as metadata. The thread-participant legacy merge and uniqueness
  transition now executes only inside the explicit coordinated sync.
- console discovery has a restricted schema-sync boot mode. `db:init` and
  content-model registration invoke that mode explicitly; HTTP/runtime boot
  does not acquire schema mutation authority.
- fixture projects use an explicit schema-migration helper rather than relying
  on repository construction to materialize schema.

Verification on PHP 8.5.9:

- schema roster: 1,143 occurrences across 357 files, with no
  `authoritative-bypass-remediation-required` occurrence;
- SQLite construction roster: 814 exact occurrences;
- PHPStan: 2,056 files, zero errors;
- architecture: 144 tests, 18,891 assertions;
- unit: 10,896 tests, 228,723 assertions (one unrelated environment skip);
- integration: 1,857 tests, 8,220 assertions;
- Composer validation, formatting, package-layer, policy, and secret gates pass.

This evidence closes the entity runtime-schema slice only. The encompassing
`S1-FW-DB-02` exit gate remains open until every remaining authoritative DDL
surface is migrated and installed-form and independent-review evidence pass.

## Strict verification slice

`migrate --verify` now fails closed across the complete authority tuple:

- every ledger row binds its migration id, declaring package, exact source
  checksum, and independently computed plan hash;
- new legacy-procedural rows use an exact executable-class checksum and a
  domain-separated procedural-plan hash, while historical null rows remain
  honest `unknown` failures rather than receiving invented evidence;
- every successful coordinator transition records canonical live-schema and
  ledger fingerprints before commit, while migration runs also bind the exact
  loaded source catalogue;
- verification performs only reads, compares the current schema, ledger, and
  source catalogue with that manifest, and reports missing authority, schema
  drift, ledger drift, catalogue drift, package substitution, plan
  substitution, orphan rows, and unknown rows as failures;
- two concurrent processes applying the same plan produce one ledger result,
  and a process killed after DDL but before commit leaves no partial schema,
  ledger, or manifest state.

Verification on PHP 8.5.9:

- schema roster: 1,148 exact occurrences; SQLite construction roster: 826;
- PHPStan: 2,060 files, zero errors;
- architecture: 144 tests, 18,892 assertions;
- unit: 10,901 tests, 228,740 assertions (one unrelated environment skip);
- integration: 1,859 tests, 8,236 assertions;
- focused migration/strict-verification matrix: 94 tests, 267 assertions.

The following reconciliation closes the installed-form and independent-review
requirements for this work package.

## Installed-form and independent-review reconciliation

The exact `database-legacy`, `foundation`, and `cli` package trees are archived
twice with canonical metadata and byte-compared, installed without path
repositories, and executed with lock-bound Doctrine/PSR dependency bytes. The
isolated consumer applies a real V2 migration, verifies the complete authority
tuple, injects an unauthorized `ALTER TABLE`, and proves `schema_drift` refusal.
The proof uses no monorepo autoloader, network, forge, or hosted artifact.

Claude Code independently challenged the complete parent-to-candidate diff in
read-only plan mode. It validated the full Unit and Integration suites,
PHPStan, both occurrence rosters, behavioral installed-form proof,
concurrency/crash tests, retained-red history, change-record hygiene, and
forge-neutrality. It found two evidence defects at reviewed commit
`4fc4d124b`:

1. the new `usleep()` contention barrier was not classified by the repository
   test-quality inventory, leaving Architecture red;
2. the installed-artifact script refused dirty package bytes but did not yet
   refuse dirty lock, dependency-authority, or probe-script bytes.

The first was corrected by `aa42b8f25`; the second was corrected there and its
semantic structural assertions were sealed in `2d6510f71`. Reconciliation
then proved:

- Architecture: 146 tests, 18,926 assertions, green;
- deliberate dirty probe input: artifact proof refused before construction;
- exact restored input: installed-artifact schema authority proof passed;
- schema roster: 1,150 occurrences across 359 files, no authoritative bypass;
- SQLite construction roster: 828 occurrences;
- no Claude, test, browser, or server process was left running.

Independent verdict after corrections: `sound; no blockers`.

## DB-02 disposition

`S1-FW-DB-02` is implementation-complete and independently reconciled on this
local review branch. The exit contract is satisfied by executable evidence:
one database-native authority, whole-plan atomicity, unique migration identity,
zero-DDL runtime/inspection paths, truthful forward-only rollback, strict
source/package/plan/ledger/live-schema verification, complete fail-closed DDL
classification, contention and process-death proof, and deterministic offline
installed-form reproduction.

This disposition authorizes only progression to the next dependency-ordered
local remediation work package. It does not authorize integration to shared
main, split-package fan-out, publication, release, deployment, production
mutation, backup, restore, or recovery execution.
