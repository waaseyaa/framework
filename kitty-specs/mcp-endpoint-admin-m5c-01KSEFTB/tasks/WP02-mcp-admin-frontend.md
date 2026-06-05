---
work_package_id: WP02
title: 'Frontend: MCP admin registry, detail, server-config pages + composables, nav, i18n, docs (M5C)'
dependencies:
- WP01
requirement_refs:
- C-001
- C-002
- FR-009
- FR-010
- NFR-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T005
- T006
- T007
phase: ''
assignee: ''
agent: ''
history: []
authoritative_surface: packages/admin/app/pages/mcp/tools/[name].vue
execution_mode: code_change
owned_files:
- packages/admin/app/composables/useMcpTools.ts
- packages/admin/app/composables/useMcpTool.ts
- packages/admin/app/composables/useMcpServerConfig.ts
- packages/admin/app/pages/mcp/tools/index.vue
- packages/admin/app/pages/mcp/tools/[name].vue
- packages/admin/app/pages/mcp/server-config.vue
- packages/admin/app/components/mcp/ToolRegistryTable.vue
- packages/admin/app/components/mcp/InputSchemaViewer.vue
- packages/admin/app/components/mcp/RecentInvocationsTable.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useMcpTools.test.ts
- packages/admin/tests/unit/composables/useMcpTool.test.ts
- packages/admin/tests/unit/composables/useMcpServerConfig.test.ts
- packages/admin/e2e/mcp-admin.spec.ts
- docs/specs/mcp-endpoint.md
- docs/specs/admin-spa.md
- CHANGELOG.md
tags: []
---

# WP02 — Frontend: MCP admin registry, detail, server-config pages + composables, nav, i18n, docs (M5C)

