# Delivery telemetry

## Authority

GitHub owns repository, pull-request, issue, and hosted-CI events. The tracked
`ops/observability/delivery-agent-events-v1.jsonl` ledger owns off-platform
review and verification events. Its closed schema is
`ops/observability/delivery-agent-event-v1.schema.json`.

DevLake, MySQL, Grafana, and other reporting systems are rebuildable
projections. They may retain source identifiers and projection diagnostics,
but they never define event vocabulary, release policy, or support policy.
The off-platform ledger does not copy GitHub-owned events as new agent events.

## Custody and time

The ledger is append-only. Each revision must retain the complete prior blob as
an exact byte prefix and append newline-terminated JSON objects. Git history is
the integrity chain; this contract does not add a competing per-event chain.

`recorded_at` is the custody time at which the event entered the ledger.
`occurred_at` is the source occurrence time. When historical evidence does not
establish an occurrence time, it remains `null` and is excluded from duration
calculations. Custody time must not be presented as occurrence time.

## Causality and adjudication

A causal reference names an earlier event in the same repository and pull
request. Repair events may cross head SHAs. An adjudication must name one prior
`verification_finding_issued` event on the same head SHA, and each finding has
at most one adjudication.

Verifier claims and decisions are separate facts. A missing decision is
pending, not `unresolved`. `unresolved` is an explicit adjudication whose
`candidate_defect_confirmed` value is `null`.

## Evidence and field applicability

Evidence quality is explicit:

- `observed` means the recording actor directly witnessed or executed the fact;
- `operator_assertion` means a person or agent supplied the fact without a
  machine-resolvable source event;
- `source_event` mirrors a resolvable external fact and requires `source_url`;
- `derived` is a calculation and requires a source URL or causal predecessor.

Missing provenance is never upgraded to a stronger evidence kind.

| Event type | Allowed outcome | `review_depth` | `finding_count` | `token_count`, `elapsed_ms` |
| --- | --- | --- | --- | --- |
| `review_started` | null | optional | null | null |
| `substantive_review_issued` | accepted, changes_requested, refused | optional | optional | optional |
| `repair_requested` | changes_requested | null | optional | null |
| `repair_started` | null | null | null | null |
| `repair_completed` | passed, failed | null | optional | optional |
| `decision_recorded` | accepted, refused | null | null | null |
| `verification_completed` | passed, failed | optional | optional | optional |
| `verification_finding_issued` | null | null | null | null |
| `verification_finding_adjudicated` | null | null | null | null |

“Optional” means a correctly typed value or null. Counts and elapsed values are
local to that event; projections must not reinterpret them as a different
boundary.

## Validation and evolution

`php bin/check-delivery-agent-events` executes the JSON Schema and the causal
rules. With `--base=<ref>` it also proves exact-prefix append-only history.
`--self-test` seeds independent schema, causality, temporal, adjudication, and
history corruptions and must fail each one.

The v1 schema is closed. A vocabulary or shape change requires a new schema
version and an explicit migration; projections do not widen the contract.

This first delivery slice establishes source authority only. Projection
idempotence, GitHub ingestion recovery, metric calculations, required-check
parity, and dashboards remain later parts of #2869.
