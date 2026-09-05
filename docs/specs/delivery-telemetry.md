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
Local Composer and preflight checks use `--branch-base=<ref>`: resolve the ref
and HEAD once, require a unique merge-base, then check current worktree bytes
against that common ancestor. An unrelated append on main does not invalidate
a source-only branch. Existing `--base` retains its exact-prefix meaning.

Required CI uses immutable `--candidate=<40-SHA> --base=<40-SHA>`. PR checks
also pass `--head=<40-SHA>` and require the candidate's two ordered parents to
match the event's base and head. Push checks retain `event.before` across the
entire push; explicit dispatches qualify the candidate against its first parent.
Candidate mode reads committed schema and ledger bytes, refuses fixture path
overrides, and rejects missing or incompatible ancestry. Normal schema, causal,
ID-uniqueness, and accepted-prefix rules apply in every mode. Both new history
modes require a complete (non-shallow) repository; truncated history cannot prove
uniqueness or ancestry.

The governed merge adapter pins the PR head and requires main's effective rules
to enforce strict `ci/verify-gates` checks from GitHub Actions. GitHub enforces
freshness at the actual merge, including if main moves after the adapter's rules
lookup. There is no bypass or unpinned fallback. This does not remove normal
strict branch requirements or solve conflicting concurrent appends (#2902).

`--self-test` seeds independent schema, causality, temporal, adjudication, and
history corruptions and must fail each one.

The v1 schema is closed. A vocabulary or shape change requires a new schema
version and an explicit migration; projections do not widen the contract.

## Projection

`php bin/project-delivery-agent-events plan|apply|verify --source-ref=<ref>` is
the governed source-to-analytics boundary. Durable operations resolve the ref to
a full commit and read the tracked schema and ledger from that commit; dirty
working-tree bytes are not an input. It validates the complete source before
database mutation and replaces
event rows, projection identity, and row count in one transaction. A failure
rolls back to the prior complete projection; retry is recovery. Success is based
on committed row count and source hashes rather than parsed input count.

The projection is an exact set, not an append-only authority. Rows absent from
the governed source are removed from the projection. Replaying an already
verified source is a true no-op and advances neither generation nor projection
time. A singleton state row binds the projection to the resolved source commit,
ledger SHA-256, schema SHA-256, source row count, generation, and projection time
so dashboards can display freshness and source identity. Ordered rows preserve
each JSONL line verbatim and carry an individual line hash; verification
reconstructs the complete ledger bytes and cannot accept a matching state digest
over corrupted rows.

Connection secrets are environment-only and are never included in output or
tracked dashboard exports. MySQL projection is local-operator tooling and
refuses non-loopback hosts. SQLite is permitted for disposable analysis and the
credential-free hostile self-test. Table names and SQL are closed by the tool;
no operator-supplied identifier or statement is accepted.

The tracked Grafana definition is a rebuildable view of GitHub-owned DevLake
facts and the governed projection. A missing finding adjudication is displayed
as `pending`; only an explicit `unresolved` adjudication may display unresolved.
Recent GitHub delivery panels must not exclude a PR merely because no
off-platform event exists for it.

GitHub ingestion recovery, metric calculations, required-check parity, and the
remaining dashboard families remain later parts of #2869.
