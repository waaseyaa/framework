# FW-DELIVERY-EVENT-BATCHES-01 — migration and ordering design (Codex review)

**Status:** proposed — awaiting Codex acceptance before implementation.  
**Authority today:** `docs/specs/delivery-telemetry.md` + v1 JSONL/schema.  
**Proposed contract sketch:** `docs/specs/delivery-agent-event-batches.md`.

## Problem restated

`ops/observability/delivery-agent-events-v1.jsonl` is a single append-only blob.
Two independent evidence commits that each extend the same tip produce a Git
textual conflict on that one path. #2900 fixed *false* failures from unrelated
main appends during branch validation; it did **not** remove dual-append
contention when two lanes both publish new events.

## Non-goals

- Do not rewrite, retimestamp, or re-ID any accepted v1 event.
- Do not invent `occurred_at` for historical nulls.
- Do not make DevLake/Grafana policy authorities.
- Do not add per-commit CI jobs or claim old proof can be reused for unrelated
  changed candidates.
- Do not land shared `ci.yml` / `preflight-gates.json` edits in the first
  implementation PR — those ride a separate Codex integration patch.

## Layout after cutover

```
ops/observability/delivery-agent-event-v1.schema.json     # unchanged closed v1 event schema
ops/observability/delivery-agent-events-v1.jsonl          # FROZEN accepted history (byte-identical)
ops/observability/delivery-agent-batch-v1.schema.json     # NEW: batch envelope schema (implementation)
ops/observability/delivery-agent-batches-v1/
  <batch_id>.json                                         # NEW: one immutable file per batch
```

### Why files, not a second JSONL tail

- Distinct paths ⇒ concurrent lanes never edit the same blob.
- No shared mutable index or aggregate file to race on.
- Discovery = directory listing of `delivery-agent-batches-v1/*.json` on the
  committed tree (plus the frozen v1 ledger). Sorting is algorithmic, not
  “whichever file Git merged last”.

### Batch envelope (proposed)

```json
{
  "schema_version": "delivery-agent-batch/v1",
  "batch_id": "0193a1c2-…",
  "created_at": "2026-09-05T12:00:00Z",
  "producer": { "kind": "cursor", "name": "Cursor", "model": null },
  "events": [ /* one or more delivery-agent-event/v1 objects */ ]
}
```

Rules:

- `batch_id` is a UUID; filename must be exactly `{batch_id}.json`.
- `events` is a non-empty array of objects that each validate against the
  **existing** closed `delivery-agent-event/v1` schema (same vocabulary; no
  silent widening).
- Batch file bytes are immutable once accepted on `main`: no modify, no delete,
  no rename. New evidence = new `batch_id` / new path only.
- Lanes must not append to `delivery-agent-events-v1.jsonl` after the freeze
  commit (gate refuses growth or mutation of that blob relative to the freeze
  ancestor on `main`).

## Preserving v1

At freeze commit `F`:

1. Record `v1_ledger_sha256` and byte length in the change record / gate
   constant (design-start measurement:
   `0c8be52156201fa1f3c35d0261fdc91446ba8a49e491020bdba106a2f011c38f`).
2. All future accepted trees must contain **identical** v1 ledger bytes to `F`
   (or to the latest accepted freeze if a one-time governed rewrite is ever
   authorized — none is proposed here).
3. Event IDs already present in v1 remain the sole IDs for those facts forever.
4. Projection and validators treat v1 lines as the first segment of the
   complete accepted set, preserving current line-order / `source_ordinal`
   semantics **for v1 only**.

This is an explicit versioning cut: batches are a **sibling authority surface**,
not a widened v1 line format.

## Complete-set validation

Load:

1. Every event from the frozen v1 JSONL (in file order).
2. Every event from every batch file under `delivery-agent-batches-v1/`.

Then enforce across the **union**:

| Rule | Behaviour |
| --- | --- |
| Event-ID uniqueness | Same ID with identical payload bytes → refuse duplicate (batches must not restate v1 or each other). Conflicting payload for same ID → refuse. |
| Causal closure | `causation_event_id` must name an event present in the union; refuse missing causes. |
| Causality direction | Custody (`recorded_at`) of effect ≥ cause; when both `occurred_at` are non-null, effect ≥ cause. |
| Cycles | Refuse causal cycles (DFS/toposort over the union). |
| Single adjudication | At most one `verification_finding_adjudicated` per finding ID across the entire set. |
| Cross-batch adjudication | Allowed if cause exists anywhere in the union and head_sha / classification coherence rules from v1 still hold. |
| Event semantics | Existing v1 type/outcome/evidence applicability tables unchanged. |
| Batch immutability vs base | Relative to merge-base / accepted main: may only **add** new batch paths; may not alter/delete existing batch files or the v1 blob. |

