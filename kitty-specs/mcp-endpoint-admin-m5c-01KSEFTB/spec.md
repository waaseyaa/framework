# MCP Endpoint Admin — Tool Registry + Server Config (Read-Only) (M5 Phase 2, sub-mission C)

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB`
**Umbrella issue:** #1415 (M5 — admin observability cluster)
**Audit row:** C-L6-01 (MCP endpoint visibility)
**Mission type:** software-dev
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — cross-layer L6→L4 via CodifiedContext; M4B `QueueController`/`QueueAdminApiRouter`; M4A-5 `WorkflowGuardsApiRouter`.

## Why

The MCP endpoint (`/mcp` JSON-RPC, served by `packages/mcp`) is the framework's outbound interface to external AI agents (Claude Code, other MCP-aware clients). It carries the largest blast-radius of any HTTP surface: every registered tool is a capability-gated callable that an authorised agent can invoke against entities, files, configuration, and the AI pipeline. Today, operators have no admin surface to inspect *which* tools are registered, *what* capabilities each requires, *who* the registered MCP clients are, or *which* tools have been invoked recently. Without that visibility, audit and incident response on the MCP side are blind.

The data is already on disk: M3 (`bimaaji-mcp-bridge-01KS5VS8`) shipped `ToolRegistryInterface` (a `Waaseyaa\AI\Tools\ToolRegistryInterface` instance that lists `McpToolDefinition` items), `McpAuthInterface` (BearerToken-based client auth), and the per-request `AgentToolRegistryBridge` (gates per-tool `requireCapability` checks at dispatch time). M3 WP03 also ratified `BearerTokenAuth(tokens: [])` as the default binding, so the list of registered clients is reachable from the SP. Recent invocation audit is reachable from the AI observability trace data (M5A WP01 shipped — traces with `label = 'mcp.tools.call'` or similar carry the tool name + outcome in their span attributes).

M5C turns that data into a **read-only admin surface**: a paginated browser of registered MCP tools (with capability flags), per-tool detail (input schema, capability requirements, recent invocations from the audit trail), and a read of the server config (registered clients, server-side capability allowlist). The mission is **explicitly read-only** — adding, removing, or editing tools is out of scope and tracked as a future M5E sub-mission. Per M5A's out-of-scope clarification: M5C owns no mutation surface.

Per DIR-006 (codified policy gates as trust substrate), the admin surface MUST respect the per-record AI access policy shipped by `per-record-ai-access-flagship-01KSEFT5`/WP02 (which wires the MCP serializer's `FieldAccessPolicy` integration). The admin response MUST NOT leak fields that the requesting admin's policy forbids; the controller MUST defer to `EntityAccessHandler` for serializing recent-invocation rows that carry per-record AI traces.

## The cross-layer constraint (read before designing)

`packages/mcp` is **Layer 6 (Interfaces)**. `packages/api` is **Layer 4**. The layer rule forbids api from importing a higher layer, so the only correct cross-layer wiring is the **CodifiedContext three-tier pattern** that M5A shipped (and that telescope shipped before it):

1. **api defines the read contract** — interfaces + DTOs live in `packages/api/src/McpAdmin/`, using only api-local value types. No `Waaseyaa\Mcp\*` symbols in api source.
2. **Controllers depend on the api-local interface**, nullable, and return an empty payload when null (M5A's `AiObservabilityController` is the precedent — same empty-shape behaviour).
3. **Adapters implementing the interfaces live in `packages/mcp`** (L6 → L4 is downward = allowed). They read `ToolRegistryInterface` (the M3 surface), the SP's `McpAuthInterface` binding for the registered-clients list, and the AI observability read model (M5A's shipped surface) for recent invocations.
4. **`mcp`'s `McpServiceProvider` binds the new admin interfaces → adapters.** This binding is the single thing that prevents the dead-code-in-production failure (FR-008 guard test).
5. **`ApiServiceProvider::httpDomainRouters()` resolves the interfaces via `resolveOptional` and wires the router.** Mirrors M5A's `AiObservabilityApiRouter` registration block.
6. **`waaseyaa/mcp` is added to api's `require-dev`** (NOT `require`) — mirrors how api `require-dev`s `waaseyaa/telescope` and (per M5A) `waaseyaa/ai-observability`. Keeps `bin/check-package-layers` green (it checks runtime `require` edges only) while letting api's own service provider tag the resolved adapter at boot.
7. **Recent-invocations response respects per-record AI access policy.** The recent-invocations rows ride through `EntityAccessHandler` (per the M-A5 flagship pattern shipped by `per-record-ai-access-flagship-01KSEFT5`/WP02 — `FieldAccessPolicy` wiring). The admin controller does NOT bypass field access; an admin without per-record access to a given trace gets that row redacted, not omitted, with `_redacted: true` markers per the M-A5 convention.

## Scope

### In scope

**api (L4) — read contract + DTOs + controller + router:**
- `packages/api/src/McpAdmin/ToolRegistryReadModelInterface.php` — `listTools(): array<ToolRegistryRow>`, `findTool(string $name): ?ToolDetail`. `@api`.
- `packages/api/src/McpAdmin/ServerConfigReadModelInterface.php` — `serverConfig(): ServerConfigSnapshot`. `@api`.
- `packages/api/src/McpAdmin/ToolRegistryRow.php` — readonly: `name`, `summary?`, `requiredCapabilities: array<string>`, `category?`. `@api`.
- `packages/api/src/McpAdmin/ToolDetail.php` — readonly: `name`, `summary?`, `description?`, `inputSchema: array<string, mixed>`, `requiredCapabilities: array<string>`, `category?`, `recentInvocations: array<RecentInvocation>`. `@api`.
- `packages/api/src/McpAdmin/RecentInvocation.php` — readonly: `traceUuid`, `invokedAt`, `account?`, `outcome: 'ok'|'error'`, `errorMessage?`, `latencyMs?`. `@api`.
- `packages/api/src/McpAdmin/ServerConfigSnapshot.php` — readonly: `transport: 'streamable-http'|'sse'`, `protocolVersion: string`, `registeredClients: array<RegisteredClient>`, `serverCapabilities: array<string>`. `@api`.
- `packages/api/src/McpAdmin/RegisteredClient.php` — readonly: `clientId`, `addedAt?`, `lastSeenAt?`, `tokenFingerprint`. `@api`. Token MUST be fingerprinted (sha256 prefix) — never returned plaintext.
- `packages/api/src/Controller/McpAdminController.php` — three actions: `tools(): array` (registry list), `tool(string $name): array` (per-tool detail), `serverConfig(): array` (config snapshot). Constructor `(?ToolRegistryReadModelInterface $registry = null, ?ServerConfigReadModelInterface $config = null, ?EntityAccessHandler $accessHandler = null, ?AccountInterface $account = null)`. Returns empty-shape payloads when the registry / config dependencies are null.
- `packages/api/src/Http/Router/McpAdminApiRouter.php` — three actions: `mcp.admin.tools`, `mcp.admin.tool.show`, `mcp.admin.server-config`. Mirrors M5A's `AiObservabilityApiRouter`.
- `packages/api/src/ApiServiceProvider.php` — `httpDomainRouters()` resolves the two interfaces via `resolveOptional` and wires the router.
- `packages/api/composer.json` — add `"waaseyaa/mcp": "^<current-tag>"` to `require-dev` + path repo `../mcp` if absent (mirrors how M5A added `waaseyaa/ai-observability` to `require-dev`). Uses `^<current-tag>` literal per CP-NEW.

**mcp (L6) — adapters + binding:**
- `packages/mcp/src/Admin/ToolRegistryReadModel.php` — `implements Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface`. Reads `Waaseyaa\AI\Tools\ToolRegistryInterface` from the kernel-services bus; iterates `getTools()`; for each `McpToolDefinition` produces a `ToolRegistryRow` (`name`, `summary`, `requiredCapabilities` from the definition's capability metadata, `category`). For `findTool($name)` also reads recent invocations via the M5A `AiObservabilityReadModelInterface` (filtered to `label LIKE 'mcp.%' AND attributes.tool = $name`) — limited to the most recent 25 by `started_at DESC`.
- `packages/mcp/src/Admin/ServerConfigReadModel.php` — `implements Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface`. Reads the `McpAuthInterface` binding to enumerate registered clients (extracts `tokenFingerprint` via sha256 prefix; NEVER returns plaintext tokens); returns server capabilities and protocol version (`2025-03-26` per `McpEndpoint::dispatch()::initialize`).
- `packages/mcp/src/McpServiceProvider.php` — extend `register()` with two new bindings: `ToolRegistryReadModelInterface` → `ToolRegistryReadModel`, `ServerConfigReadModelInterface` → `ServerConfigReadModel`. Both depend on `ToolRegistryInterface` + `McpAuthInterface` already bound in M3 WP03 — no new container dependencies.

**foundation (L0) — routes:**
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes after the M5A observability block, all using **string FQCN** controller references:
  - `api.mcp.admin.tools` → `GET /api/mcp/tools`, `_role: admin`, controller `'Waaseyaa\\Api\\Controller\\McpAdminController'`, action `tools`.
  - `api.mcp.admin.tool.show` → `GET /api/mcp/tools/{name}`, `_role: admin`, action `tool`. Path token `{name}` is URL-encoded tool name; controller decodes once.
  - `api.mcp.admin.server-config` → `GET /api/mcp/server-config`, `_role: admin`, action `serverConfig`.

**admin SPA (L6) — registry page, detail page, composables, nav, i18n, docs:**
- `packages/admin/app/composables/useMcpTools.ts` — `{rows, loading, error, fetchTools()}`. TS types match the controller payload (camelCase).
- `packages/admin/app/composables/useMcpTool.ts` — `{tool, loading, error, fetchTool(name)}`.
- `packages/admin/app/composables/useMcpServerConfig.ts` — `{config, loading, error, fetchConfig()}`.
- `packages/admin/app/pages/mcp/tools/index.vue` — registry browser table (name, category, capabilities chips, summary). Each row links to detail.
- `packages/admin/app/pages/mcp/tools/[name].vue` — per-tool detail: header (name, category, capabilities), input schema viewer (collapsible JSON tree), recent-invocations table (last 25). Link to "Server config".
- `packages/admin/app/pages/mcp/server-config.vue` — server config display: transport, protocol version, server capabilities list, registered-clients table (clientId + fingerprint + lastSeenAt).
- `packages/admin/app/components/mcp/ToolRegistryTable.vue` — table reused for registry browser.
- `packages/admin/app/components/mcp/InputSchemaViewer.vue` — collapsible JSON-schema tree (read-only).
- `packages/admin/app/components/mcp/RecentInvocationsTable.vue` — table with `traceUuid` link-out to `/ai/observability/runs/{uuid}` (M5B's detail page if available; otherwise plain text).
- Nav: add a new "MCP" group with two entries — `/mcp/tools` ("Tools") and `/mcp/server-config` ("Server config"). Mirror exactly how M5A registered the "AI" group with `/ai/observability`.
- `packages/admin/app/i18n/en.json` — keys: `mcp_tools_title`, `mcp_tools_empty`, `mcp_tools_col_name`, `mcp_tools_col_category`, `mcp_tools_col_capabilities`, `mcp_tools_col_summary`, `mcp_tool_detail_title`, `mcp_tool_detail_input_schema`, `mcp_tool_detail_capabilities`, `mcp_tool_detail_recent_invocations`, `mcp_server_config_title`, `mcp_server_config_transport`, `mcp_server_config_protocol`, `mcp_server_config_capabilities`, `mcp_server_config_clients`, `mcp_server_config_client_id`, `mcp_server_config_client_fingerprint`, `mcp_server_config_client_last_seen`.
- `packages/admin/tests/unit/composables/useMcpTools.test.ts` — vitest.
- `packages/admin/tests/unit/composables/useMcpTool.test.ts` — vitest.
- `packages/admin/tests/unit/composables/useMcpServerConfig.test.ts` — vitest.
- `packages/admin/e2e/mcp-admin.spec.ts` — Playwright smoke (run deferred).

**Docs:**
- `docs/specs/mcp-endpoint.md` — stamp `<!-- Spec reviewed 2026-05-25 - mcp-endpoint-admin-m5c-01KSEFTB -->` + "Admin surface" section describing the three read endpoints + pages.
- `docs/specs/admin-spa.md` — stamp + "MCP admin" section.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: MCP endpoint admin — tool registry browser, per-tool detail with recent invocations, and server config viewer (read-only). (#1415)`

