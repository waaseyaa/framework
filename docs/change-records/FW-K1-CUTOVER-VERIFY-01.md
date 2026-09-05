# FW-K1-CUTOVER-VERIFY-01 — read-only K1 cutover verification

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Worktree lease: `da54610503f7ff14849c6fa0fa459154` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2869-k1-cutover-verify`
- Authority: repository-tracked verifier; forge-neutral

## Outcome

Give operators a concrete acceptance check that Panel 8's Grafana query results
match the governed projection identity after the live cutover, without making
Grafana or the projection database an authority.

## Design

`php bin/verify-k1-delivery-cutover` reuses the projector's connection helpers
(`projectionAssertDsn` / `projectionConnect`) and the same environment
variables. The live path is SELECT-only: it
executes Panel 8 SQL from the tracked dashboard and independently reads
`waaseyaa_delivery_projection_state` plus
`waaseyaa_delivery_projection_identity_v2`. Missing identity, Grafana
`unknown` values, or mismatched source SHA, projector version, generation,
event count, or hashes fail closed.

`--self-test` and Architecture fixtures cover matching, missing, and mismatched
disposable SQLite cases without touching live state.

## Scope boundary

This slice owns the verifier, its tests, this record, the operator guide, and
the changelog fragment. It does not change the dashboard, projector, validator,
CI, or live Grafana/projection state.
