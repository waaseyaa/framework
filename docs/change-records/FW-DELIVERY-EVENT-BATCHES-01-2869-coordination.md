# #2869 coordination — batch-aware projection (after contract settle)

Parent: `FW-DELIVERY-EVENT-BATCHES-01` / Framework #2902  
Projection owner: #2869 (`FW-DELIVERY-TELEMETRY-02`, `bin/project-delivery-agent-events`)

## Today

Projection binds a single tracked JSONL + schema from an immutable commit,
validates with `bin/check-delivery-agent-events`, and stores exact-set rows with
line ordinals reconstructing that one ledger.

## After batches are accepted

1. Source identity expands to frozen v1 SHA-256 **plus** a sorted manifest of
   `{batch_id, content_sha256}` (see design doc).
2. `plan|apply|verify` rebuild from the complete accepted set using
   `Replay(S)` (v1 line order, then batch events by `(recorded_at, event_id)`).
3. Row identity remains `event_id`; ordinals become replay ordinals, not “JSONL
   line of a single file”.
4. Dashboards stay projections; Grafana SQL should not assume a single file
   path. Prefer binding to projection state identity fields.
5. Baseline/friction panels for #2527 may keep using v1 history unchanged until
   batch-aware apply ships — do not silently rewrite TELEMETRY-02 mid-baseline.

## Handoff rule

- #2902 design + implementation own validator / freeze / batch files.
- #2869 owns projector/schema table evolution once #2902 contract is accepted.
- Neither lane edits the other’s authority blob without an explicit handoff
  commit referenced from both change records.
