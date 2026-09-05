# FW-K1-BATCH-PROVENANCE-01 — batch-aware K1 projection identity

- Issue: `#2869`
- Contract: `docs/specs/delivery-telemetry.md`
- Codex schema source: `codex/2902-integration` table
  `waaseyaa_delivery_projection_identity_v2`
- Worktree lease: `bdafa788ab701ab50b0b00e783e8c09e` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2869-k1-batch-provenance`
- Authority: repository-tracked Grafana definition; forge-neutral

## Outcome

Show complete projection provenance on the K1 delivery-flow dashboard without
making Grafana an authority, and without importing the definition into live
Grafana. Codex coordinates that import after the projection cutover.

## Design

Panel 8 reads the existing singleton `waaseyaa_delivery_projection_state` row
and left-joins Codex's additive v2 identity table. It displays:

- projected source SHA
- projector version
- generation
- event count
- projection time
- frozen v1 ledger SHA-256 (JSONL cutover bytes only)
- complete replay SHA-256 (frozen v1 plus accepted batches)
- batch-manifest SHA-256

Those two hashes are separately labeled so a matching ledger digest cannot be
mistaken for the complete replay. A seed row for
`delivery-agent-events/v1` keeps the panel at one row when state or identity is
absent. `COALESCE(..., 'unknown')` keeps missing values visibly unknown rather
than blank.

## Verification

Disposable SQLite fixtures in `K1BatchProvenanceDashboardTest` execute the
tracked panel SQL against Codex's v2 identity DDL. They cover a populated
identity, a v1-only state row, and a fully missing projection.

## Scope boundary

This slice owns only the tracked dashboard JSON, those query fixtures, this
record, and the changelog fragment. It does not change the validator, projector,
CI, shared governance files, or the live Grafana instance.
