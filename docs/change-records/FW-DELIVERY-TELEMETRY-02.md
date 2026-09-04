# FW-DELIVERY-TELEMETRY-02 — exact-set event projection

- Issue: `#2869`
- Parent record: `FW-DELIVERY-TELEMETRY-01`
- Contract: `docs/specs/delivery-telemetry.md`
- Authority: repository-tracked source changes; forge-neutral

## Outcome

Project the governed off-platform ledger into a disposable analytics database
without allowing the database, importer, or dashboard to reinterpret source
events or retain rows that no longer exist in the validated source.

## Design

Durable projection binds to an immutable, full Git commit. The projector reads
the schema and ledger with `bin/git show`, validates those exact bytes with
`bin/check-delivery-agent-events`, and records the resolved commit alongside the
source hashes. Dirty working-tree bytes can neither enter nor influence a run.

The projector exposes side-effect-free `plan`, transactional `apply`, and
read-only `verify` operations. It validates the complete ledger before opening
a database transaction. Apply then replaces
the target table's contents as one transaction and writes a singleton projection
state row carrying the source commit, exact ledger SHA-256, schema SHA-256, row
count, generation, and projection timestamp. Repeating an already verified
projection is a true no-op: neither generation nor projection timestamp changes.

The versioned target tables are fixed names and are not DevLake-owned tables.
The command accepts no SQL identifier or
statement from an operator. Connection material is read only from environment
variables, is never printed, and MySQL targets must use a loopback host. SQLite
is accepted for executable self-tests and disposable local analysis.

## Failure and recovery

Validation failure performs no database work. Once replacement begins, delete,
insert, exact-set verification, and state update share one transaction. An
exception rolls the transaction back. The prior complete projection therefore
survives host, data, uniqueness, and injected mid-import failures; retrying the
same command is the recovery path.

The projector stores each source line verbatim with its ordinal and line hash.
Verification reconstructs the ledger byte-for-byte from those rows instead of
trusting a stored digest. The projector verifies committed source identity and
row count after commit. Success emits a closed JSON receipt containing outcome,
source commit, source hashes, row count, generation, and exact-set delta; it
never persists that receipt as a second authority.

## Dashboard binding

The versioned Grafana definition reads GitHub-owned PR/CI facts from DevLake and
off-platform events from the exact-set projection. It displays the projection
state prominently. Missing adjudication is `pending`; `unresolved` is reserved
for an explicit governed adjudication. Recent GitHub PR panels are not limited
to PRs that happen to have an off-platform event.

## Acceptance evidence

- [ ] A hostile self-test proves first import, true no-op replay, stale-row
      removal, rollback after injected failure, and projection-state binding.
- [ ] Durable operations resolve a full commit, read tracked source bytes from
      that commit, and refuse source regression or a non-ancestor source.
- [ ] Verification reconstructs the tracked JSONL bytes from ordered raw rows
      and detects row corruption even when the state digest still matches.
- [ ] Unknown CLI options, absent connection configuration, non-loopback MySQL,
      invalid source, and schema/ledger mismatches fail closed.
- [ ] The permanent preflight runs the self-test without credentials or a live
      analytics service.
- [ ] A local MySQL projection imports the exact governed row count and hashes.
- [ ] DevLake refresh completes and the installed dashboard definition is
      byte-semantically identical to the tracked definition.
- [ ] A query proves the old ungoverned event is absent and the 15 governed event
      identifiers are present exactly once.

## Scope boundary

This slice does not create release policy, infer missing off-platform events,
change GitHub ingestion, or make MySQL, DevLake, or Grafana framework runtime
dependencies. Metric percentile definitions, required-check parity, and broader
release-readiness dashboards remain later #2869 work.
