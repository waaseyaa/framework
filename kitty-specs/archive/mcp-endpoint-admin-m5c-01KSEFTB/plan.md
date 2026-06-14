# Implementation Plan: MCP Endpoint Admin (M5C)

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` — see `spec.md`.
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — CodifiedContext cross-layer L6→L4. M4B `QueueController`/`QueueAdminApiRouter`. M4A-5 `WorkflowGuardsApiRouter`.
**Two WPs, sequential:** WP02 (frontend) depends on WP01 (backend) — the pages need the endpoint shape locked.
**Cross-mission dependency:** WP01 is `blocked_by` `per-record-ai-access-flagship-01KSEFT5/WP02` (the MCP serializer `FieldAccessPolicy` wiring is the runtime hook the controller delegates to for `recentInvocations` redaction).

## WP01 — Backend: admin read contracts, adapters, binding, routes, kernel-boot test

### api (L4) — read contract, DTOs, controller, router

- READ FIRST: M5A's shipped surface — `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + DTOs, `packages/api/src/Controller/AiObservabilityController.php`, `packages/api/src/Http/Router/AiObservabilityApiRouter.php`, and `httpDomainRouters()` in `ApiServiceProvider.php`. Mirror EXACTLY.
- READ M3-shipped surface: `packages/mcp/src/McpServiceProvider.php` (`BearerTokenAuth(tokens: [])` binding + `AgentToolRegistryBridge` binding), `packages/mcp/src/McpEndpoint.php` + `McpController.php` (the dispatch shape), `packages/ai-agent/src/Tool/AbstractAgentTool.php` (`requireCapability` semantics).
- READ per-record-AI-access flagship: confirm WP02 has wired `EntityAccessHandler` into the MCP serializer pipeline — the controller delegates field redaction to that handler.
- `packages/api/src/McpAdmin/ToolRegistryReadModelInterface.php` — `@api`. `listTools(): array<ToolRegistryRow>`, `findTool(string $name): ?ToolDetail`.
- `packages/api/src/McpAdmin/ServerConfigReadModelInterface.php` — `@api`. `serverConfig(): ServerConfigSnapshot`.
- `packages/api/src/McpAdmin/ToolRegistryRow.php` + `ToolDetail.php` + `RecentInvocation.php` + `ServerConfigSnapshot.php` + `RegisteredClient.php` — readonly DTOs, all `@api`.
- `packages/api/src/Controller/McpAdminController.php` — constructor `(?ToolRegistryReadModelInterface $registry = null, ?ServerConfigReadModelInterface $config = null, ?EntityAccessHandler $accessHandler = null, ?AccountInterface $account = null)`. Three actions. Empty-shape on null deps. camelCase keys. `recentInvocations` rows passed through `EntityAccessHandler::serialize` when both handler + account present (else default fields, no redaction).
- `packages/api/src/Http/Router/McpAdminApiRouter.php` — `supports()` matches `_controller` in `{mcp.admin.tools, mcp.admin.tool.show, mcp.admin.server-config}`; dispatch on `_controller` to controller methods.
- `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — extend with `resolveOptional` for the two interfaces; register the admin router when either binds.
- `packages/api/composer.json` — add `"waaseyaa/mcp": "^<current-tag>"` to `require-dev` (literal current tag per CP-NEW); add path repo `"path": "../mcp"` if not already present.

### mcp (L6) — adapters + binding

- READ FIRST: `packages/mcp/src/McpServiceProvider.php` (M3 WP02-03 binding shape), `packages/mcp/src/McpController.php` + `McpEndpoint.php` (dispatch + initialize semantics, `protocolVersion = '2025-03-26'`), `packages/ai-agent/src/Tool/AbstractAgentTool.php` (capability semantics).
- `packages/mcp/src/Admin/ToolRegistryReadModel.php` — implements the api registry interface. Reads `Waaseyaa\AI\Tools\ToolRegistryInterface` from the kernel-services bus (NOT a hard `use`; resolve at construction). Iterates `getTools()`; emits `ToolRegistryRow` per `McpToolDefinition`. `findTool($name)` extends with `inputSchema`, `description`, plus reads recent invocations via `Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface` (M5A's shipped api contract — this mcp adapter `use`s the API-layer interface, which is downward, allowed).
- `packages/mcp/src/Admin/ServerConfigReadModel.php` — implements the api server-config interface. Reads `McpAuthInterface` for registered clients. `tokenFingerprint = substr(hash('sha256', $token), 0, 16)`. Plaintext token MUST never leave the adapter — assertion: any test asserting the response body contains a plaintext token MUST fail.
- `packages/mcp/src/McpServiceProvider.php` — add two bindings: `ToolRegistryReadModelInterface` → `ToolRegistryReadModel`, `ServerConfigReadModelInterface` → `ServerConfigReadModel`. Both constructors take only existing M3 bindings + the M5A `AiObservabilityReadModelInterface` (resolved via `resolveOptional` so the bindings still register when ai-observability is absent — recent invocations just come back as `[]`).

### foundation (L0) — routes

- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes after the M5A observability block:
  - `api.mcp.admin.tools` → `GET /api/mcp/tools`, `_role: admin`, controller `'Waaseyaa\\Api\\Controller\\McpAdminController'`, action `tools`.
  - `api.mcp.admin.tool.show` → `GET /api/mcp/tools/{name}`, `_role: admin`, action `tool`.
  - `api.mcp.admin.server-config` → `GET /api/mcp/server-config`, `_role: admin`, action `serverConfig`.

### root integration (FR-008 — dead-code guard)

- `tests/Integration/PhaseMcpAdmin/McpAdminEndpointTest.php` — boot full kernel, register ≥2 MCP tools (one with capabilities, one without) via `McpServiceProvider` test extension; register ≥1 client via `BearerTokenAuth`; seed ≥3 trace invocations across two tools via M5A's `TraceRecorder`; hit all three endpoints as admin, assert shapes; hit as non-admin, assert 403; assert NO plaintext token leak in `serverConfig` response. `#[CoversNothing]`. **MUST fail if either SP binding is removed** — verify by hand.

