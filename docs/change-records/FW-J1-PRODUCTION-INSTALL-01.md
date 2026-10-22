# FW-J1-PRODUCTION-INSTALL-01: explicit production installation authority

Journey: FW-AUDIT-J1 / #3199. Base:
`17bf3bb78fa494fcd325a5753795d93952ede184`, including the merged #3231
homepage repair. Worktree: `codex/j1-prod-install-3199`.

## Preserved failure

The published alpha.307 Linux baseline completed creation, locked reinstall
and minimal generation, then `APP_ENV=production install:init` failed because
the configured SQLite database was absent. Restricted discovery reached the
ordinary production missing-file guard before installation could materialize
schema or configuration. A subsequent Apache/PHP-FPM request returned 500 for
the same cause. The four relevant installed files matched the base above.
The original evidence, failed applications and stopped runtime are immutable
inputs, retained separately from every repair probe.

## Installation authority, recorded before implementation

Only the explicit `install:init` console invocation selects installation boot.
Its authority is to connect to or create the configured application SQLite
database through Foundation's `DatabaseBootstrapper` and the existing
`DBALDatabase::createSqlite()` lifecycle, then run the existing restricted
discovery, coordinated migrations/entity-schema synchronization and canonical
configuration genesis. No manual `db:init`, empty-file creation, environment
downgrade or general missing-file-guard override is part of that path.

ConsoleKernel owns command intent. DatabaseBootstrapper owns path resolution,
directory preparation and connection creation. The existing S1 topology
authority still rejects unsupported paths and production memory databases
before filesystem/connection work. Configured-path precedence and CWD
independence remain unchanged. Existing database contents are never truncated
or replaced. The command's installation mode is private and scoped to its boot
invocation, restored even if boot throws.

Ordinary production ConsoleKernel/HttpKernel boot, `bootForSchemaSync()` and
other restricted commands retain missing-database refusal. This authority does
not activate ordinary runtime, invent field-access evidence, bypass schema or
configuration ownership, publish live capabilities without an active generation,
or create a second configuration generation on repeated installation.

Symfony Process and Filesystem are the existing maintained mechanisms for
bounded subprocess fixtures and directory preparation. Doctrine DBAL and the
existing S1 connection middleware own actual SQLite materialization and PRAGMA
verification; no parallel file/database creator is introduced.

## Bounded work and verification

Own ConsoleKernel's installation selection, DatabaseBootstrapper's explicit
installation seam, focused bootstrap/console regression tests, affected CLI and
S1/bootstrap specs, this record and one release fragment. Do not change the
homepage repair or repair a later journey failure in this slice.

First reproduce through the real console entrypoint on a fresh production
project with no database. Cover default/configured paths, CWD independence,
repeat initialization with preserved data/generation, ordinary CLI/HTTP
missing-file refusal, topology refusals, configuration-authority and field-access
refusals. Run applicable focused checks and normal governed preflight. One heavy
local operation at a time; dependencies belong to the new worktree or isolated
Linux export, never another checkout's vendor.

Seal the exact commit and qualify a different fresh Linux Apache/PHP-FPM app
using one exported Framework cohort with mirrored Composer installation. Retain
the published alpha.307 lock and source identities as a separate baseline.
Resume initialization and verification, preserving the next failing boundary
without further repair. WSL2/container evidence does not certify the pending S1
consumer test point. No merge, release, deployment or #3199 closure is authorized.

The maintainer explicitly requested an independent reviewer agent. Supply the
immutable candidate and evidence, require a verdict on installation authority,
persistence and retained safeguards, address findings and re-review changed code.
Self-review does not satisfy that gate.

## Evidence

The real console-entrypoint regression failed on the unmodified base for all
three initial path cases (default, configured relative and environment relative):
3 failures in 4 tests, with ordinary production refusal still passing. The
authoritative red log is `regression-red-confirmed.log`; an earlier fixture
draft used the wrong process CWD and is not that evidence.

After repair, the Linux focused selection passed **93 tests / 319 assertions**
on PHP 8.5.12. It covers five paths (including absolute paths and config-over-env
precedence), repeated installation preserving content and activation rows,
ordinary CLI/HTTP and other restricted-command missing-file refusals, invalid
production secrets, topology refusals and verified SQLite PRAGMAs. Existing
configuration-authority, active-configuration and field-access refusal suites
passed in the same selection. The test fixture deliberately added a foreign
table once; the schema authority correctly refused the repeat with S1-DB109.
That behavior is retained as an explicit negative control, not weakened to
make idempotency appear green.

Symfony Filesystem's locked `mkdir()` implementation was inspected: it handles
existing directories, recursive preparation and failure exceptions. Foundation
now declares the runtime dependency rather than relying on root development
dependencies. Composer regenerates first-party path references and promotes
the already-locked Filesystem package into the production graph; package
versions remain unchanged. Canonical generators refreshed the new SQLite
construction and schema-boundary occurrences; no allowlist suppression was added.

Exact-candidate installed qualification and independent review are pending at
this checkpoint. Local evidence is retained outside the repository under
`3199-evidence/20261022-prod-install-3199`. The preserved published failure is
`3199-evidence/20261021-s1-fpm-44b7b1d8/baseline.txt`.
