# FW-DELIVERY-EVENT-BATCHES-01 — immutable agent-event batches

- Status: **implementing / review**
- Parent programme: Framework #2527
- Forge mirror: Framework #2902
- Depends on: #2900 / PR #2907 (landed)
- Coordinates with: #2869 projection (`FW-DELIVERY-TELEMETRY-02`)
- Worktree lease: `6f57f3a75066e7c8eaed54cf51681b4c` at
  `/home/fsd42/dev/waaseyaa-worktrees/fw-2902-event-batches`
- Design-start head: `a0dbd5353dfc3ef6aaf38d61afb6a969f54c0485`
- Cutover freeze ledger SHA-256 (actual accepted bytes):
  `662c662ebb10ee49189540b74144abbfdf91dc2a9e0762cda84efcccc032a4ed`
  (111 accepted events, 117888 bytes; freeze binds the cutover ledger)

## Intent

Replace single-JSONL append contention with independent immutable batch files and
deterministic complete-set validation/replay, while keeping every accepted v1
byte and event ID intact.

## Accepted decisions (Codex review)

1. Replay: preserve v1 line order; batch events use deterministic topological
   order; among ready events choose `(normalized recorded_at, event_id)`.
2. Format: JSON batch envelopes; refuse duplicate event IDs even when payloads
   match; keep closed v1 event schema and all causal/adjudication checks.
3. Freeze: hard-freeze actual accepted ledger at cutover; no rewrite exception.
4. Integration: schema/loading/validation/replay/immutability in-lane; shared
   CI/preflight uses the existing commands, as reviewed by Codex; batch publication live only with #2869
   projection reader.
5. Proofs: both merge orders through the real gate, equal-time causal chains,
   timezone-equivalent timestamps, cross-batch adjudication, #2900 history
   checks preserved. Replay ordinals may change; event IDs stay stable.

## Deliverables

| Path | Role |
| --- | --- |
| `ops/observability/delivery-agent-batch-v1.schema.json` | Batch envelope schema |
| `ops/observability/delivery-agent-v1-freeze.json` | Hard freeze manifest |
| `ops/observability/delivery-agent-batches-v1/` | Batch directory (publication gated with #2869) |
| `bin/lib/delivery-agent-event-set.php` | Complete-set + topo replay |
| `bin/check-delivery-agent-events` | Wired freeze/batches/immutability |
| `docs/specs/delivery-agent-event-batches.md` | LIVE contract |
| Architecture fixtures | Contention, adversarial, gate proofs |

## Codex integration

- Existing `.github/workflows/ci.yml` and `tools/preflight-gates.json` commands already invoke the batch-aware gate; no duplicate gate or command change is needed.
- Batch-aware `bin/project-delivery-agent-events` is included under `FW-DELIVERY-BATCH-PROJECTION-01`, with upgrade/rollback fixtures and `docs/cookbook/delivery-batch-cutover.md`. Operational activation follows qualification and acceptance of the complete candidate.
