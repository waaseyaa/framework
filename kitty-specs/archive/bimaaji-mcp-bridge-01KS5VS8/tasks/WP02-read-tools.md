---
work_package_id: WP02
title: Bridge wiring + bimaaji_search_specs tool
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
authoritative_surface: packages/mcp/src/
execution_mode: code_change
owned_files:
- packages/mcp/src/McpServiceProvider.php
- packages/mcp/tests/Unit/McpServiceProviderTest.php
- packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php
- packages/ai-agent/tests/Contract/Bimaaji/SearchSpecsToolTest.php
- packages/bimaaji/src/BimaajiServiceProvider.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php
- docs/specs/mcp-endpoint.md
- CHANGELOG.md
tags: []
---

## Objective

Wire `McpServiceProvider::register()` so the `AgentToolRegistryBridge` is
container-resolvable as both `Mcp\Bridge\ToolRegistryInterface` and
`Mcp\Bridge\ToolExecutorInterface`. Add the one genuinely net-new read tool
(`bimaaji_search_specs`) under `packages/ai-agent/src/Tool/Bimaaji/`; the
existing M2 tools cover the other "read" entries from the original AD-02
inventory (six of those eight collapse into `bimaaji_introspect_section`,
the seventh — `bimaaji_application_info` — is redundant with
`bimaaji_introspect_graph`). End-to-end integration test
(`BimaajiMcpReadTest`) demonstrates the bridge listing the now-five
bimaaji read tools through the container-resolved
`Mcp\Bridge\ToolRegistryInterface`.

This WP02 is re-scoped per WP01's audit and `plan.md` § "WP01 re-scope
rationale" (2026-05-22). The original spec called for eight new
`packages/mcp/src/Tool/Bimaaji/*.php` classes; the audit found that
ai-agent's `#[AsAgentTool]` registry plus the pre-existing
`AgentToolRegistryBridge` adapter already deliver those tools over MCP with
no per-tool MCP code. The remaining work is bridge wiring + one search
backend.

## Subtasks

### T004 — Bind `SpecIndexProvider` in `BimaajiServiceProvider`

`SpecIndexProvider` (Layer 4 — `packages/bimaaji/src/Spec/`) is not yet
container-resolvable. Add a singleton binding in
`BimaajiServiceProvider::register()` constructed from a
`resolveSpecsDirectory()` helper that prefers
`config['bimaaji']['specs_directory']` and falls back to
`projectRoot . '/docs/specs'`. (FR-005 backend availability.)

### T005 — `SearchSpecsTool` in ai-agent

Add `Waaseyaa\AI\Agent\Tool\Bimaaji\SearchSpecsTool` carrying
`#[AsAgentTool(name: 'bimaaji_search_specs', capability: 'bimaaji.read', ...)]`.
Constructor takes `SpecIndexProvider`. `inputSchema` requires a `query`
string and an optional `limit` integer (default 20, max 100). `execute()`:

1. Gates on `bimaaji.read` via the inherited `requireCapability()`.
2. Validates `query` is a non-empty string (returns structured error
   envelope on miss, mirroring `IntrospectSectionTool`).
3. Calls `SpecIndexProvider::provide()->data` to enumerate the spec files.
4. For each file: `file_get_contents()` + a case-insensitive substring
   search. Captures `{file, section_title, line_number, snippet}` per
   match — `section_title` is the nearest preceding `## ` or `### `
   header line.
5. Returns `AgentToolResult::success(content: [['type'=>'json',
   'data'=>['matches'=>[...]]]], summary: 'N matches')`.

No filesystem writes; no symlink following; no recursive directory walk
(spec index already lists `docs/specs/*.md` non-recursively).

Contract test pattern mirrors `IntrospectSectionToolTest`:
`accountWithPermission()` helper, stub `SpecIndexProvider`, positive +
forbidden + missing-arg paths.

### T006 — `McpServiceProvider::register()` bindings

Three bindings:

| Abstract | Concrete |
|----------|----------|
| `Mcp\Auth\McpAuthInterface` | `BearerTokenAuth(tokens: [])` — empty by default; production overrides via the kernel-services bus or by re-binding the abstract. |
| `Mcp\Bridge\AgentToolRegistryBridge` | Singleton: `new AgentToolRegistryBridge(registry: $kernelServices->get(AgentToolRegistryInterface::class), account: <placeholder>)`. |
| `Mcp\Bridge\ToolRegistryInterface` | Same singleton (returns the bridge). |
| `Mcp\Bridge\ToolExecutorInterface` | Same singleton (returns the bridge). |

**Placeholder account caveat.** `AccountInterface` is a per-request
value (set by SessionMiddleware), but `register()` runs once at boot.
The bridge is bound with a no-permission anonymous-shaped inline
`AccountInterface` so `tools/list` works (no permission check there) but
`tools/call` returns `forbidden` until WP03 lands per-request account
passthrough into the bridge. The integration test asserts both
behaviours — listing succeeds, calling returns the documented
`forbidden` envelope.

Update `McpServiceProviderTest` to cover the new bindings.

### T007 — `BimaajiMcpReadTest` integration test

`tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php`. Boot a minimal
kernel with `McpServiceProvider` + `BimaajiServiceProvider` +
`AiToolsServiceProvider` + `AiAgentServiceProvider`. Assert:

1. `Mcp\Bridge\ToolRegistryInterface` resolves to an
   `AgentToolRegistryBridge`.
2. `getTools()` returns ≥ 5 bimaaji tools whose names match `^bimaaji_`.
3. The five canonical bimaaji read-tool names appear:
   `bimaaji_introspect_graph`, `bimaaji_introspect_section`,
   `bimaaji_propose_mutation`, `bimaaji_generate_patch`,
   `bimaaji_search_specs`.
4. Calling `bimaaji_search_specs` through the bridge returns the
   WP02-documented `forbidden` envelope (placeholder account has no
   caps).

Uses `#[CoversNothing]`.

## Definition of Done

- [ ] `SpecIndexProvider` bound singleton in `BimaajiServiceProvider`.
- [ ] `SearchSpecsTool` + contract test landed; ≥ 4 contract tests cover
      success / missing-query / forbidden / no-matches.
- [ ] `McpServiceProvider::register()` binds the three abstracts;
      `McpServiceProviderTest` covers them.
- [ ] `BimaajiMcpReadTest` exists and passes.
- [ ] `CHANGELOG.md` `[Unreleased]` updated under `### Added`.
- [ ] `docs/specs/mcp-endpoint.md` stamped with WP02 bindings note.
- [ ] All local gates pass (cs-check, phpstan, layer, composer-policy,
      dead-code, getQuery, surface-map).

## Risks and notes

- **Per-request account passthrough is a WP03 concern.** The placeholder
  account in the WP02 bridge binding makes `tools/call` always return
  `forbidden` for capability-gated tools. This is documented in the
  WP02 commit message and the `BimaajiMcpReadTest` docblock so reviewers
  don't read the forbidden envelope as a regression.
- **`SearchSpecsTool` is naïve substring search**, not BM25 or trigram —
  AD-04 explicitly notes this. If the M3 mission later wants ranked
  results, that's a follow-up.
