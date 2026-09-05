# FW-DELIVERY-BATCH-PROJECTION-01 — complete delivery-event projection

- Issue: `#2869`
- Contracts: `docs/specs/delivery-telemetry.md`, `docs/specs/delivery-agent-event-batches.md`

## Outcome

Project the immutable frozen v1 ledger followed by every accepted batch event in
the governed deterministic replay order. The projection remains rebuildable and
does not become an event authority.

## Identity and migration

Durable reads resolve one source commit and validate its complete event set with
the real immutable-candidate checker. The legacy ledger hash continues to name
only the frozen v1 bytes. An explicitly installed additive identity table binds
the sorted batch ID/content-hash manifest, both schemas, freeze file, and replay
hash. Apply replaces event rows and updates both state tables atomically;
verification compares exact ordered rows and the full identity. Existing v1-only
sources remain supported, and install is idempotent without clearing rows.
Plan, apply, and verify receipts expose the batch manifest, batch schema,
freeze, and replay hashes plus the projector version, so retained CLI evidence
binds the same complete identity that verification enforces in the database.

## Recovery

An identical verified replay is a true no-op. Any failure rolls back event rows
and both identities together; retrying the same immutable source is recovery.

## Independent verification and cutover

Independent review found that `--self-test` loaded `HEAD` through durable
complete-set validation, so it failed in a depth-one checkout. The self-test now
uses controlled in-memory fixture events and identity bytes. Shallow and
source-only fixtures prove that self-test succeeds, while durable `plan`,
`apply`, and `verify` still refuse the same unverifiable `HEAD`; the
committed-batch fixtures continue to exercise the durable path.

The committed-batch fixture proves that a poisoned working-tree batch does not
change immutable source replay, that a batch row is projected, and that manifest
and row corruption are refused by verify and repaired by replay. Receipt hashes
are derived independently and matched to the persisted identity. The upgrade fixture
materializes populated legacy rows and the exact v1 state shape in disposable SQLite,
then removes the v2 identity table and restores projector version 1. It proves that
verify refuses the pre-install database without mutation, install retains legacy
rows, an injected insert failure rolls back the first replay to that legacy state, successful replay
binds the complete identity, and a second apply is a true no-op. The fixture does
not load historical Git objects at runtime, so it remains valid in shallow CI; its
explicit limitation is that legacy state is faithfully established from the current
row and state DDL rather than executed by an archived projector binary. Focused
projection coverage passed 8 tests and 104 assertions after the upgrade verification.

This is the Codex-owned projection integration for
`FW-DELIVERY-EVENT-BATCHES-01`. Operational activation remains pending acceptance
of the corrected freeze/batch-schema immutability contract and qualification of
the combined candidate. No live projection database has been upgraded by this
change record. The schema inventory records the additive identity-table DDL as
a tooling query; it does not add an application schema authority.

The operator sequence is documented in `docs/cookbook/delivery-batch-cutover.md`.
It pins one accepted commit, captures receipts and stderr separately, and requires
a verified repeat-apply no-op. The Bash example passed syntax validation; live
activation remains pending the combined candidate's acceptance.
