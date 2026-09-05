# #2869 coordination — batch-aware projection (after contract settle)

Parent: `FW-DELIVERY-EVENT-BATCHES-01` / Framework #2902  
Projection owner: #2869 (`FW-DELIVERY-TELEMETRY-02`, `bin/project-delivery-agent-events`)

## Candidate implementation

The #2902 candidate supplies the immutable freeze manifest, batch schema, batch
loader, complete-set validation, and deterministic topological replay contract.
The existing local and CI validator invocations already reach that batch-aware
path; no second gate or command form is needed.

## Operational cutover remains pending

1. The #2869 projector must read the source commit's frozen v1 SHA-256 **plus**
   a sorted manifest of `{batch_id, content_sha256}` (see design doc).
2. `plan|apply|verify` rebuild from the complete accepted set using
   `Replay(S)` (v1 line order, then topological batch replay with `(normalized recorded_at, event_id)`
   among causally ready events).
3. Row identity remains `event_id`; ordinals become replay ordinals, not “JSONL
   line of a single file”.
4. Dashboards stay projections; Grafana SQL should not assume a single file
   path. Prefer binding to projection state identity fields.
5. Baseline/friction panels for #2527 may keep using v1 history unchanged until
   batch-aware apply ships — do not silently rewrite TELEMETRY-02 mid-baseline.
6. Publication is permitted only after the accepted freeze and the complete
   batch-aware projector pass qualification together. This record does not
   claim that operational cutover or publication has occurred.

## Handoff rule

- #2902 design + implementation own validator / freeze / batch files.
- #2869 owns projector/schema table evolution and qualification once the #2902
  contract is accepted; see `FW-DELIVERY-BATCH-PROJECTION-01`.
- Neither lane edits the other’s authority blob without an explicit handoff
  commit referenced from both change records.
