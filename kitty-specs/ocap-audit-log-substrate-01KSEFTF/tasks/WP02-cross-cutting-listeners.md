---
work_package_id: WP02
title: Cross-cutting audit listeners (entity lifecycle, API request, agent tool, MCP dispatch, broadcast) + NFR-001 chaos test
dependencies:
- WP01
requirement_refs:
- FR-006
- FR-007
- NFR-001
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-G
- T-H
- T-I
- T-J
- T-K
- T-L
history: []
authoritative_surface: packages/audit/src/Listener
execution_mode: code_change
owned_files:
- packages/audit/src/Listener/EntityLifecycleAuditListener.php
- packages/audit/src/Listener/ApiRequestAuditListener.php
- packages/audit/src/Listener/AgentToolAuditListener.php
- packages/audit/src/Listener/McpDispatchAuditListener.php
- packages/audit/src/Listener/BroadcastAuditListener.php
- packages/audit/tests/Unit/Listener/EntityLifecycleAuditListenerTest.php
- packages/audit/tests/Unit/Listener/ApiRequestAuditListenerTest.php
- packages/audit/tests/Unit/Listener/AgentToolAuditListenerTest.php
- packages/audit/tests/Unit/Listener/McpDispatchAuditListenerTest.php
- packages/audit/tests/Unit/Listener/BroadcastAuditListenerTest.php
- packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php
- packages/audit/tests/Integration/AuditChaosTest.php
tags:
- substrate
- ocap
- audit
- listeners
- best-effort
---

# WP02 — Cross-cutting audit listeners + NFR-001 chaos test

**Mission:** `ocap-audit-log-substrate-01KSEFTF`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01 (must be approved). WP02 may proceed in parallel with WP03.

## Pattern references — READ FIRST

- `packages/foundation/src/Kernel/EventListenerRegistrar.php` — how listeners are auto-discovered. Verify the exact discovery mechanism (attribute-based? FQCN registered in service provider? both?) before writing listener classes.
- `CLAUDE.md` §"Adding middleware" — for the `ApiRequestAuditListener` (which is a middleware).
- `CLAUDE.md` §Architecture-Gotchas §HTTP / auth — the `_account` request attribute rule (NEVER `account`).
- `CLAUDE.md` §Logging / side effects — best-effort wrap pattern is mandated.
- `packages/entity/src/Event/` — discover the canonical entity-lifecycle event class names (likely `EntityCreatedEvent`, `EntitySavedEvent`, `EntityDeletedEvent`; verify before subscribing).
- `packages/ai-agent/src/Event/` — discover the `AgentToolExecuted` event (verify FQCN).
- `packages/mcp/src/Event/` — discover the MCP JSON-RPC dispatch event.
- `packages/foundation/src/Http/Inbound/` — discover the broadcast publication event.

## Subtasks

### T-G — `EntityLifecycleAuditListener`

- `packages/audit/src/Listener/EntityLifecycleAuditListener.php`. Class-level `@api`.
- Constructor: `(AuditWriterInterface $writer, ?LoggerInterface $logger = null)`.
- Subscribes to the canonical entity lifecycle events. Records:
  - `entity.created` / `entity.saved` → `AuditEventKind::EntityWrite` (kind string `entity.write`).
  - `entity.deleted` → `AuditEventKind::EntityDelete`.
- Resolves `account_uid` via `$event->context['_account']?->id() ?? 0`. `subject_uri` = `/entities/{type}/{uuid}`. Attributes: `{entity_type, dirty_fields: [...]}` (best-effort — use `$entity->getDirty()` if defined; else `[]`).
- Whole body wrapped in `try { ... } catch (\Throwable $e) { $this->logger->warning('audit.listener_failed', ['listener' => self::class, 'error' => $e->getMessage()]); }`.
- Unit test: fires a fake `EntitySavedEvent`; assert one `AuditEvent` recorded with `event_kind = 'entity.write'`. Failing writer → no exception bubbles up.

### T-H — `ApiRequestAuditListener` (middleware)

- `packages/audit/src/Listener/ApiRequestAuditListener.php` `implements HttpMiddlewareInterface`. `#[AsMiddleware(priority: -50)]` so it runs AFTER `AuthorizationMiddleware` (lower priority = inner onion layer = runs later in the request-handling phase and earlier in response post-processing — verify the priority semantics in `packages/foundation/src/Http/Inbound/` to make sure -50 is later than authorization).
- Reads `_account` from `$request->attributes->get('_account')` (NEVER `'account'` per CLAUDE.md §Architecture-Gotchas).
- After delegating to the inner handler: inspect `$response->getStatusCode()`:
  - `200..299` on `GET` on an entity route (route name `api.{type}.{id}` or similar — verify pattern) → `entity.read`.
  - `200..299` on an export route (route name pattern matching `api.*.export` or `api.export.*`) → `entity.export`.
  - `403` → `access.denied` with attributes `{path, method}`.
