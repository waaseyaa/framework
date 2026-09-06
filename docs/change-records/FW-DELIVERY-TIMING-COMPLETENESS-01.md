# FW-DELIVERY-TIMING-COMPLETENESS-01 — Trustworthy elapsed_ms for future review/repair events

Status: IMPLEMENTED / QUALIFYING
Forge mirror: #2947 (lightweight; not a durable identity per `docs/specs/workflow.md`).
Initial source: d64a825fc8a4ec56e8ec259da2415a14a0c2f116.

## Problem and authority

`substantive_review_issued` and `repair_completed` events carry an `elapsed_ms`
field, but nothing validated that a populated value was actually derived from
matching start/end occurrence timestamps. A writer could assert any number.
The frozen v1 ledger (`docs/specs/delivery-agent-event-batches.md`) is
byte-immutable — `ops/observability/delivery-agent-v1-freeze.json` already
pins its SHA-256 — so every future delivery-agent event necessarily arrives
through the governed batch path
(`ops/observability/delivery-agent-batches-v1/*.json`), not as a new JSONL
line. This change scopes the new rule to that batch path only, leaving the
frozen ledger, its schema, and all existing causal/temporal rules untouched.

## Design

Add `delivery_agent_elapsed_ms_errors(array $event, array $eventsById): array`
to `bin/lib/delivery-agent-event-set.php` and call it from
`bin/check-delivery-agent-events` alongside the existing
`validateEventSemantics()` call in the batch cross-validation loop, so it runs
against replayed batch events only.

Rule: batch-sourced `substantive_review_issued` and `repair_completed` events
must populate `elapsed_ms`; every such event is prospective because accepted
`main` contains no batch files. For each populated duration:

1. Required authoritative start type by completion type:
   `substantive_review_issued` → `review_started`;
   `repair_completed` → `repair_started`.
2. `causation_event_id` must be a non-empty string naming a known event of
   exactly that type. Missing, unknown, or wrong-typed causes fail closed.
3. Identity: cause and event must share `repository` and `pull_request`.
   `substantive_review_issued` additionally requires matching `head_sha`
   (a review is scoped to one commit); `repair_completed` does not (repair
   may legitimately cross head SHAs per `docs/specs/delivery-telemetry.md`).
4. Both the cause and the event must carry an explicit string `occurred_at`.
   A missing occurrence time on either side forbids a populated `elapsed_ms`
   — `recorded_at` (custody time) is never substituted, and no PR/queue time
   is inferred.
5. The computed duration (`end.occurred_at - start.occurred_at`, in whole
   milliseconds) must be non-negative and must equal the declared
   `elapsed_ms` exactly.

Any violation, including a missing duration on either terminal event type,
fails the whole ledger/batch validation. Historical rows — including null
durations and the one existing non-null legacy value at ledger line 10 (PR
2872, causation to a `substantive_review_issued` rather than a
`repair_started` event, because that workflow variant never recorded a separate
repair-start event) — remain unaffected: they live in the frozen v1 file, which
this rule never inspects.

## Boundaries

This does not change the v1 schema, append or rewrite the frozen ledger,
touch the projector, DevLake bridge/MCP, ingestion, dashboard, scheduler, or
any orchestration. It does not retroactively validate or repair historical
timing data. It does not introduce inference of occurrence time from custody
time, PR events, or queue state.

## Proof plan

Direct unit tests call `delivery_agent_elapsed_ms_errors()` against
constructed event maps: target terminal events with null `elapsed_ms` fail
closed; non-timing event types no-op; correct review and repair matches compute
cleanly (including a cross-head-SHA repair match); missing/unknown causation fails closed;
wrong start type fails for both review and repair; cross-PR and
cross-head-SHA (review only) identity mismatches fail; missing `occurred_at`
on either side fails; inverted timestamps fail; a mismatched declared value
fails. One end-to-end test drives the real `bin/check-delivery-agent-events`
CLI over a disposable git fixture with a batch file: a correctly matched pair
passes the gate, and a mutated `elapsed_ms` is refused with the computed-duration
message. A regression test re-asserts the real frozen ledger (with its legacy
non-null row) still passes unmodified.

## Delivery evidence

RED: 13 of 15 new tests errored on the undefined function
`delivery_agent_elapsed_ms_errors` (feature genuinely absent) and 1 failed
because the real gate accepted a mismatched batch `elapsed_ms` (exit 0 instead
of 1); the regression guard against the untouched frozen ledger was already
green. GREEN: all 15 new tests pass; the existing
`DeliveryAgentEventLedgerContractTest` suite (9 tests) and the full
delivery-agent Architecture slice (70 tests, 973 assertions) remain green;
`bin/check-delivery-agent-events` and its `--self-test` both PASS. The ledger
SHA-256 is unchanged at
`662c662ebb10ee49189540b74144abbfdf91dc2a9e0762cda84efcccc032a4ed` before and
after this change. Full Architecture suite (920 tests) and the fast preflight
roster (40 gates) were run clean prior to this commit.