Branch mode (post-#2900) stays: validate branch delta against merge-base.  
Candidate/acceptance mode: validate proposed combined tree against current main
so neither side’s accepted batches or v1 bytes are dropped.

## Deterministic ordering (migration-critical)

### Why v1 line-order must not be imposed on batches

v1 required non-decreasing `recorded_at` along the **file** and used line order
as the custody sequence. Independent batches are concurrent: requiring a global
line order would reintroduce a single tail. Therefore batches are **sets** with
an explicit total order for projection/replay, not a second append-only spine.

### Proposed total order over the complete accepted set

Define a deterministic sequence `Replay(S)` for accepted set `S`:

1. **Segment V1:** all events from the frozen JSONL in existing byte/line order
   (unchanged `source_ordinal` 1..N).
2. **Segment Batches:** all events from all batch files, sorted by the tuple  
   `(recorded_at ASC, event_id ASC)`  
   using the custody timestamps already on the events (ISO-8601 / DateTime
   comparable as today). Ties break on `event_id` (UUID string compare).  
   **Do not** use Git commit time, batch `created_at`, or invented
   `occurred_at` values for this key.

Properties:

- **Commutative acceptance:** if batch files `A` and `B` are both valid against
  `S₀` and do not conflict, `Replay(S₀ ∪ A ∪ B)` is identical regardless of
  which path landed first on `main`.
- **No invented times:** null `occurred_at` stays null; duration math still
  excludes nulls (TELEMETRY-01).
- **Later-recorded historical events:** an event with late `recorded_at` but
  null `occurred_at` sorts by custody time only — correct for replay identity,
  still excluded from occurrence-based metrics.
- **Intra-batch causality:** authors SHOULD emit events that are causally
  coherent; the validator checks the union graph, not “line N before line N+1
  inside the batch file”. File array order is **not** authoritative for replay.

### Alternatives considered (rejected unless Codex prefers)

| Alternative | Why rejected |
| --- | --- |
| Sort by `(batch_id, array_index)` | Replay order depends on UUID minting, not custody; harder to reason about metrics timelines. |
| Require global monotonic `recorded_at` across batches like v1 lines | Forces serialization / clock skew fights; recreates contention socially. |
| Merge by Git topology order | Non-deterministic across rebase; violates “either valid order”. |
| Widen v1 JSONL with a batch marker field | Silently widens closed v1; forbidden by acceptance. |

## Commutativity proof obligation (implementation)

Architecture fixtures already landed (ordering-independent):

- Duplicate event IDs (identical and conflicting payloads)
- Missing causal references
- Causal cycles
- Conflicting double adjudications
- Modified or deleted accepted batch paths

Additional Architecture fixture (implementation PR, after ordering accept):

1. Fixture tree `S₀` = frozen mini v1 ledger.
2. Batch `A` and batch `B` with disjoint event IDs, no causal edges between them
   (or edges only into `S₀`).
3. Assert validation(`S₀∪A∪B`) passes.
4. Assert `Replay(S₀∪A∪B)` bytes/IDs equal for apply-orders `(A then B)` and
   `(B then A)`.
5. Assert conflicting duplicate IDs / missing causes / double adjudication /
   v1 byte mutation still fail.
6. Preserve all current adversarial history checks from
   `bin/check-delivery-agent-events --self-test` and #2900 branch/candidate
   fixtures, extended to batch paths.

## Projection identity (coordinate with #2869)

Today TELEMETRY-02 binds one ledger SHA-256. After batches:

```
source_identity = {
  v1_ledger_sha256,
  batch_manifest: sorted list of { batch_id, content_sha256 },
  schema_sha256 (event + batch schemas)
}
```

`plan|apply|verify` must rebuild idempotently from that immutable identity,
detect missing/replaced rows, and reproduce the same accepted event set.
Dashboard remains a projection. **Do not implement this binding in the design
PR**; see `FW-DELIVERY-EVENT-BATCHES-01-2869-coordination.md`.

## Cutover steps (after design acceptance)

1. Land design + fixtures documenting contention (this candidate).
2. Implementation PR: batch schema, directory, validator complete-set mode,
   freeze enforcement, commutativity + adversarial tests.
3. Codex CI/preflight integration patch (separate).
4. #2869 batch-aware projection follow-through.
5. Operators publish only new batch files thereafter.

## Open questions for Codex

1. Confirm `(recorded_at, event_id)` as the batch-segment total order, or name
   a preferred alternative that stays commutative and time-honest.
2. Confirm freeze semantics: hard refuse any post-freeze v1 growth vs a short
   dual-write window (design recommends **hard freeze** at cutover commit).
3. Confirm duplicate ID policy: refuse even byte-identical restatement (design
   recommendation) vs treat identical bytes as idempotent no-op.
4. Confirm batch envelope JSON (array of events) vs JSONL-per-batch-file.