## WP02 — Frontend: composables, registry page, detail page, server-config page, nav, i18n, docs

- READ FIRST: `packages/admin/app/composables/useAiObservability.ts` (M5A), `packages/admin/app/pages/ai/observability.vue` (M5A), the M5A "AI" nav-group registration. Mirror the same pattern for the "MCP" group.
- `app/composables/useMcpTools.ts` — TS types match payload; `{rows, loading, error, fetchTools()}`. `useApi`.
- `app/composables/useMcpTool.ts` — `{tool, loading, error, fetchTool(name)}`. URL-encode `name` once before passing to `useApi`.
- `app/composables/useMcpServerConfig.ts` — `{config, loading, error, fetchConfig()}`.
- `app/pages/mcp/tools/index.vue` — title, list table, empty + loading + error states.
- `app/pages/mcp/tools/[name].vue` — header card, input-schema viewer, recent-invocations table, link to "Server config".
- `app/pages/mcp/server-config.vue` — transport, protocol version, capabilities list, clients table.
- `app/components/mcp/ToolRegistryTable.vue` — props `{rows}`. Capability chips column.
- `app/components/mcp/InputSchemaViewer.vue` — collapsible JSON tree (read-only). Use `<details>` for nodes.
- `app/components/mcp/RecentInvocationsTable.vue` — props `{rows}`. Each `traceUuid` row links to `/ai/observability/runs/{uuid}` (M5B detail page) — if M5B hasn't shipped yet, render plain text.
- Nav: register a new "MCP" group with "Tools" → `/mcp/tools` and "Server config" → `/mcp/server-config`. Mirror M5A's "AI" group exactly (read the M5A WP02 nav commit).
- `app/i18n/en.json` — all `mcp_*` keys listed in spec.
- `tests/unit/composables/useMcpTools.test.ts` — vitest: fetch success / failure.
- `tests/unit/composables/useMcpTool.test.ts` — vitest: fetch success populates tool; name with `.` is URL-encoded once.
- `tests/unit/composables/useMcpServerConfig.test.ts` — vitest: fetch success populates config; assert no plaintext token field present in TS types.
- `e2e/mcp-admin.spec.ts` — Playwright smoke (run deferred): visit registry, detail, server-config; assert pages render.
- `docs/specs/mcp-endpoint.md` — stamp + "Admin surface" section.
- `docs/specs/admin-spa.md` — stamp + "MCP admin" section.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: MCP endpoint admin — tool registry browser, per-tool detail with recent invocations, and server config viewer (read-only). (#1415)`

## Verification gate (each WP, in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/mcp/tests/ tests/Integration/PhaseMcpAdmin/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `rg -n 'Waaseyaa\\\\(Mcp|AI)' packages/api/src` returns **nothing** (C-002).
6. WP02 only: `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`.

## Reviewer focus

- **Dead-code guard.** Confirm FR-008 integration test fails when either `McpServiceProvider` binding is removed.
- **Plaintext token leak.** Grep the integration test JSON response for any 64-character hex; assert only 16-char fingerprints appear.
- **Layer hygiene.** `rg 'Waaseyaa\\(Mcp|AI)\\' packages/api/src` MUST return zero hits.
- **Field redaction.** Recent-invocations rows from a trace the requesting admin lacks per-record access to must show `_redacted: true`, not be omitted. Reviewer verifies against M-A5 fixtures.
- **Empty-shape parity.** Controller returns zeroed empty payloads when deps null. Mirror M5A's `AiObservabilityController`.
- **camelCase JSON.** All response keys camelCase to match TS types. M5A precedent.
