# S1 schema mutation authority

Status: implementation contract for `S1-FW-DB-02`.

Parent candidate: `S1-FW-DB-01` commit
`17bb866242c63885b6d6cc5373098d0ddb7f4e22`, tree
`2512f6abe4ca1f39378fc972e8202e90a8857c63`.

This is a forge-neutral local change record. Git commits, trees, executable
tests, exact installed artifacts, and retained evidence are the authority.
Issues, pull requests, and hosted checks may mirror the work but are not
required to reproduce or verify it.

## Problem

S1 currently has several incompatible schema owners:

- `Migrator` commits one node at a time, so a failed later migration retains
  earlier schema and ledger changes from the same requested run.
- the migration ledger permits duplicate migration identities;
- ledger bootstrap and upgrade bypass the migrator and can run from ordinary
  command composition;
- rollback removes a ledger row even when the source migration is unavailable
  and no reverse effect ran;
- verification can accept unknown legacy rows without proving live schema;
- entity repositories, schema synchronization, projections, and ETL setup own
  additional DDL paths that are not mechanically classified.

A matching source file and ledger row therefore do not prove that the database
has one known schema or that a requested transition was atomic.

## Authority

One `SchemaMutationCoordinator` owns every production DDL mutation against the
authoritative SQLite database. It uses the DBAL connection selected by
`S1-FW-DB-01` and acquires SQLite writer ownership as its first transactional
effect through a durable coordinator authority row. It does not issue raw
`BEGIN IMMEDIATE` outside DBAL bookkeeping.

The coordinator transaction contains ledger compatibility checks, plan
discovery and recheck, every schema/data node declared part of the schema plan,
ledger writes, and the logical live-schema manifest. The manifest binds the
authority generation, canonical SQLite schema objects, canonical ledger rows,
and the exact loaded migration-catalogue fingerprint. It is written after the
transition and before commit, so the whole ordered plan and its proof commit or
roll back together. A future resumable protocol must be a separately versioned
contract.

Migration identity is globally unique. Before adding the database uniqueness
constraint, legacy duplicates fail closed for explicit operator reconciliation;
the implementation never picks a winner. Every already-applied skip rechecks
package identity, source checksum, compiled plan, expected pre-state, and
verified post-state.

## Prior-state validation and replay rechecks

