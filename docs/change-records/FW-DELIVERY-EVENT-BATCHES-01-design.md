# FW-DELIVERY-EVENT-BATCHES-01 — migration and ordering design

**Status:** accepted and implemented on the #2902 candidate.  
**LIVE contract:** `docs/specs/delivery-agent-event-batches.md`.

## Accepted replay order

1. V1 events in existing JSONL line order.
2. Batch events in deterministic topological order. Among events whose causes are
   already emitted, choose by `(normalized recorded_at, event_id)`.

Plain timestamp/UUID sorting is rejected: equal-time effects must not precede
their causes.

## Accepted freeze

Hard-freeze the actual accepted ledger at cutover via
`ops/observability/delivery-agent-v1-freeze.json`. There is **no** rewrite
exception. The design-start SHA was illustrative only.

## Accepted format

JSON batch envelopes; duplicate event IDs refused even when payloads match;
closed v1 event schema preserved; all causal/adjudication checks retained.

## Integration boundary

Codex reviewed the existing CI/preflight commands; no command change is required. Batch publication is operator-live only
with the #2869 projection reader.