**Mission:** `mcp-endpoint-admin-m5c-01KSEFTB` (#1415, audit C-L6-01)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on WP01** — `GET /api/mcp/tools`, `GET /api/mcp/tools/{name}`, `GET /api/mcp/server-config` and their camelCase payloads must already be merged/approved.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/mcp-endpoint-admin-m5c-01KSEFTB-lane-b
```
(Exact path is printed by `spec-kitty agent action implement WP02`.) Lane worktree has no `node_modules` — `cd packages/admin && npm install` first.

## Pattern to mirror (READ FIRST)

- `packages/admin/app/composables/useAiObservability.ts` (M5A's shipped composable)
- `packages/admin/app/pages/ai/observability.vue` (M5A's shipped page + nav-group registration)
- The M5A "AI" nav-group registration — mirror exactly to add a "MCP" group

## Endpoint contract (from WP01 — AS SHIPPED; use these exact field names)

```
GET /api/mcp/tools
→ { data: { rows: [{ name, summary?, requiredCapabilities: string[], category? }] } }

GET /api/mcp/tools/{name}
→ { data: { tool: { name, summary?, description?, inputSchema, requiredCapabilities: string[], category?, recentInvocations: [{ traceUuid, invokedAt, account?, outcome: 'ok'|'error', errorMessage?, latencyMs? }] } } }

GET /api/mcp/server-config
→ { data: { config: { transport: 'streamable-http'|'sse', protocolVersion, registeredClients: [{ clientId, addedAt?, lastSeenAt?, tokenFingerprint }], serverCapabilities: string[] } } }
```

**Security note:** TS types MUST NOT include any `token` field. The `tokenFingerprint` is a 16-char hex.

## Subtasks

**T005 — composables + components + pages**
- `app/composables/useMcpTools.ts` — `{rows, loading, error, fetchTools()}`. `useApi`.
- `app/composables/useMcpTool.ts` — `{tool, loading, error, fetchTool(name)}`. URL-encode `name` once before request.
- `app/composables/useMcpServerConfig.ts` — `{config, loading, error, fetchConfig()}`.
- `app/components/mcp/ToolRegistryTable.vue` — props `{rows}`; capability chips column.
- `app/components/mcp/InputSchemaViewer.vue` — collapsible JSON-schema tree; use `<details>` per node.
- `app/components/mcp/RecentInvocationsTable.vue` — props `{rows}`. Each `traceUuid` cell renders a router-link to `/ai/observability/runs/{uuid}` (M5B detail page) — if M5B page doesn't exist yet, render the uuid as plain text (no broken link).
- `app/pages/mcp/tools/index.vue` — title, registry table, empty/loading/error.
- `app/pages/mcp/tools/[name].vue` — header card (name, category, capability chips), input-schema viewer, recent-invocations table, "Server config" link.
- `app/pages/mcp/server-config.vue` — transport + protocol version banner, capabilities list, clients table.

**T006 — nav + i18n + tests**
- Register a new "MCP" nav group with two entries: "Tools" → `/mcp/tools`, "Server config" → `/mcp/server-config`. Mirror M5A's "AI" group exactly.
- `app/i18n/en.json` — `mcp_tools_title`, `mcp_tools_empty`, `mcp_tools_col_name`, `mcp_tools_col_category`, `mcp_tools_col_capabilities`, `mcp_tools_col_summary`, `mcp_tool_detail_title`, `mcp_tool_detail_input_schema`, `mcp_tool_detail_capabilities`, `mcp_tool_detail_recent_invocations`, `mcp_server_config_title`, `mcp_server_config_transport`, `mcp_server_config_protocol`, `mcp_server_config_capabilities`, `mcp_server_config_clients`, `mcp_server_config_client_id`, `mcp_server_config_client_fingerprint`, `mcp_server_config_client_last_seen`.
- `tests/unit/composables/useMcpTools.test.ts` — vitest: fetch success / failure.
- `tests/unit/composables/useMcpTool.test.ts` — vitest: fetch success populates `tool`; name with `.` (e.g. `bimaaji.search_specs`) is URL-encoded once before request.
- `tests/unit/composables/useMcpServerConfig.test.ts` — vitest: fetch success populates `config`; TS type assertion verifies the type has no `token` field (only `tokenFingerprint`).
- `e2e/mcp-admin.spec.ts` — Playwright smoke (run deferred): visit all three pages, assert renders + nav.

**T007 — docs**
- `docs/specs/mcp-endpoint.md` — add `<!-- Spec reviewed 2026-05-25 - mcp-endpoint-admin-m5c-01KSEFTB: read-only admin surface (tool registry, per-tool detail, server config) -->` near the top + an "Admin surface" section describing the three endpoints + their pages.
- `docs/specs/admin-spa.md` — stamp + "MCP admin" subsection describing the three pages.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: MCP endpoint admin — tool registry browser, per-tool detail with recent invocations, and server config viewer (read-only). (#1415)`

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
2. `npm run build` (Nuxt SSR compile check).
3. `cd /home/jones/dev/waaseyaa/.worktrees/... && composer cs-check && composer phpstan` (docs touch).
4. Confirm by hand: visiting `/mcp/tools` shows the new "MCP" nav group + the list renders against WP01.

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(admin): MCP tool registry page + composable (#1415)`
  - `feat(admin): MCP per-tool detail page with input schema + invocations (#1415)`
  - `feat(admin): MCP server config page (#1415)`
  - `feat(admin): MCP nav group + i18n keys (#1415)`
  - `docs(mcp-endpoint,admin-spa): stamp MCP admin surface (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission mcp-endpoint-admin-m5c-01KSEFTB
  spec-kitty agent tasks move-task WP02 --to for_review --mission mcp-endpoint-admin-m5c-01KSEFTB --note "M5C frontend ready; vitest green; TS type guard confirms no plaintext token field; e2e deferred"
  ```

## Report back with

- vitest + typecheck + lint + npm run build output.
- Confirmation the three pages render against WP01.
- Confirmation the TS type assertion verifies no `token` field exists.

## Activity Log

(implementer appends here)
- 2026-05-25T06:09:36Z – unknown – Moved to in_progress
- 2026-05-25T06:19:33Z – unknown – M5C frontend ready; vitest 287/287 green; typecheck clean (mcp components); TS type guard confirms no plaintext token field; e2e deferred (requires dev server)
- 2026-05-25T06:20:23Z – unknown – Opus review: lane-a disciplined; 287/287 Vitest; NFR-003 type-asserts no token field; useLanguage() pattern correctly used (not useI18n())
- 2026-05-26T19:02:16Z – unknown – Done override: Sprint merge to main