Every coordinated transition validates the recorded manifest before it may
inspect or mutate schema state (#2730). The order inside the coordinator
transaction is fixed: writer acquisition (`acquireSchemaAuthority()`, whose
first statement is unchanged from #2446), prior-state validation
(`MigrationRepository::assertSchemaAuthorityPreState()`), ledger
install/upgrade, the transition, the manifest. The validation compares the live
logical schema fingerprint and the canonical ledger fingerprint — the same
functions strict verification reads, so refusal and `migrate --verify` always
agree — with the recorded manifest:

- No manifest, or a manifest without fingerprints, is a fresh install or the
  #2452 adoption path: the transition proceeds and records the first proof.
- A recorded manifest that no longer describes the live schema or ledger
  refuses with `[S1-DB109]`. The refusal is thrown inside the coordinator
  transaction, so the authority generation, schema, data, ledger, catalogue and
  manifest are left exactly as found, and verification keeps reporting the
  drift. Pending work after drift is refused the same way; a catalogue
  extension against an unchanged database applies normally.
- Because the ledger upgrade runs after the validation, a manifest recorded
  before a ledger column existed (the pre-#2701 `apply_mode` window) is not
  drift: the unchanged schema passes, the upgrade runs inside the boundary, and
  the new manifest is recorded. `db:init` therefore no longer installs or
  upgrades the ledger outside the coordinator.

Replay rechecks apply to every already-applied node, legacy and V2 alike, from
the ledger row rather than `hasRun()` alone. Package identity and the stored
compiled-plan hash (`diff_hash`: the compiled SQLite plan for V2, the
domain-separated procedural hash for legacy) are refused with `[S1-DB112]`; a
source-checksum mismatch keeps the `CHECKSUM_MISMATCH` refusal. All three throw
in production and log a warning in development. A null stored hash is a
pre-WP09 row: nothing is invented, and strict verification continues to report
it as unverifiable. The loaded catalogue fingerprint is recorded only after
every replay check has passed, so a refused replay cannot rewrite the catalogue
authority.

Runtime tables that a serving path declares lazily on the authoritative
database are part of the logical schema fingerprint. The privileged-read ledger
(`StrictLedgerSchema`) therefore creates its table through the coordinator on
first use, so a kernel boot cannot leave the manifest stale. A projection that
still declares its tables outside the coordinator on a shared authoritative
file (FTS5 search without a dedicated `search.database`, ai-vector embeddings)
makes strict verification report `schema_drift` and makes the next coordinated
transition refuse with `[S1-DB109]` until the projection is migration-owned or
moved to its own file.

### Governed re-adoption

There is deliberately no automatic re-baseline. When an operator has verified
that the drift `migrate --verify` reports is benign — for example a projection
table created before this contract landed — the explicit re-adoption step is to
clear the recorded fingerprints so the next transition records a fresh manifest
from the live database:

```sql
UPDATE waaseyaa_schema_authority
   SET schema_fingerprint = NULL, ledger_fingerprint = NULL
 WHERE authority_id = 1;
```

then run `migrate` (or `db:init`). The transition acquires authority, advances
the generation and records the new proof. The step is an operator decision
visible in the generation counter; the framework never takes it on its own.

## Read-only boundary

Boot, status, dry-run, verify, health, and ordinary repository construction
perform zero DDL. They may inspect a present ledger and schema, but absence,
legacy ambiguity, or drift produces a stable diagnostic rather than self-heal.
Ledger bootstrap/upgrade runs only through the coordinator.

Entity schema materialization is a deterministic versioned plan. Its canonical
form must express partial indexes, foreign keys, table options, triggers,
views, and declared virtual tables where relevant. `db:init`, migration apply,
and explicit schema synchronization use the coordinator's one connection;
serving paths never create, alter, heal, or drop schema.

## Rollback and verification

Schema evolution is forward-only by default. Rollback refuses without changing
schema or ledger unless the exact applied migration provides a supported,
versioned reverse plan with checked preconditions and a verified post-state.
Missing source is failure, never success.

Strict verification rejects duplicates, unknown or null-hash legacy rows,
orphan rows, missing sources, package/source/plan mismatches, stale expected
pre-state, and live-schema drift. The logical schema fingerprint is based on
canonical SQLite introspection and excludes SQLite internals, root pages,
journal state, auto-index names, and raw file bytes.

New legacy-procedural applies record an exact hash of the executable migration
class body plus a domain-separated procedural-plan hash. This does not invent
historical evidence: rows that predate those hashes remain `unknown` and make
strict verification fail. V2 rows continue to bind canonical intent and the
compiled SQLite plan independently. The catalogue identity is content based;
it does not require a forge, issue, branch, or hosted artifact.

## Boundary inventory

An executable repository-wide DDL roster classifies every occurrence exactly
once (entries bind semantic identity — path, pattern, class, normalized match
hash, occurrence index — never line numbers or file hashes; schema v2, see
[governed-gates.md](governed-gates.md) §6) as:

- coordinator-owned authoritative schema;
- read-only refusal;
- DB-01 rebuildable projection;
- separately bounded ETL/data workflow;
- test/tooling-only; or
- explicit reviewed exclusion.

New or reclassified candidates fail until reviewed. `migrate:defaults` row
work remains outside the schema transaction. ETL row processing remains
separate while its table DDL is coordinator-owned. Search FTS5 remains a
rebuildable projection and cannot lazily mutate the authoritative database.

## Retained-red sequence

The first regressions prove:

1. duplicate migration identities are currently accepted;
2. one failed node leaves a prior node committed;
3. rollback with missing source currently erases ledger evidence.

Later retained reds cover two-process contention, process termination around
every durable boundary, zero-DDL read paths, strict live-schema drift,
cross-package legacy-ID collision, entity-schema plan fidelity, one-connection
`db:init`, and complete DDL-roster classification.

## Exit gate

Two concurrent apply attempts produce one authoritative result; one requested
plan is all-or-nothing; read-only/runtime paths perform zero DDL; rollback
cannot erase unreversed state; every production authoritative DDL site uses the
coordinator; and verification binds exact source, package, plan, ledger,
framework identity, and logical live schema. Installed-form proof reproduces
the same contract without a live forge or monorepo path repository.

This package authorizes local framework source work only. It does not authorize
shared-main integration, package publication, release, deployment, production
mutation, backup, restore, or recovery execution.
