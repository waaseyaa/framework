# FW-A39-OPENAPI-CLEANUP-01: agent-run OpenAPI cleanup

Observed October 30, 2026. Base: `6871d0d5789ab2fde47bc38f4915eb3b4b1ef4a9`.
Forge mirror: [#3241](https://github.com/waaseyaa/framework/issues/3241).

## Scope and design

Resolve the nine reproduced Spectral warnings without changing runtime behavior,
lint configuration, dependencies or publication controls. The hand-maintained
document describes only agent-run streaming and approval decisions.

Runtime tracing found more than missing metadata:

- `AgentRouteServiceProvider::routes()` registers one approval POST at
  `/api/ai/agent/run/{id}/approve`. Both decisions require `call_id` and
  `decision`; `AgentRunController::approve()` returns 204, 400, 401, 403, 404
  or 409. There are no `/api/agent-run/{id}/{events,approve,deny}` routes.
- `BroadcastRouter` emits SSE frames on `/broadcast`. Message JSON is the
  complete `BroadcastStorage::poll()` row: `id`, `channel`, `event`, `data`,
  `created_at`. `AgentRunBroadcaster` adds `run_id` inside `data`.
- Current producers emit `run_started`, `run_completed`, `run_failed`,
  `run_cancelled`, and `approval_resolved`. The cancelled payload may contain
  either `cancelled_at` (handler/controller) or error fields (reaper).
- No runtime producer emits `pending_approval` or its documented
  `state/iteration/tool_calls/requires/timestamp` shape. The broadcaster accepts
  data without manufacturing those fields. Remove that fictional component
  and its exclusive helpers rather than attaching it to unrelated events.

Use relative server `/` for the consuming application's origin and the README's
project issue URL for contact. Use existing lower-camel-case operation naming.
Document the two real operations, retaining the four terminal/start event
variants and constraining only `approval_resolved` to its actual payload.
The SSE schema is a string; a named extension describes agent-run message JSON,
not the opening `connected`, `error`, or other-channel frames.

## Verification plan and review

1. Retain baseline lint output: nine warnings, zero errors, Spectral 6.17.0.
2. Add failing payload checks against real handler/controller emissions through
   the broadcaster and SQLite log. Cover representative other variants and
   malformed envelopes/approval decisions with Opis JSON Schema.
3. Repair only the document and its owning OpenAPI explanations. Run the
   supported checker with unchanged rules, focused runtime/contract tests,
   governed preflight and exact-head hosted CI.
4. Record immutable-candidate self-review under the routine documentation risk
   policy. No runtime authorization, execution or persistence code changes.
   Broaden review if implementation or approval policy changes become necessary.
5. Deliver a draft PR and retain detailed receipts outside the checkout.

## Deferred execution gap

Owner: Framework AI-agent maintainer. `AgentExecutor::hitlInteractive()` saves
awaiting-approval state and appends `EventType::ApprovalRequired` to the audit
log, but has no broadcaster dependency or SSE push. The design vocabulary also
promises iteration/tool/provider events without current producers. This cleanup
does not redefine those requirements as implemented or add emission. Follow-up
acceptance: choose the authoritative vocabulary, emit the approval-required
notification through the production worker before polling, verify actual call
identity/expiry and refusal paths, and independently review the execution change.

## Delivery boundary

Stop before merge, tagging, release, splitting, publication or deployment.
A35/A36 critical Git-toolchain findings, original provider-role review evidence,
separate release qualification, A30 published acceptance, J2, #3205 and wider S1
retain their existing owners and holds.
