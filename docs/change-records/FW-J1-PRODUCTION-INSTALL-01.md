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

The initial immutable candidate `51adda46994497902432c306636f0e91ff2ee418`
received REQUEST CHANGES from the separate reviewer: selecting installation
boot from the raw command token created storage for help and invalid input.
Four new real-entrypoint Linux regressions confirmed that finding before its
repair (`installation-intent-red.log`, 4 tests / 8 assertions / 4 failures).
Creation authority belongs only to validated command execution. The repair
shares `MigrateServiceProvider::installInitCommand()` between pre-boot dispatch
and provider discovery, using Symfony's execution callback to defer boot until
help and argument/option validation finish. It adds no second input parser.
The installed Symfony Console `Command::run()` and `setCode()` implementations
were inspected to confirm this ordering and the supported callback mechanism.

Initial candidate qualification stopped at a faulty diagnostic class-name
probe before initialization; it is harness evidence, not a product failure.
Local evidence is retained outside the repository under
`3199-evidence/20261022-prod-install-3199`. The preserved published failure is
`3199-evidence/20261021-s1-fpm-44b7b1d8/baseline.txt`.

After the review repair, the expanded Linux selection passes **107 tests /
426 assertions**, including all four non-executing input cases and the migration
provider tests. Each such input asserts absence of the selected database,
WAL/SHM sidecars, selected parent directory and ordinary storage directory.

## Installed candidate qualification and review

The independent reviewer passed runtime candidate
`08fbc6199b39a7772303d183dfffe8da6f4ae60b` with no findings after re-review.
Independent native diagnostics also confirmed help before the command, version,
end-of-options handling, invalid input with an invalid production secret, and
clearing installation mode after callback failure. Ordinary restricted boot
still refused the absent database. `review-initial.txt` and `review-final.txt`
retain the finding, disposition, exact identities and limits.

The Linux focused export's 5,709 source/test/manifest files were byte-compared
against that candidate archive. Its 107-test evidence is carried forward only
for equivalent runtime, tests, dependency inputs and execution conditions;
later documentation-only custody updates do not claim a new suite execution.
The default governed preflight passed 43 executed gates, zero failures, zero
hosted-required and three not-applicable in 159 seconds. Mandatory commit hooks
passed. The optional full profile and hosted release qualification were not run.

Qualification exports the exact Framework commit and published skeleton commit
`db29e321305110480429a2bdc5b124ae04d94f72` with native Git. The disposable
template alone adds mirrored Composer path repositories (`symlink: false`) for
the complete first-party cohort. The checked-out alpha.307 label is only solver
metadata. All 67 installed `waaseyaa/*` members resolve from the single export;
representative loaded Foundation and CLI classes match its bytes. Normal
Composer scripts/plugins and application autoload rules remain enabled, and
each application has its own vendor. The candidate identity, archive digest,
overlay manifest, package map and exact lock are retained in the qualification
receipt, distinct from the preserved published alpha.307 lock.

The candidate app installed 115 production and 31 development packages, then
passed minimal site preview/generation and production `install:init` with no
database present. SQLite creation, migrations, entity schema and canonical
configuration genesis completed with sequence 1. Repeated initialization
preserved a content witness and every configuration-activation row.
`composer site-verify` passed strict site-doctor and its 6 tests / 20 assertions.
A separate fresh install from the same lock with `--no-dev` proved PHPUnit
absent, Symfony Filesystem present and production initialization successful.

The same frozen Linux image supplies Apache 2.4.58, PHP CLI/FPM 8.5.12 and
SQLite 3.45.1. The HTTP runtime witness confirms `fpm-fcgi`, production mode,
debug false and development fallback false. It is an unpublished, exact-
candidate installed qualification on WSL2 Docker with Linux filesystem
storage. It is not a new published alpha.307 result, an S1 consumer
certification, release qualification or deployment. Final immutable-head
receipts and independent custody review live alongside the evidence above.

## Next journey boundary and retained work

After successful initialization and site verification, the cold Apache/FPM
`GET /` returns 500 because field-read activation requires
`.waaseyaa/field-access-preflight.json`. The installed app has no such artifact;
installation intentionally did not fabricate one. The public response is a
generic boot error (128 bytes, SHA-256
`45d5049de8d8b6f5130f049ff5143d8121bd8e1a2c5f8d7ad8dd37509946234d`);
the server log captures the owning preflight refusal. This is the first failing
journey boundary after the bounded repair, preserved without further repair.
The request cannot reach the already-merged homepage route until that boundary
is resolved through its own lifecycle.

The original published baseline's evidence, failed application trees and
stopped container remain unchanged. Candidate applications and their stopped
containers are separate custody. #3199 remains open: the full fresh-install
journey and pending S1 consumer certification have not been proven. No push,
merge, release or deployment is part of this repair.
