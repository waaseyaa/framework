# Delivery agent event batches

Status: **LIVE** (Framework #2902 / `FW-DELIVERY-EVENT-BATCHES-01`)

Related: `docs/specs/delivery-telemetry.md`, #2869 projection coordination.

## Purpose

New off-platform evidence is published as uniquely identified **immutable JSON
batch envelopes**. The accepted v1 JSONL is **hard-frozen** at cutover. Dashboards
remain projections over the complete accepted set.

## Authority surfaces

| Surface | Role |
| --- | --- |
| `delivery-agent-events-v1.jsonl` | Frozen accepted history; byte-identical to `delivery-agent-v1-freeze.json` |
| `delivery-agent-v1-freeze.json` | Records the cutover ledger SHA-256 (no rewrite exception) |
| `delivery-agent-batches-v1/<batch_id>.json` | Immutable additive batches |
| `delivery-agent-event-v1.schema.json` | Unchanged closed event vocabulary |
| `delivery-agent-batch-v1.schema.json` | Batch envelope schema |

## Complete-set rules

Across frozen v1 ∪ all batches: unique event IDs (duplicates refused even when
payloads match), causal closure, no cycles, custody/occurrence directionality,
at most one adjudication per finding, immutable existing batch paths and v1
bytes, and immutable accepted freeze-manifest / batch-schema blobs
(additive-only acceptance).

## Replay order

1. V1 events in existing JSONL line order.
2. Batch events in deterministic **topological** order. Among events whose
   causes are already emitted, choose by `(normalized recorded_at, event_id)`.
   Plain timestamp/UUID sorting is insufficient: equal-time effects must not
   precede their causes.

Batch replay ordinals may change as the accepted set grows; **event IDs remain
stable**.

## Publication readiness

Gate enforcement for batches is implemented in `bin/check-delivery-agent-events`.
Operator batch publication is live only when this enforcement and the #2869
projection reader are ready together. Shared CI/preflight roster edits remain a
Codex integration patch.
