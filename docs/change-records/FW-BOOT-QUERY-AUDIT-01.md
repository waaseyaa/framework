# Boot query audit durability

Issue: #3204. Status: local repair candidate under qualification, not landed.
Owners: entity-storage (gateway and validation), audit (provider writer),
foundation (boot composition). Program: #3118; boot journey: #3199; foundation
assessment: #3123. This is a bounded composition supplement, not a completed
package assessment or framework journey.

## Boundary and evidence

Reported source: `9cefbdf93e771df8130ac6ae2a131dd556726b92`.
Repair base: `902a95edf36013a066e18275922a868b707c6d32`. The intervening workflow
changes do not change packages or composer.lock. Supplied portable consumer
evidence identifies concurrent anonymous failures before controller dispatch,
with stacks in query-definition validation and gateway audit reserve/finalize.
It shows repeated writer-lock intervals and cumulative durability cost, not a
single ten-second transaction. Physical durability latency remains unresolved.

An isolated synthetic validation with 60 indexed fields produced 120 audit
INSERTs, no explicit transaction, and 60 reservation/success pairs. The native
Windows eight-process control passed. A separate Linux source export with its
own lockfile-installed dependencies passed the existing three full-boot tests.
Linux syscall tracing showed sync calls for the autocommitted writes.

Injecting 90 ms at fsync/fdatasync entry in two concurrent Linux processes
reproduced SQLite error 5 outside an outer transaction: one validation passed
in 13.57 seconds and the other failed in 12.38 seconds. This is a controlled
durability-pressure discriminator, not a diagnosis of a consumer hypervisor or
an exact consumer runtime reproduction. No consumer repository or VM was changed.

## Contract and design

Preserve mandatory audit pre-durability, per-field descriptors, registrar-bound
inputs/outputs, fresh fingerprints and first-unsupported refusal. Replace
per-field autocommit amplification with two durable transactions per adjacent
same-gateway run: reserve every planned attempt, invoke outside transactions,
then finalize every outcome before returning. Do not cache capability answers,
replay callbacks, increase timeouts, reduce synchronous mode or suppress audits.

Single-operation audit implementations remain valid extension integrations.
The optional batch interface is a real persistence capability, not a legacy
shim. Both Framework database writers share the same batching implementation;
their existing append-only/provider versus schema-owning/core responsibilities
remain. Database-backed batches refuse an outer transaction or an opaque
database wrapper whose independent commit boundary cannot be established.

SQLite documents a single WAL writer and per-commit sync in FULL mode:
https://www.sqlite.org/wal.html and https://www.sqlite.org/pragma.html.
Use the existing Doctrine/Framework transaction mechanism; do not add a lock,
retry loop or generic transaction framework. Symfony Process owns bounded
diagnostic subprocesses. Symfony cannot supply Waaseyaa's value-free gateway
receipt and authority policy; no new generic mechanism is justified here.

## Reconciliation and audit supplement

Open GitHub issues were paginated at intake; relevant closed SQLite and gateway
issues were read. #3183 resolved a distinct privileged-read finalization
snapshot-upgrade defect, not the demonstrated gateway autocommit pressure.
#2275 and #2446 are prior related contention mechanisms, not evidence this
gateway path is repaired. #3182's introspection optimization is distinct.
#3110 remains schema-authority work, not a reason to introduce boot-time DDL.
The audit provider and core fallback require separate failure controls.

Live authorities reviewed: entity-system definition validation, the WP3/WP4
gateway contract in entity-field-read-boundary, ocap-audit-log strict persistence,
SqliteTopology, and the repository persistence/kernel/failure audit profiles.
The two gateway writer implementations duplicate descriptor persistence;
this repair shares only new batch semantics and does not claim a complete
writer-consolidation assessment. Full audit/field-storage package assessment
and broader failure handling remain with their existing owners.

Supplement J1/#3199 acceptance with ordinary concurrent boot on file-backed
SQLite, nontrivial indexed-field counts and durable audit pressure. A serial
homepage pass or memory database cannot prove this boundary. Preserve #3183's
snapshot discriminator alongside this independent autocommit discriminator.

## Qualification evidence and remaining ownership

- Red regression: 60 fields across three types must retain 120 events with two
  explicit commits; it failed on the exact base (zero grouped commits).
- Independent observer connection proves reservations visible before callbacks,
  no callback transaction, and finalizations visible before return.
- Both writers: reservation/finalization partial failure, outer transaction,
  duplicate receipt, fingerprint drift, backend failure and skipped probes.
- Existing gateway, definition validator, audit writer and full-kernel tests.
- Repeat unchanged concurrent slow-sync discriminator with candidate bytes.
- Independent immutable-candidate review, required gates and an exact local
  qualification record. Hosted/full and installed-consumer acceptance remain
  distinct and must not be inferred from isolated tests.

The controlled Linux slow-sync replay with repaired bytes completed both
workers successfully (0.46 and 0.86 seconds), retaining the same per-field
records and durability settings. This demonstrates removal of commit
amplification under the injected condition, not universal contention freedom.
Focused native regressions passed: 21 tests, 88 assertions. Focused Linux
checks passed: 73 tests, 239 assertions, including both database writers,
gateway contracts and full kernel validation. A separate diagnostic composition
using Doctrine DBAL 4.4.4 passed the same 73 tests and 239 assertions. The
repository lock remains unchanged at DBAL 4.4.3. Static analysis of the changed
production boundary passed without suppressions.

Native architecture assertions have an existing path-separator mismatch;
native kernel fixture teardown also encounters open SQLite files. Linux
focused checks cover those boundaries. Neither result is a hosted full-suite
verdict. Independent review and exact-head default preflight are recorded
with the immutable candidate in the private evidence handoff. No installation
or browser qualification of a downstream application is claimed.

## Downstream requirements

Adopt only a reviewed immutable candidate and coherently rebuilt package
closure after separately authorized delivery. Repeat anonymous homepage/image
concurrency in the installed composition, plus unsupported-field refusal,
mandatory audit failure and interruption controls. Capture effective WAL,
synchronous mode, busy timeout, SQL/transaction lifetimes and sync timings.
Investigate the storage-latency cause separately. Existing stopped environments
require explicit maintainer approval before reuse. No release, tags, splits or
deployment are authorized by this record; the alpha hold remains.
