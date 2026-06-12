---
work_package_id: WP04
title: Agent & MCP Event Provenance (ai-agent + ai-observability + mcp)
dependencies:
- WP01
requirement_refs:
- FR-005
- FR-007
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T017
- T018
- T019
agent: "claude:fable-5:implementer:implementer"
shell_pid: "26240"
history:
- date: '2026-06-12T03:32:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/mcp/
execution_mode: code_change
owned_files:
- packages/ai-agent/**
- packages/mcp/**
- packages/ai-observability/src/Event/AgentRunToolCallObserved.php
- packages/ai-observability/tests/**
tags: []
---

# WP04 — Agent & MCP Event Provenance (ai-agent + ai-observability + mcp)

**Mission**: revision-audit-provenance-01KTWY5V | **Tracks**: #1645
**Requirements**: FR-005 (event half), FR-007 | **Dependencies**: WP01
**Command**: `spec-kitty agent action implement WP04 --agent <name>`

## Objective

Make the two non-HTTP provenance producers emit what the audit listeners need: `AgentRunToolCallObserved` carries the initiator account (today the audit listener has nothing to read and hardcodes 0), `AgentExecutor` scopes the acting-account context around runs so entity saves made *by agent tools* — including queue-driven runs with no HTTP request — carry the initiator, and `McpEndpoint` finally fires the `waaseyaa.mcp.dispatch` event that `McpDispatchAuditListener` has subscribed to since it was written. All additive, all best-effort, fully independent of the #1635/#1636 MCP transport bugs.

The audit-side consumers (listener changes, audit rows) are another WP's scope — this WP produces the events and the context; its tests assert dispatch payloads, not audit rows.

## Context (read first)

- `research.md` D1 (writer surfaces 2–3), D5; `data-model.md` "MCP dispatch event" + holder-writers table; `contracts/audit-attribution.md` clauses 9, 16–20 (the producer-side clauses).
- `AgentRunToolCallObserved` (`packages/ai-observability/src/Event/AgentRunToolCallObserved.php:17-24`) carries only `runId`/`toolName`/`succeeded`. The dispatcher, `AgentExecutor::executeRun()`, HAS `$initiatorAccount` in scope at both dispatch sites (`packages/ai-agent/src/AgentExecutor.php:337`, `:367` — success and threw paths; re-locate with `rg -n "AgentRunToolCallObserved" packages/ai-agent/src/AgentExecutor.php`) and passes it into every tool execution (`:319`).
- `McpEndpoint` (`packages/mcp/src/McpEndpoint.php:29-99`) has NO event-dispatcher dependency. It is container-resolved via the controller string `Waaseyaa\Mcp\McpEndpoint::handle` (`packages/mcp/src/McpRouteProvider.php:17`, dispatched by `AppControllerRouter`); the auth-resolved account is available at `:61-76`; the JSON-RPC `match` on `method` is at `:92`.
- `McpDispatchAuditListener::EVENT_NAME = 'waaseyaa.mcp.dispatch'` (`packages/audit/src/Listener/McpDispatchAuditListener.php:30`); it duck-reads `method`/`params`/`accountUid`. mcp must NOT require audit at runtime — the name literal is duplicated by design and pinned by test (D5).
- WP01 delivered `Waaseyaa\Access\Context\AccountContextInterface` (+ `RequestAccountContext`), exposed via the kernel handler-container bindings and the services bus. `ai-agent` (L5) and `mcp` (L6) both already require `waaseyaa/access` — typed imports are layer-clean, no new runtime composer edges.
- Dispatcher type: `Symfony\Contracts\EventDispatcher\EventDispatcherInterface` — the interface providers already resolve (see `AuditServiceProvider.php:82`).
- Spec assumption (binding): FR-007 fires the event from the dispatch seam AS-IS and "must not depend on fixing" #1635/#1636/#1640. If the endpoint 500s for transport reasons, that is out of scope — do not touch routing/transport behavior.

## Requirement / contract map

| Deliverable | Requirement | audit-attribution.md clause(s) |
|---|---|---|
| `AgentRunToolCallObserved::$accountId` + executor population | FR-005 (producer half) | 9 |
| Executor context set/restore | FR-002 (writer 3, queue-safe) | research D1 |
| `McpDispatchEvent` + endpoint firing | FR-007 | 16–18, 20 |
| Endpoint context set/restore | FR-002 (writer 2) | research D1 |
| Best-effort dispatch | C-002 spirit (audit never breaks the primary path) | 19 |
| Event-name pin test | FR-007 (double-defined literal risk) | 18 |

## Out of scope for this WP (do not touch)

- `packages/audit/**` — `AgentToolAuditListener` / `McpDispatchAuditListener` changes are the audit WP's; your events must satisfy the contract payload shapes so its listeners can consume them, nothing more.
- The MCP transport bugs #1635 (500s), #1636 (tools/list), #1640 (OAuth) — explicitly out of mission scope; the event fires from the seam as-is.
- `packages/access/**`, kernel files — the context exists; you only accept it as an optional ctor param and resolve it at your construction sites.
- `AgentToolRegistryBridge` — rejected firing point (D5 alternative b); leave it alone.

## Subtasks

### T017 — Agent run provenance

**Files**: `packages/ai-observability/src/Event/AgentRunToolCallObserved.php`, `packages/ai-agent/src/AgentExecutor.php`

1. **Event**: additive trailing constructor param `public readonly ?int $accountId = null` on `AgentRunToolCallObserved`. Existing constructions compile unchanged. Docblock: the initiator account of the agent run (null = no known initiator); three-state semantics per the mission's data model — never default to 0.
2. **AgentExecutor — populate at BOTH dispatch sites** (`:337` success path, `:367` threw path): pass `accountId: $initiatorAccount?->id()` (check the actual type/nullability of `$initiatorAccount` in scope — cast to `?int` explicitly if `id()` is not int-typed). Both sites, or the audit row for failed tool calls regresses to null while successes carry the account.
3. **AgentExecutor — context scoping** (research D1 writer 3, queue-safe): add an optional trailing constructor param `?AccountContextInterface $accountContext = null`. In `executeRun()`, around the run body:
   ```php
   $previous = $this->accountContext?->current();
   $this->accountContext?->set($initiatorAccount);
   try {
       // existing run body
   } finally {
       $this->accountContext?->set($previous);
   }
   ```
   Set/restore in `finally` — a thrown run must not leak the initiator into the next job on the same worker (plan premortem "stale actor in long-lived processes"). Wire the param wherever `AgentExecutor` is constructed in this package's providers/factories (`rg -n "new AgentExecutor" packages/` — resolve the context the same way the construction site resolves other optional services; absent resolution → null is acceptable and behavior-identical to today).
4. No change to tool execution, run persistence, or observability payloads beyond the new field.

### T018 — MCP dispatch event

**Files**: `packages/mcp/src/Event/McpDispatchEvent.php` (NEW), `packages/mcp/src/McpEndpoint.php`

1. **`McpDispatchEvent`** (NEW, namespace `Waaseyaa\Mcp\Event`):
   ```php
   final class McpDispatchEvent
   {
       public const NAME = 'waaseyaa.mcp.dispatch';

       public function __construct(
           public readonly string $method,
           public readonly array $params,
           public readonly ?int $accountUid,
       ) {}
   }
   ```
   Extend the same event base the framework's string-named events use if one is conventional in this codebase (check how other `dispatch($event, NAME)` payloads are shaped — e.g. the broadcast events); otherwise a plain object is fine for Symfony's dispatcher. Docblock: fired once per authenticated, well-formed JSON-RPC request; params are RAW here — the audit listener hashes them and never logs the payload (privacy property lives in the listener, contract clause 17).
2. **`McpEndpoint`**: optional trailing constructor param `?EventDispatcherInterface $dispatcher = null` (Symfony contracts). Default-null keeps the endpoint constructible even if the container cannot supply a dispatcher (event silently not fired — best-effort audit semantics, plan premortem). Also add `?AccountContextInterface $accountContext = null`.
3. **Firing point** (D5, contract clause 16): in `dispatch()`, AFTER `authenticate()` succeeds and the JSON-RPC envelope parses with a known `method` key, immediately BEFORE the `match` (`:92`). Exactly once per request — covering `tools/call` (FR-007) and every other method per the listener's documented contract. Unauthenticated (401) and parse-error requests fire nothing.
   ```php
   try {
       $this->dispatcher?->dispatch(
           new McpDispatchEvent($method, $params, $authenticated->id()),
           McpDispatchEvent::NAME,
       );
   } catch (\Throwable) {
       // best-effort: audit must never alter the JSON-RPC response (clause 19)
   }
   ```
4. **Context scoping** (research D1 writer 2): in the same `dispatch()` block, set the context to the bearer-auth account and restore the prior value in `finally` (the MCP account deliberately differs from any session account per the endpoint's own docblock):
   ```php
   $previous = $this->accountContext?->current();
   $this->accountContext?->set($authenticated);
   try { /* existing method routing */ } finally { $this->accountContext?->set($previous); }
   ```
5. **Container resolution check** (plan premortem): the endpoint is resolved from the controller string by `AppControllerRouter`. Verify the resolver actually injects the dispatcher and context in a booted kernel — the handler container's reflection autowiring resolves constructor named types via providers + kernel bindings (the context is bound there since the context WP). If the resolution path cannot supply one of them, the default-null keeps everything working; state what you found in completion notes.

### T019 — Unit tests

**Files**: `packages/ai-observability/tests/Unit/` (extend the event test if present), `packages/ai-agent/tests/Unit/` (extend executor coverage — locate with `rg -l "AgentExecutor" packages/ai-agent/tests/`), `packages/mcp/tests/Unit/McpEndpointDispatchEventTest.php` (NEW)

ai-observability / ai-agent:

1. `AgentRunToolCallObserved` constructed without `accountId` → null (additive compat); with `accountId: 7` → 7.
2. Executor run with initiator account N → BOTH observed-tool-call dispatches (success and a run whose tool throws) carry `accountId = N`.
3. Executor with no initiator → events carry null.
4. Context scoping: stub `AccountContextInterface` recording set() calls → set to initiator at run start, restored to the previous value after — including when the run body throws (the `finally` pin). Constructed without the context param → no error.

mcp (`McpEndpointDispatchEventTest`, NEW — mirror the existing `packages/mcp/tests/Unit/` endpoint test bootstrap for auth/request fixtures):

5. Authenticated, well-formed `tools/list` (or `tools/call`) request → exactly ONE `McpDispatchEvent` dispatched, with the correct `method`, `params`, and `accountUid` = the bearer account id.
6. 401 (failed auth) → zero events. Parse-error body → zero events (contract clause 16).
7. Dispatcher that throws → the JSON-RPC response is byte-identical to the no-dispatcher response (clause 19 best-effort pin).
8. Constructed with `dispatcher: null` (legacy construction) → no error, no event, response unchanged.
9. Context set/restore: stub context → set to the authenticated account during dispatch, restored after (including on a handler exception).
10. **Event-name pin** (D5, contract clause 18): assert `McpDispatchEvent::NAME === \Waaseyaa\Audit\Listener\McpDispatchAuditListener::EVENT_NAME`. This requires `waaseyaa/audit` in `packages/mcp/composer.json` `require-dev` (path repo + constraint) — a DOWNWARD L6→L1 edge, legal even at runtime, dev-only here. CP-NEW: constraint literal must equal `^<current tag>` from `git describe --tags --abbrev=0 --match='v*.*.*'` (planning-time value `^0.1.0-alpha.203` — re-verify when you run). If the monorepo root autoloads audit for package test runs anyway, check how other cross-package dev edges are declared (`rg -A4 'require-dev' packages/*/composer.json | rg waaseyaa`) and follow the established convention.

**Validation**:

```bash
./vendor/bin/phpunit packages/ai-observability/tests/ --no-progress
./vendor/bin/phpunit packages/ai-agent/tests/ --no-progress
./vendor/bin/phpunit packages/mcp/tests/ --no-progress
composer phpstan
composer cs-check
composer check-composer-policy     # if composer.json was touched (CP-NEW literal)
bin/check-package-layers           # no new runtime edges; dev edge is downward
bin/check-dead-code                # McpDispatchEvent is dispatched (wired) — no @api needed
```

## Edge cases & risks (from the plan premortem)

- **Stale actor on queue workers is THE failure mode this WP guards**: an agent run on a long-lived worker that sets the context and never restores it bleeds the initiator into every subsequent save on that worker. The `finally` restore (to the PREVIOUS value, not blindly to null — nested scopes must unwind correctly) is non-negotiable; both throw-path tests pin it.
- **Double-defined event name by design**: `'waaseyaa.mcp.dispatch'` exists in both mcp (`McpDispatchEvent::NAME`) and audit (`McpDispatchAuditListener::EVENT_NAME`) because mcp must not require audit at runtime. The pin test is the ONLY thing preventing silent divergence — it must compare the two class constants, not literals.
- **Container resolution of `McpEndpoint`**: the endpoint is resolved from a controller string; if the resolution path cannot autowire the optional params, both default to null and the endpoint behaves exactly as today (constructible, no event, no context). This is the designed degradation — but T018 step 5 requires you to VERIFY what a booted kernel actually injects and record it; "default-null happened silently in production" is the premortem scenario.
- **Params privacy**: the event carries RAW params; the privacy property (SHA-256 hash, never the payload) lives in the audit listener. Do not pre-hash in the endpoint — that would break the listener's documented contract and double-hash.
- **`initialize`/`tools/list` also fire**: the listener's documented contract is "each JSON-RPC method invocation" — firing for non-tools/call methods is intended (D5 rejected the narrower alternative). Don't filter by method.
- **Additive ctor params only**: every new constructor param in this WP is optional-trailing with a null default — existing constructions (tests, fixtures, providers) must compile unchanged. If a construction site needs editing to PASS the new values, that is wiring (fine); if it needs editing to COMPILE, you broke additivity.

## Definition of Done

- [ ] All three subtasks complete; the three package suites green; existing tests unmodified.
- [ ] `accountId` carried on BOTH executor dispatch sites (success + threw), pinned by tests.
- [ ] Context set/restore proven for both writers (executor + endpoint), including the throw paths (`finally`).
- [ ] Event fires exactly once per authenticated well-formed request; 401/parse-error fire nothing; dispatcher failure leaves the RPC response unchanged.
- [ ] `McpDispatchEvent::NAME === McpDispatchAuditListener::EVENT_NAME` pinned by a compiling, running test.
- [ ] No transport/routing behavior change in `McpEndpoint` beyond the event + context (independence from #1635/#1636 preserved).
- [ ] Gates green: phpstan, cs-check, layers, dead-code, composer policy (CP-NEW literal verified against `git describe` at implementation time).
- [ ] No changes outside `owned_files` (in particular: NOT `packages/audit/src/**` — the listener side belongs to another WP).

## Reviewer guidance

- **The set/restore-in-`finally` pins are the highest-value assertions**: a leaked initiator on a queue worker mis-attributes every subsequent save on that worker — verify both throw-path tests actually throw from inside the scoped region and assert the restored value (not just "null after").
- The threw-path dispatch site (`:367`) is the easy miss — confirm the test drives a tool that throws and still asserts `accountId` on the observed event.
- Event-name pin must reference BOTH constants by FQCN — reject a test comparing two string literals (it would survive a rename and defeat its purpose).
- Exactly-once: the test should count dispatched events, not just assert the first one.
- Confirm the firing point is pre-`match`, post-auth/post-parse — an event inside `handleToolsCall()` only would narrow the listener's documented contract ("each JSON-RPC method invocation").
- Watch for scope creep into the #1635/#1636 transport bugs or OAuth (#1640) — any change to status codes, routing, or response shapes is out of mission scope.

## Endpoint test-case summary (T019 cases 5–9, as a matrix)

| Request | Auth | Body | Events dispatched | Response |
|---|---|---|---|---|
| `tools/list`, valid bearer | pass | well-formed JSON-RPC | exactly 1 — `method='tools/list'`, `accountUid=N` | normal |
| `tools/call`, valid bearer | pass | well-formed | exactly 1 — `method='tools/call'`, params carried raw | normal |
| any, bad/missing token | fail (401) | — | 0 | 401 unchanged |
| valid bearer | pass | malformed JSON / missing `method` | 0 | parse-error response unchanged |
| valid bearer, dispatcher throws | pass | well-formed | dispatch attempted, swallowed | byte-identical to no-dispatcher run |
| valid bearer, `dispatcher: null` | pass | well-formed | 0 (no dispatcher) | unchanged — legacy construction |

Context assertions ride the same fixtures: set to the bearer account during routing, restored after (including when the routed handler throws).

## Completion notes template (fill in before requesting review)

- Booted-kernel resolution check (T018 step 5): what `AppControllerRouter`'s resolution actually injected for `dispatcher` / `accountContext` (resolved instance vs default-null), and where you verified it.
- Both executor dispatch sites: line numbers after your edit + the test names pinning success and threw paths.
- CP-NEW literal used for the mcp require-dev edge (if added): `^___` from `git describe --tags --abbrev=0 --match='v*.*.*'`; or the convention you followed instead.
- Confirmation that no `packages/audit/**` file changed (`git status --short packages/audit` empty).
- Any construction site of `AgentExecutor` you wired the context into, and how it resolves the instance.

## Activity Log

- 2026-06-12T03:32:00Z – spec-kitty.tasks – created
- 2026-06-12T05:01:44Z – claude:fable-5:implementer:implementer – shell_pid=26240 – Started implementation via action command
