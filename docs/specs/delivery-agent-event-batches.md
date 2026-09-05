# Delivery agent event batches (proposed)

Status: **DESIGN REVIEW** — not LIVE. Do not treat as enforced contract until
`FW-DELIVERY-EVENT-BATCHES-01` is accepted by Codex and an implementation PR
lands. Live authority remains `docs/specs/delivery-telemetry.md` and the v1
JSONL/schema.

Related: Framework #2902, #2869, #2900; design detail in
`docs/change-records/FW-DELIVERY-EVENT-BATCHES-01-design.md`.

## Purpose

Remove single-file JSONL append contention by storing new off-platform evidence
in uniquely identified **immutable batch files**, while preserving every
accepted v1 ledger byte and event ID.

## Authority surfaces (proposed)

| Surface | Role |
| --- | --- |
| `delivery-agent-events-v1.jsonl` | Frozen accepted history; byte-identical forever after freeze |
| `delivery-agent-batches-v1/<batch_id>.json` | Immutable additive batches of v1-shaped events |
| Event schema v1 | Unchanged closed event vocabulary |
| Batch schema v1 | Envelope only (`batch_id`, metadata, `events[]`) |

Projections (DevLake/MySQL/Grafana) remain rebuildable views over the complete
accepted set.

## Complete-set rules (proposed)

Across frozen v1 ∪ all batches: unique event IDs, causal closure, no cycles,
custody/occurrence directionality, at most one adjudication per finding,
immutable existing batch paths and v1 bytes, additive-only acceptance.

## Replay order (proposed)

1. V1 events in existing file order.
2. All batch events sorted by `(recorded_at ASC, event_id ASC)`.

Acceptance order of non-conflicting batches must not change `Replay(S)`.

## Freeze

After the cutover commit, growth or mutation of the v1 JSONL is refused. New
evidence is new batch files only.
