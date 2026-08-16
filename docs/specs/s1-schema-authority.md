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
once as:

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
