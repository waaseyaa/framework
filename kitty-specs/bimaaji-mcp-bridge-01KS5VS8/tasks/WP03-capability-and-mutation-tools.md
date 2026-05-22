---
work_package_id: WP03
title: Capability gating + 2 mutation tools
dependencies:
- WP02
requirement_refs:
- FR-003
- FR-004
- FR-010
- FR-011
- NFR-003
- NFR-004
- SC-002
- SC-003
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T008
- T009
- T010
- T011
history: []
authoritative_surface: packages/mcp/src/Capability/
execution_mode: code_change
owned_files:
- packages/mcp/src/Capability/SessionCapabilities.php
- packages/mcp/src/Tool/Bimaaji/ProposeMutationTool.php
- packages/mcp/src/Tool/Bimaaji/GeneratePatchTool.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpMutateTest.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpCapabilityTest.php
tags: []
---

## Objective

Implement the per-session capability mechanism (default `['bimaaji.read']`; opt-in `bimaaji.mutate` via env var). Ship the two mutation tools and the capability gating that protects them. SC-003: neither tool writes to disk under any code path.

## Subtasks

### T008 — `SessionCapabilities`

Create `packages/mcp/src/Capability/SessionCapabilities.php`. Constructor reads from:

1. `WAASEYAA_MCP_CAPABILITIES` env var (CSV string parsed into a list).
2. Default: `['bimaaji.read']`.

Public surface: `has(string $capability): bool`, `all(): array<string>`. Marked `@api` (consumed by the dispatch logic, never directly by tools).

Wire `SessionCapabilities` into the MCP server's tool-dispatch path: before invoking any tool, check `$session->has($tool->capability())` and short-circuit with the `capability_required` error envelope (NFR-003 — ≤ 5 ms) if missing.

### T009 — `ProposeMutationTool` + `GeneratePatchTool`

Same skeleton as WP02 tools but `capability: bimaaji.mutate`. Constructors take `MutationValidator` and `PatchGenerator` respectively. Both wrap M2's tool surface (or call bimaaji directly if M2 not yet merged — see WP01 cross-mission contract).

**SC-003 compliance:** Neither tool calls `file_put_contents`, `fwrite`, `mkdir`, `rename`, or any other filesystem-writing function. Static-analyze the implementation: the only outbound calls should be to bimaaji APIs.

### T010 — `BimaajiMcpMutateTest` (positive path, FR-010, SC-002, SC-003)

Boot MCP server with `WAASEYAA_MCP_CAPABILITIES=bimaaji.read,bimaaji.mutate`. Invoke `bimaaji_propose_mutation` against a fixture entity type; assert non-error envelope with `valid: true`. Then invoke `bimaaji_generate_patch` with the validated result; assert non-empty PatchSet.

**Disk-write assertion (SC-003):** Snapshot a temp directory before + after the test; assert zero file creates/modifies/deletes.

### T011 — `BimaajiMcpCapabilityTest` (negative path, FR-011, NFR-003)

Boot MCP server with default capabilities (no env var set). Attempt `bimaaji_propose_mutation`. Assert:

- Envelope: `{ ok: false, error: { code: 'capability_required', capability: 'bimaaji.mutate' } }`
- Response time ≤ 5 ms (NFR-003)
- No tool work occurred (verify by asserting `MutationValidator::validate()` was not called — use a spy validator)
- No information leak in the error envelope (specifically: no operation name, no entity type, no parameter values — only the required capability name)

## Definition of Done

- [ ] `SessionCapabilities` resolves env var + defaults correctly.
- [ ] Both mutation tools register and execute when `bimaaji.mutate` is present.
- [ ] All 3 contract assertions in `BimaajiMcpMutateTest` pass, including SC-003 disk-write check.
- [ ] All assertions in `BimaajiMcpCapabilityTest` pass.
- [ ] Logging (NFR-004) emits `{tool, capability_set, outcome}` for each invocation — assert via a captured log spy.
- [ ] All local gates clean.

## Risks and notes

- **M2 dependency:** If M2 hasn't merged, this WP defines the canonical mutation-tool argument schema. M2 will inherit it. Coordinate via the cross-mission contract anchor (WP01).
- **NFR-003 5 ms budget:** Tight on a slow CI. The check is a single `array_search`-equivalent — should be fast. Soft warning if exceeded.
- **Logging gotcha:** Per CLAUDE.md "Best-effort side effects" — wrap logger calls in try/catch so a logger failure doesn't crash the dispatch.
- **PII-free logging:** Spec is explicit (NFR-004). Don't log patch content, don't log operation parameters, don't log session-identifying data. The log line is `{tool, capability_set: [...], outcome}` and nothing else.
