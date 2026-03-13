# Codified Context — Design Decisions & Summary

## Why Extend Telescope Instead of a Separate Module?

The existing `packages/telescope/` package already provides:
- A storage abstraction (`TelescopeStoreInterface`) and SQLite backend
- A service provider with the lazy-getter/config pattern for recorders
- A consistent entry point for observability across the platform

Extending Telescope keeps instrumentation co-located with other observability
concerns (queries, events, requests, cache). A separate module would duplicate
the store infrastructure and create a second observability configuration surface.

The tradeoff is coupling: `CodifiedContextObserver` is aware of the Telescope
store interface. If the store interface changes, codified context adapters must
update too. This is acceptable — both live in the same package.

---

## Tradeoffs

### In-process Prometheus vs. Exporter

**Decision:** Push directly to a push-gateway rather than exposing a `/metrics`
scrape endpoint.

**Rationale:** The platform runs as a CLI tool and short-lived PHP processes —
there is no persistent process to serve a scrape endpoint. Push-gateway fits
the ephemeral process model.

**Tradeoff:** Push-gateway introduces a single point of failure. If it is
unavailable, metrics are silently dropped (we log via `error_log()` but do not
crash). Operators must monitor push-gateway availability separately.

### JSONL Simplicity vs. SQLite Queries

**Decision:** Provide both adapters; default to SQLite in development, JSONL
in production log-shipping environments.

**JSONL:** Zero-dependency, crash-safe (append-only), trivial to ship to
external systems. Cannot answer "show me the 10 sessions with lowest drift
score" without a full scan.

**SQLite:** Full SQL expressiveness, instant per-session lookups, supports the
SSR detail view without a full log scan. Single-writer — unsuitable for
distributed deployments.

**Not chosen:** PostgreSQL/MySQL adapter. Adds a hard dependency on a running
RDBMS server during development. The pattern exists if a future contributor
wants to add it — implement `CodifiedContextStoreInterface`.

### Deterministic Scoring vs. Probabilistic

**Decision:** Drift score is deterministic given identical inputs (same
embeddings, same spec text, same events).

**Rationale:** Reproducibility is essential for regression detection. If the
score changes between runs on the same session, it cannot be used as a
reliable signal.

**Tradeoff:** Determinism requires stable embeddings. The default TF-IDF
provider is fully deterministic. External embedding providers (OpenAI etc.)
may return slightly different vectors across API versions — operators using
external providers should pin the model version in their configuration.

### Heuristic Contradiction Detection

**Decision:** `ContradictionDetector` uses keyword and pattern matching, not
semantic reasoning.

**Rationale:** Semantic contradiction detection requires LLM inference at
validation time, adding latency and cost. Heuristic matching catches common
patterns (e.g., "never X" in one file, "always X" in another) with zero
external calls.

**Tradeoff:** False negatives — subtle contradictions are missed. The
contradiction score component (0–20 pts) is weighted lower than semantic
alignment (0–60 pts) to reflect this limitation.

---

## Algorithm Choices

### Drift Score Composition (0–100)

```
driftScore = semantic_alignment   (0–60)
           + structural_checks     (0–20)
           + contradiction_checks  (0–20)
```

Semantic alignment is weighted 3× the other components because it directly
measures whether the context influenced the output. Structural and contradiction
checks are useful sanity signals but cannot be as confidently measured.

### Structural Check Rules

Derived directly from the CLAUDE.md "Architecture Gotchas" section. Each rule
is encoded as a named check (e.g., `layer_discipline`, `final_class_default`,
`json_symmetry`). A check passes (contributes its weight) or fails (contributes
zero). No partial credit — ambiguity is treated as failure.

### Semantic Alignment Calculation

1. Concatenate all spec/skill content loaded during the session
2. Concatenate all generated code captured via `codeGenerated` events
3. Compute embeddings for both corpora
4. Score = cosine_similarity(context_embedding, code_embedding) × 60

Scores below 0.4 cosine similarity trigger a `critical` issue.

---

## Next Steps

### ClickHouse Adapter

For teams with high session volume (hundreds per day), SQLite write contention
becomes a bottleneck. A `ClickhouseCodifiedContextAdapter` would provide:
- Columnar storage for efficient drift score analytics
- Sub-second aggregation across thousands of sessions
- Native time-series functions for drift trend detection

Implementation: implement `CodifiedContextStoreInterface`, push via HTTP
insert API. No schema migration needed — ClickHouse is schemaless for inserts.

### Alerting Integration

Add a `DriftAlertDispatcher` that fires a `DomainEvent` when drift score drops
below a configurable threshold. Consumers can subscribe to trigger:
- GitHub issue creation ("spec drift detected in entity-system")
- Slack/Teams webhook notification
- Block CI pipeline via `bin/check-milestones` exit code

### Retention Policy

JSONL logs and SQLite rows accumulate indefinitely. Add a `PruneOldSessionsCommand`:

```
bin/waaseyaa telescope:codified-context:prune --older-than=90d
```

Default retention: 90 days for full event streams, indefinite for aggregated
drift scores (small row).

### SSR Template Enhancements

- Add a drift score sparkline chart (SVG, no JS dependency) across recent sessions
- Link from session view to the specific spec files that contributed low scores
- Export session as PDF for audit trail (use `wkhtmltopdf` or `Chromium --print-to-pdf`)
