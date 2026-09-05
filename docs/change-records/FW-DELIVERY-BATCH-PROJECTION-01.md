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

The committed-batch fixture proves that a poisoned working-tree batch does not
change immutable source replay, that a batch row is projected, and that manifest
and row corruption are refused by verify and repaired by replay. Receipt hashes
are derived independently and matched to the persisted identity. Focused
projection coverage passed 7 tests and 57 assertions after the receipt repair.

This is the Codex-owned projection integration for
`FW-DELIVERY-EVENT-BATCHES-01`. Operational activation remains pending acceptance
of the corrected freeze/batch-schema immutability contract and qualification of
the combined candidate. No live projection database has been upgraded by this
change record. The schema inventory records the additive identity-table DDL as
a tooling query; it does not add an application schema authority.