- Anonymous traffic → `account_uid = 0` (AnonymousUser sentinel; NEVER null).
- Whole listener body wrapped in try-catch + best-effort log.
- Unit test: build a fake `Request` + fake handler returning `Response(200)`; assert one `entity.read` audit row.

### T-I — `AgentToolAuditListener`

- `packages/audit/src/Listener/AgentToolAuditListener.php`. Subscribes to `Waaseyaa\AI\Agent\Event\AgentToolExecuted` (verify FQCN).
- Records `AuditEventKind::AgentToolExecute` (kind string `agent.tool_execute`) with attributes `{tool_name, outcome, duration_ms}`. NEVER stores raw tool input/output (PII / classified-data exposure).
- This listener is the cross-listener seam with `per-record-ai-access-flagship-*` — that mission's per-record `AccessChecker` decisions inside `AgentToolInterface::execute()` will dispatch their own canonical event which this listener also subscribes to (extend the listener at that time, not now).

### T-J — `McpDispatchAuditListener`

- `packages/audit/src/Listener/McpDispatchAuditListener.php`. Subscribes to the MCP JSON-RPC dispatch event from `packages/mcp/`.
- Records `AuditEventKind::McpDispatch` with attributes `{method, params_hash: sha256(json_encode($params))}` — NEVER raw params.

### T-K — `BroadcastAuditListener`

- `packages/audit/src/Listener/BroadcastAuditListener.php`. Subscribes to the broadcast publication event from `packages/foundation/src/Http/Inbound/` (or wherever the broadcaster fires).
- Records `AuditEventKind::BroadcastPublish` with severity `notice` and attributes `{channel, message_id}` — NEVER the payload.

### T-L — NFR-001 chaos test

- `packages/audit/tests/Integration/AuditChaosTest.php` `#[CoversNothing]` (the integration test root, not under `packages/audit/tests/Unit/`).
- Boots full kernel via the standard integration test bootstrap (mirror `tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php`).
- Renames the audit table out from under the writer: `$db->executeStatement('ALTER TABLE audit_event RENAME TO audit_event_broken')`. (For SQLite, use `ALTER TABLE audit_event RENAME TO audit_event_broken`; portable.)
- Performs a `GET /api/entity/...` request (use an existing entity-route fixture from another phase, OR use a `node` entity created in the test setUp).
- Asserts response status code is 200.
- Asserts at least one `warning` log entry was emitted with the canonical message `audit.write_failed` (use a capturing logger in the kernel-services binding).
- Restores the table in tearDown so subsequent tests don't break.

### Contract test for entity-event names (T-G partner)

- `packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php` — asserts the FQCNs / event names this listener subscribes to actually exist in `packages/entity`. If `packages/entity` ever renames an event, this contract test fails immediately rather than the listener silently going dark. Implementation: `assertTrue(class_exists(Waaseyaa\Entity\Event\EntitySavedEvent::class))` (substitute real FQCNs).

## Verification gate (in lane worktree)

1. `composer install` (if not already).
2. `vendor/bin/phpunit packages/audit/tests/Unit/Listener/ packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php packages/audit/tests/Integration/AuditChaosTest.php`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings`.
5. Verify every listener body is wrapped in try-catch (grep): `rg -nE 'class .*AuditListener' packages/audit/src/Listener/` → for each match read the file and confirm `try {` opens at the top of the public handle method and `catch (\Throwable` closes it.

## Commit + handoff

- `feat(audit): EntityLifecycleAuditListener + contract test`
- `feat(audit): ApiRequestAuditListener middleware (post-Authorization)`
- `feat(audit): AgentToolAuditListener for ai-agent execute events`
- `feat(audit): McpDispatchAuditListener + BroadcastAuditListener`
- `test(audit): NFR-001 chaos test — broken audit table does not 500 primary requests`

```
spec-kitty agent tasks mark-status T-G T-H T-I T-J T-K T-L --status done --mission ocap-audit-log-substrate-01KSEFTF
spec-kitty agent tasks move-task WP02 --to for_review --mission ocap-audit-log-substrate-01KSEFTF --note "Five listeners + chaos test passing; best-effort verified"
```

## Report back with

1. Commit SHAs.
2. Output of `AuditChaosTest` (must show 200 status returned with audit table renamed; warning log captured).
3. The exact event FQCNs subscribed by each listener (so reviewer can verify no drift).
4. Confirmation grep: `rg -A 1 'class .+AuditListener' packages/audit/src/Listener/ | rg -c 'try \{'` (must equal 5).

## Activity Log
- 2026-05-25T05:20:29Z – unknown – Moved to for_review
- 2026-05-25T05:30:59Z – unknown – Opus review: cross-cutting listeners wired across entity/api/agent/mcp/broadcast; best-effort try-catch preserves primary request; NFR-001 chaos test included
- 2026-05-26T18:47:41Z – unknown – Done override: Sprint merge to main
