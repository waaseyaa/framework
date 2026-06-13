---
work_package_id: WP01
title: 'Backend: MCP admin read contracts, adapters, binding, routes, kernel-boot test (M5C)'
dependencies: []
requirement_refs:
- C-001
- C-002
- C-003
- C-004
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-008
- NFR-001
- NFR-002
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-mcp-endpoint-admin-m5c-01KSEFTB
base_commit: 7f46dfa417b811743bb8c92b812bb5e30317e4db
created_at: '2026-05-25T05:38:22.508049+00:00'
subtasks: []
phase: ''
assignee: ''
agent: "claude"
shell_pid: "73410"
history: []
authoritative_surface: packages/api/src/McpAdmin
execution_mode: code_change
owned_files:
- packages/api/src/McpAdmin/ToolRegistryReadModelInterface.php
- packages/api/src/McpAdmin/ServerConfigReadModelInterface.php
- packages/api/src/McpAdmin/ToolRegistryRow.php
- packages/api/src/McpAdmin/ToolDetail.php
- packages/api/src/McpAdmin/RecentInvocation.php
- packages/api/src/McpAdmin/ServerConfigSnapshot.php
- packages/api/src/McpAdmin/RegisteredClient.php
- packages/api/src/Controller/McpAdminController.php
- packages/api/src/Http/Router/McpAdminApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/composer.json
- packages/api/tests/Unit/Controller/McpAdminControllerTest.php
- packages/api/tests/Unit/Http/Router/McpAdminApiRouterTest.php
- packages/mcp/src/Admin/ToolRegistryReadModel.php
- packages/mcp/src/Admin/ServerConfigReadModel.php
- packages/mcp/src/McpServiceProvider.php
- packages/mcp/tests/Unit/Admin/ToolRegistryReadModelTest.php
- packages/mcp/tests/Unit/Admin/ServerConfigReadModelTest.php
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- tests/Integration/PhaseMcpAdmin/McpAdminEndpointTest.php
tags: []
---

# WP01 — Backend: MCP admin read contracts, adapters, binding, routes, kernel-boot test (M5C)

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` (#1415, audit C-L6-01)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Blocked by:** `per-record-ai-access-flagship-01KSEFT5/WP02` MUST be merged before this WP starts — the MCP serializer `FieldAccessPolicy` wiring is the runtime hook the controller delegates to for `recentInvocations` redaction.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/mcp-endpoint-admin-m5c-01KSEFTB-lane-a
```
(Exact path is printed by `spec-kitty agent action implement WP01`.) Do NOT edit the main worktree.

## THE pattern to mirror (read these before writing anything)

This mission is a **cross-layer** read surface: `packages/mcp` is **Layer 6**, `packages/api` is **Layer 4**. api may NOT import a higher layer. The shipped pattern is **CodifiedContext** (M5A used it for `AiObservabilityReadModelInterface`):

- READ `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + M5A DTOs — the api-owned read contract + value DTOs.
- READ `packages/api/src/Controller/AiObservabilityController.php` — nullable dependency, returns empty-shape when null.
- READ `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php` — adapter in higher layer implementing the api interface.
- READ `packages/api/src/Http/Router/AiObservabilityApiRouter.php` + the `AiObservabilityApiRouter`/`resolveOptional` block in `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — the router + wiring shape.
- READ `packages/mcp/src/McpServiceProvider.php` — the M3 binding shape (`BearerTokenAuth(tokens: [])`, `AgentToolRegistryBridge`, `ToolRegistryInterface`).
- READ `packages/mcp/src/McpEndpoint.php` + `McpController.php` — dispatch semantics; `protocolVersion = '2025-03-26'` lives in `initialize`.
- READ `packages/ai-agent/src/Tool/AbstractAgentTool.php` — `requireCapability` and how `McpToolDefinition` exposes its capability metadata.
- READ the M-A5 flagship WP02 implementation — how `EntityAccessHandler` is wired into the MCP serializer for `FieldAccessPolicy`-aware response shaping.

### Data shape (already shipped — you only READ it)

- `Waaseyaa\AI\Tools\ToolRegistryInterface::getTools(): array<McpToolDefinition>` — each definition exposes `name`, `summary`, `description`, `inputSchema`, capability metadata.
- `Waaseyaa\Mcp\Auth\McpAuthInterface` — concrete `BearerTokenAuth` carries `tokens: array<string>` of registered client tokens. The adapter MUST hash, never return plaintext.
- M5A's `Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface` — already shipped; the mcp adapter uses it (downward import = allowed) to read recent invocations.

## Subtasks

**T001 — api read contract (interfaces + DTOs)**
- `packages/api/src/McpAdmin/ToolRegistryReadModelInterface.php` — `@api`. `listTools(): array<ToolRegistryRow>`, `findTool(string $name): ?ToolDetail`.
- `packages/api/src/McpAdmin/ServerConfigReadModelInterface.php` — `@api`. `serverConfig(): ServerConfigSnapshot`.
- DTOs: `ToolRegistryRow`, `ToolDetail`, `RecentInvocation`, `ServerConfigSnapshot`, `RegisteredClient`. All readonly. All `@api`.