### Out of scope (→ M5E or later)

- Capability allowlist editing UI (mutates server config).
- Tool registration / deregistration UI.
- Client token rotation / revocation UI.
- Live SSE tail of MCP request stream (M5D scope — broadcast monitor).
- AI observability runs surface (M5B owns that).

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `ToolRegistryReadModelInterface` and `ServerConfigReadModelInterface` (in `packages/api/src/McpAdmin/`) declare their methods using only api-local DTOs. No reference to any `Waaseyaa\Mcp\*` or `Waaseyaa\AI\*` type. |
| FR-002 | Mandatory | `ToolRegistryReadModel` (in `packages/mcp`) implements the api registry interface; reads `Waaseyaa\AI\Tools\ToolRegistryInterface` via the kernel-services bus; emits `ToolRegistryRow` per `McpToolDefinition` (including `requiredCapabilities` from the definition metadata). |
| FR-003 | Mandatory | `findTool($name)` returns a `ToolDetail` including the most recent 25 invocations from the AI observability trace surface (`label LIKE 'mcp.%'`, `attributes.tool = $name`, `started_at DESC`). Reads via the M5A `AiObservabilityReadModelInterface` — no direct `trace_span` SQL from the mcp adapter (one read contract per source). |
| FR-004 | Mandatory | `ServerConfigReadModel` (in `packages/mcp`) implements the api server-config interface; reads `McpAuthInterface` for registered clients (token fingerprint via sha256 prefix; **never returns plaintext token**); reports protocol version `2025-03-26` and the server's advertised capabilities. |
| FR-005 | Mandatory | `McpServiceProvider` binds both api interfaces → their adapters. Both depend only on M3-shipped bindings (`ToolRegistryInterface`, `McpAuthInterface`) — no new container dependencies. |
| FR-006 | Mandatory | `McpAdminController` returns `{data: ...}` envelopes with **camelCase** keys; returns zeroed empty-shape payloads when its dependencies are null (degrades cleanly when mcp is absent). Controller does NOT re-check role; routing enforces `_role: admin`. |
| FR-007 | Mandatory | `GET /api/mcp/tools`, `GET /api/mcp/tools/{name}`, `GET /api/mcp/server-config` are registered in `BuiltinRouteRegistrar` with string FQCN controller references and `_role: admin`. Per-record-AI-access field redaction (per the M-A5 flagship pattern) MUST apply to `recentInvocations` rows — the controller delegates field-level access to `EntityAccessHandler` per `per-record-ai-access-flagship-01KSEFT5`/WP02 (DIR-004, DIR-006). |
| FR-008 | Mandatory | A **kernel-boot integration test** boots the full kernel, registers ≥2 MCP tools (one with capabilities, one without) + ≥1 registered client + ≥3 trace invocations across two tools, hits `GET /api/mcp/tools` as admin → asserts both tools listed with correct capability flags; hits `GET /api/mcp/tools/{name}` → asserts `inputSchema` + `recentInvocations` shape; hits `GET /api/mcp/server-config` → asserts client list (fingerprints only, no plaintext) + capabilities. Also asserts 403 for non-admin on all three. (Dead-code-in-production guard — must FAIL if either SP binding is removed.) |
| FR-009 | Mandatory | `/mcp/tools`, `/mcp/tools/[name]`, `/mcp/server-config` admin pages render. Composables covered by vitest; Playwright smoke present (run deferred). Two nav entries ("Tools", "Server config") registered under a new "MCP" group, mirroring the M5A "AI" group mechanism. |
| FR-010 | Mandatory | `docs/specs/mcp-endpoint.md` + `docs/specs/admin-spa.md` stamped. `CHANGELOG.md` `[Unreleased]` updated. |
| NFR-001 | Mandatory | Cross-layer wiring mirrors the CodifiedContext three-tier pattern shipped by M5A: read contracts + DTOs in api; adapters in mcp; `mcp` in api **require-dev** only; `bin/check-package-layers` stays green. |
| NFR-002 | Mandatory | Controller / router / composable shapes mirror M5A + M4B. camelCase JSON aligned to TS types. |
| NFR-003 | Mandatory | Token fingerprints use sha256 with a 16-character hex prefix. Plaintext bearer tokens MUST never appear in any response payload. Covered by a dedicated unit test in `ServerConfigReadModelTest`. |
| C-001 | Constraint | Read-only. No mutation surface: no add/remove/edit tools, no token rotation, no capability editing. |
| C-002 | Constraint | No upward import: api source must never `use` a `Waaseyaa\Mcp\*` or `Waaseyaa\AI\*` symbol. `mcp` is api's `require-dev` only — `bin/check-package-layers` enforces. |
| C-003 | Constraint | No new entity types. No new database tables. The adapters read only existing surfaces (`ToolRegistryInterface`, `McpAuthInterface`, M5A's observability read model). |
| C-004 | Constraint | Per-record AI access enforcement on `recentInvocations` is delegated to `EntityAccessHandler` from the M-A5 flagship — the controller MUST NOT re-implement field-level access. Removing the `EntityAccessHandler` injection is a CI-blocking regression (verified by hand against the M-A5 fixtures). |

## Acceptance

- All FRs met.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Kernel-boot integration test (FR-008) demonstrably fails when either `McpServiceProvider` binding (registry / server-config) is removed — verify by hand and report.
- Plaintext-token leak test (NFR-003) passes: no `tokenFingerprint` field returns the original token literal.
- Recent-invocations field redaction verified by hand against M-A5 fixtures (an admin lacking per-record access to a trace gets `_redacted: true` markers, not the row omitted).
- Commit footers `Refs #1415` (umbrella stays open until all four M5 sub-missions land).
- M5 progress comment posted on #1415 at wrap-up.

## Risks

- **Dead code in production (primary).** If either admin interface is wired via `resolveOptional` but no real `singleton` binds it, the admin surface silently returns empty in production while fake-backed tests pass. FR-008's kernel-boot test, which seeds real tools + clients + invocations and asserts non-empty rows, is the mandatory guard. Reviewer MUST grep `McpServiceProvider` for both bindings.
- **Plaintext token leak.** Highest-severity: if `RegisteredClient` ever returns the bearer token literal, a compromised admin account becomes a full MCP client takeover. NFR-003 + dedicated unit test guard this; reviewer MUST grep responses for `'token'` or any 64-character hex.
- **Per-record access bypass.** The recent-invocations rows are AI trace data. Without the `EntityAccessHandler` delegation (C-004), an admin with limited per-record AI access could see traces they shouldn't. The mission `blocked_by` declares `per-record-ai-access-flagship-01KSEFT5/WP02` for this reason; do NOT release before that lands.
- **Layer violation.** Any `use Waaseyaa\Mcp\…` or `use Waaseyaa\AI\…` in `packages/api/src/**` fails C-002. Adapters live in mcp.
- **Tool name encoding.** Tool names may contain `.` or `-`; route token `{name}` must be URL-encoded by clients and decoded once by the controller. Covered by a unit test using a fixture tool name with `.`.
- **getQuery / unbound chains.** Adapters delegate to the M5A read model + the M3-shipped registry — no direct entity queries — so no new getquery-baseline entries expected.

## Decisions pre-resolved

- Two WPs, sequential (frontend depends on backend contract).
- Backend WP01 writes the kernel-boot integration test that fails if either SP binding is missing (M5A FR-007 pattern).
- Authoritative surface: the JSON contract under `packages/api/src/McpAdmin/`.
- Read-only — no capability editing UI in M5C (deferred to M5E).
- camelCase JSON across all admin payloads (matches M5A as-shipped).
- Recent-invocations limited to 25 (no pagination in M5C; full audit lives at M5B's runs surface).
- Token fingerprint = sha256 hex, first 16 chars. NEVER plaintext.
- Implementer preference order: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Decisions deferred to implementer

- Exact CSS for the JSON-schema tree viewer (must respect existing AdminShell tokens; brand-teal palette).
- Whether the registry table shows capability chips inline or in a separate column — pick the option that matches existing admin table patterns.
- The exact text of the "Server config" page header — pick something consistent with M5A's "Dashboard" language.

## Out-of-band

- Dependency: `per-record-ai-access-flagship-01KSEFT5/WP02` MUST be MERGED before M5C's WP01 can land (the `EntityAccessHandler` integration on the MCP serializer is the runtime hook this WP wires for `recentInvocations` field redaction). Declared in `wps.yaml` as `blocked_by`.
- Pattern reference (read every file ONCE before writing): `/home/fsd42/dev/waaseyaa/kitty-specs/ai-observability-dashboard-01KSE9BX/` (M5A — shipped CodifiedContext cross-layer pattern).
- Strategic context: `/home/fsd42/dev/waaseyaa/docs/specs/codified-context-integration.md` (three-tier inheritance model), `/home/fsd42/dev/waaseyaa/docs/specs/mcp-endpoint.md` (the MCP package + bridge architecture shipped in M3), `/home/fsd42/dev/waaseyaa/docs/specs/ai-integration.md` (MCP tool definitions + capability model), `.kittify/charter/charter.md` DIR-004 (OCAP-by-architecture invariant) + DIR-006 (codified policy gates as trust substrate).
