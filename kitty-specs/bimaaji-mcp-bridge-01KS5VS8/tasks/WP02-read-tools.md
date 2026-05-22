---
work_package_id: WP02
title: 8 read tools + spec-search backend
dependencies:
- WP01
requirement_refs:
- FR-001
- FR-002
- FR-005
- FR-006
- FR-009
- NFR-002
- C-001
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T004
- T005
- T006
- T007
history: []
authoritative_surface: packages/mcp/src/Tool/Bimaaji/
execution_mode: code_change
owned_files:
- packages/mcp/src/Tool/Bimaaji/ApplicationInfoTool.php
- packages/mcp/src/Tool/Bimaaji/ListRoutesTool.php
- packages/mcp/src/Tool/Bimaaji/ListJsonApiTool.php
- packages/mcp/src/Tool/Bimaaji/ListEntitiesTool.php
- packages/mcp/src/Tool/Bimaaji/ListAdminTool.php
- packages/mcp/src/Tool/Bimaaji/SovereigntyProfileTool.php
- packages/mcp/src/Tool/Bimaaji/PublicSurfaceTool.php
- packages/mcp/src/Tool/Bimaaji/SearchSpecsTool.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php
tags: []
---

## Objective

Ship the 8 read tools. Seven are section-scoped wrappers around M1's section providers; one (`SearchSpecsTool`) is a spec-search backend over `docs/specs/*.md`. All tools carry `capability: bimaaji.read` (the default-on capability for MCP sessions).

## Subtasks

### T004 — Seven section-scoped tools (one per AD-02 row except SearchSpecsTool)

For each of `ApplicationInfoTool`, `ListRoutesTool`, `ListJsonApiTool`, `ListEntitiesTool`, `ListAdminTool`, `SovereigntyProfileTool`, `PublicSurfaceTool`:

- Class lives at `packages/mcp/src/Tool/Bimaaji/<Name>Tool.php`.
- Marked with `#[AsMcpTool(name: 'bimaaji_<snake>', capability: 'bimaaji.read')]` (or the actual attribute name discovered in WP01).
- Constructor takes the relevant bimaaji service (e.g. `EntityIntrospectionProvider` for `ListEntitiesTool`, `ApplicationGraphGenerator` for `ApplicationInfoTool`).
- `execute(array $arguments = []): array` returns the AD-02 result wrapped in the standard MCP tool result envelope (whatever `packages/mcp/` defines — likely `{ ok, data, meta?, error? }` mirroring M2 AD-03).

`ApplicationInfoTool` returns the full graph. The 6 section-scoped tools return only their section's payload. Each section-scoped tool's name convention matches the section key (`bimaaji_list_routes` for `routing`, `bimaaji_list_entities` for `entities`, etc.).

### T005 — `SearchSpecsTool` with grep fallback

`SearchSpecsTool` is non-trivial:

1. First, check whether `Waaseyaa\Bimaaji\Spec\SpecIndexProvider` exists and is container-bound. If yes, delegate.
2. If no (the AD-04 fallback path), implement directly: `glob(__DIR__ . '/../../../../../docs/specs/*.md')` (project-root-relative — verify the path resolves under the test kernel), iterate, do case-insensitive substring search on `query` arg, return `[{file: <path>, section_title: <##-heading>, snippet: <±80 chars around match>, line_number: <1-indexed>}, ...]`.

Returns `[]` for no matches (not an error). Returns an error envelope only if the query argument is empty or missing.

### T006 — `BimaajiMcpReadTest`

Boot the WP01 smoke kernel + MCP server. For each of the 8 tools, invoke once and assert:

- Non-error envelope returned (`ok === true` or the equivalent for the project's envelope shape)
- Payload structure matches AD-02 (full graph vs. single section vs. search results)
- Response time ≤ NFR-002 budget (M2's read-tool budget plus ≤ 20 ms MCP serialization overhead — record actual times in the PR description)

For `SearchSpecsTool` specifically, run a query that should match (e.g. `'bimaaji'`) and one that shouldn't (`'absolutely_nothing_matches_this_query_xyzzy'`); assert the first returns ≥ 1 result, the second returns `[]`.

### T007 — ServiceProvider registration

Edit `packages/mcp/src/ServiceProvider.php` (or wherever WP01 confirmed MCP tools register) to register the 8 tool classes. If attribute-based auto-discovery is the mechanism, this subtask is no-op (the attributes do the work). If explicit registration is required, add the eight lines.

## Definition of Done

- [ ] All 8 tool classes exist and pass `composer phpstan`.
- [ ] `BimaajiMcpReadTest` covers all 8 tools and passes.
- [ ] NFR-002 timing is recorded in test output / PR description.
- [ ] `SearchSpecsTool` handles empty / missing / non-string `query` argument with a structured error envelope.
- [ ] cs-check, layer, composer-policy, dead-code gates clean.

## Risks and notes

- **Provider FQCNs:** Verify the actual class names of the bimaaji introspection providers before writing tool constructors. They live under `packages/bimaaji/src/Introspection/<Section>/<Section>IntrospectionProvider.php` (e.g. `AdminIntrospectionProvider`, `EntityIntrospectionProvider`, `JsonApiIntrospectionProvider`, `RoutingIntrospectionProvider`, `SovereigntyIntrospectionProvider`, `PublicSurfaceProvider`). M1's `BimaajiServiceProvider` is the canonical reference.
- **Result envelope shape:** Confirm whether `packages/mcp/` already defines a tool result envelope. If yes, reuse it. If not, mirror M2's AD-03 envelope (`{ok, data, meta?, error?}`) — and surface the inconsistency for follow-up.
- **MCP-specific argument schemas:** Most MCP clients require declared input schemas (JSON Schema). The `#[AsMcpTool]` attribute should accept an `inputSchema` parameter — if WP01 added the attribute, define schemas now (mostly `{}` for the section-scoped tools, `{query: string}` for SearchSpecsTool).