**T002 — api controller + router + SP wiring + composer.json**
- `packages/api/src/Controller/McpAdminController.php` — three actions; constructor `(?ToolRegistryReadModelInterface $registry = null, ?ServerConfigReadModelInterface $config = null, ?EntityAccessHandler $accessHandler = null, ?AccountInterface $account = null)`. camelCase. Empty-shape on null deps. `tool($name)`: URL-decode `$name` once before lookup. `recentInvocations` rows passed through `EntityAccessHandler` when both handler + account present.
- `packages/api/src/Http/Router/McpAdminApiRouter.php` — `supports()` matches `_controller` in the three names; dispatch on `_controller` to controller methods.
- `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — extend with `resolveOptional` for the two interfaces; register the admin router when either binds.
- `packages/api/composer.json` — add `"waaseyaa/mcp": "^<current-tag>"` to `require-dev` (use the current tag literal — `bin/sync-internal-versions` will refresh on release-cut) + path repo `{"type": "path", "url": "../mcp", "options": {"versions": {"waaseyaa/mcp": "*"}}}` if absent. Re-run `composer install` from the api package to refresh the lock.

**T003 — mcp adapters + SP binding + foundation routes**
- `packages/mcp/src/Admin/ToolRegistryReadModel.php` — implements api registry interface. Constructor takes `ToolRegistryInterface` + optional `AiObservabilityReadModelInterface` (resolveOptional). `listTools()` iterates `getTools()`. `findTool($name)` adds `inputSchema` + `description` + recent invocations via the M5A read model (filtered by `label LIKE 'mcp.%'` + `attributes.tool = $name`, limit 25, order by `started_at DESC`).
- `packages/mcp/src/Admin/ServerConfigReadModel.php` — implements api server-config interface. Constructor takes `McpAuthInterface`. `serverConfig()` returns transport + protocol version (`'2025-03-26'`) + server capabilities + registered clients with `tokenFingerprint = substr(hash('sha256', $token), 0, 16)`. Plaintext token MUST never appear in the returned snapshot.
- `packages/mcp/src/McpServiceProvider.php` — add two bindings (`ToolRegistryReadModelInterface` → `ToolRegistryReadModel`, `ServerConfigReadModelInterface` → `ServerConfigReadModel`).
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three new routes; `_role: admin`; string FQCN.

**T004 — tests (unit + FR-008 dead-code guard)**
- `packages/api/tests/Unit/Controller/McpAdminControllerTest.php` — anonymous-class fakes; assert mapped camelCase payloads; null deps → zeroed empty shapes; URL-encoded tool name with `.` is decoded once. With handler+account, `recentInvocations` rows that fail field access get `_redacted: true` markers.
- `packages/api/tests/Unit/Http/Router/McpAdminApiRouterTest.php` — `supports()` true/false; dispatch routes to controller methods; unknown action → 404.
- `packages/mcp/tests/Unit/Admin/ToolRegistryReadModelTest.php` — fake `ToolRegistryInterface` with ≥2 fixture tools; assert list + detail shape; assert recent-invocations integration uses the api `AiObservabilityReadModelInterface` (mock returns 25 fixture rows).
- `packages/mcp/tests/Unit/Admin/ServerConfigReadModelTest.php` — fake `McpAuthInterface` with 3 tokens; assert `tokenFingerprint` is 16-char hex; **assert NO plaintext token appears in the serialised response** (`assertStringNotContainsString($plaintext, json_encode($snapshot, JSON_THROW_ON_ERROR))`).
- `tests/Integration/PhaseMcpAdmin/McpAdminEndpointTest.php` (`#[CoversNothing]`) — boot full kernel, register ≥2 MCP tools (one with capabilities, one without) + ≥1 registered client + seed ≥3 trace invocations via `TraceRecorder`; hit all three endpoints as admin → assert shapes; non-admin → 403 on all three; assert NO plaintext token in `serverConfig` response. **MUST fail if either SP binding from T003 is removed** — verify by hand and report.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/mcp/tests/ tests/Integration/PhaseMcpAdmin/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. Confirm: `rg -n 'Waaseyaa\\\\(Mcp|AI)' packages/api/src` returns **nothing** (C-002).
6. Confirm by hand: removing the `ToolRegistryReadModelInterface` binding OR the `ServerConfigReadModelInterface` binding from `McpServiceProvider` causes `McpAdminEndpointTest` to fail. Note this in the WP report.
7. Confirm by hand: the integration test response body contains zero 64-character hex strings (no plaintext tokens).

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(api): MCP admin read contracts + DTOs + controller + router (#1415)`
  - `feat(mcp): tool registry + server config read models + SP bindings (#1415)`
  - `feat(foundation): /api/mcp admin routes (#1415)`
  - `test(mcp): kernel-boot MCP admin integration test + no-plaintext-token assertion (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission mcp-endpoint-admin-m5c-01KSEFTB
  spec-kitty agent tasks move-task WP01 --to for_review --mission mcp-endpoint-admin-m5c-01KSEFTB --note "M5C backend ready; FR-008 kernel-boot test verified to fail without either binding; no plaintext token leak verified by hand"
  ```

## Report back with

- Confirmation that FR-008 fails when either binding is removed.
- Confirmation that no plaintext token leaks in the integration response.
- Confirmation that `recentInvocations` field redaction works against M-A5 fixtures.
- Commit SHAs + final gate output.

## Activity Log

(implementer appends here)
- 2026-05-25T05:38:24Z – claude – shell_pid=73410 – Assigned agent via action command
- 2026-05-25T05:58:39Z – claude – shell_pid=73410 – Moved to for_review
- 2026-05-25T05:59:26Z – claude – shell_pid=73410 – Opus review: backend clean; 2 commits in lane; NFR-003 plaintext-token leak gate enforced via fingerprint-only response; bug fixed in ServerConfigReadModel where AccountInterface object was being hashed instead of token string; 573 tests pass; field-access gating on recentInvocations delegates to M-A5 EntityAccessHandler
- 2026-05-26T19:02:13Z – claude – shell_pid=73410 – Done override: Sprint merge to main
