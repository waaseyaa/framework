---
work_package_id: WP03
title: Per-request account passthrough + capability gating
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
authoritative_surface: packages/mcp/src/
execution_mode: code_change
owned_files:
- packages/mcp/src/McpEndpoint.php
- packages/mcp/src/McpServiceProvider.php
- packages/mcp/tests/Unit/McpEndpointTest.php
- packages/mcp/tests/Unit/McpServiceProviderTest.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpReadTest.php
- tests/Integration/PhaseN/AgentRuntime/McpControllerToolsSharingTest.php
- tests/Integration/PhaseN/Mcp/BimaajiMcpCapabilityTest.php
- docs/specs/mcp-endpoint.md
- CHANGELOG.md
tags: []
---

## Objective

Close the per-request account passthrough gap documented as the WP02
caveat: `AgentToolRegistryBridge` takes `AccountInterface` at
construction, but `McpServiceProvider::register()` runs once at boot
with a no-permission placeholder. WP03 refactors `McpEndpoint` to
construct the bridge per-request with the auth-resolved account, then
adds capability-coverage tests proving both the positive path (account
with `bimaaji.read` lists + calls successfully) and the negative path
(account without `bimaaji.mutate` gets the documented forbidden
envelope).

This WP03 is re-scoped per the WP01 audit and the WP02 implementation.
The original spec called for a separate `SessionCapabilities` class
plus net-new `ProposeMutationTool` and `GeneratePatchTool` under
`packages/mcp/src/Tool/Bimaaji/`. Both are obviated:

- **Capability gating is already at the tool layer.**
  `AbstractAgentTool::requireCapability($capability, $account)` checks
  `$account->hasPermission($capability)` and returns the structured
  forbidden envelope. Adding a parallel env-var-driven
  `SessionCapabilities` would create two competing capability sources;
  the cleaner story is "the integrating app's session middleware owns
  account permissions, end of story." The bridge forwards the
  auth-resolved account to each tool's `execute()` call; the tool's
  capability check uses it.
- **The two mutation tools already ship in ai-agent from M2** — see
  M2's WP03 SC-004 contract (`bimaaji_propose_mutation`,
  `bimaaji_generate_patch`). The bridge surfaces them automatically.

## Subtasks

### T008 — Refactor `McpEndpoint` for per-request bridge construction

Change `McpEndpoint::__construct` from `(McpAuthInterface, ToolRegistryInterface,
ToolExecutorInterface)` to `(McpAuthInterface, AgentToolRegistryInterface)` —
take the raw framework-wide registry instead of a pre-built bridge.
`dispatch()` constructs `new AgentToolRegistryBridge($this->agentRegistry,
$account)` after `$this->auth->authenticate(...)` succeeds, then uses that
bridge for the `tools/list` + `tools/call` paths.

`Mcp\Bridge\ToolRegistryInterface` and `Mcp\Bridge\ToolExecutorInterface`
remain available (`@api`) as the bridge's implemented contracts, but
`McpEndpoint` no longer depends on them. Existing
`McpControllerToolsSharingTest` and `McpEndpointTest` are updated to
the new constructor signature.

### T009 — Update `McpServiceProvider::register()` bindings

Drop the WP02-introduced placeholder bindings:

- `Mcp\Bridge\ToolRegistryInterface` → (removed; per-request)
- `Mcp\Bridge\ToolExecutorInterface` → (removed; per-request)
- `Mcp\Bridge\AgentToolRegistryBridge` → (removed; per-request)

Keep `McpAuthInterface` → `BearerTokenAuth(tokens: [])`. Document the
new flow in the provider docblock so future readers understand why
`McpEndpoint` resolves the agent registry from the kernel-services bus
rather than via container resolution of a pre-built bridge.
`McpServiceProviderTest` updated to reflect the simpler binding set.

### T010 — Update `BimaajiMcpReadTest` for closed-loop semantics

The WP02 caveat assertion (`tools/call` returns `forbidden` for the
placeholder account) is now obsolete. Replace with:

- `bridgeIsConstructedPerRequestWithAuthResolvedAccount` — drive
  `McpEndpoint::handle` with an auth fixture returning a
  bimaaji.read-granted account; assert `tools/list` succeeds (≥ 5
  bimaaji tool names) and `tools/call(bimaaji_search_specs)` returns
  a `success` envelope.

### T011 — `BimaajiMcpCapabilityTest`

New `tests/Integration/PhaseN/Mcp/BimaajiMcpCapabilityTest.php`.
End-to-end through `McpEndpoint::handle` with two account fixtures:

1. **Read-only account** (`bimaaji.read` only). `tools/call` on
   `bimaaji_introspect_section` succeeds; `tools/call` on
   `bimaaji_propose_mutation` returns the structured forbidden
   envelope with summary `forbidden` and message containing
   "not permitted".
2. **Read+mutate account**. Both calls succeed (subject to tool
   internals — for the test we use stub tool descriptors that
   short-circuit before reaching real bimaaji surfaces, since real
   validation requires a booted application graph; the assertion is
   on the capability gate, not the tool work).

Asserts the response time of the forbidden path is under 50 ms
(NFR-003's 5 ms target is an aspirational microbenchmark — the
integration test slack accounts for kernel-boot overhead).

## Definition of Done

- [ ] `McpEndpoint::__construct` takes
      `(McpAuthInterface, AgentToolRegistryInterface)`.
- [ ] `McpEndpoint::dispatch` constructs the bridge per-request with
      the auth-resolved account.
- [ ] `McpServiceProvider::register()` drops the placeholder bridge
      bindings; only `McpAuthInterface` remains.
- [ ] `McpEndpointTest`, `McpServiceProviderTest`,
      `BimaajiMcpReadTest`, `McpControllerToolsSharingTest` all
      updated and passing.
- [ ] `BimaajiMcpCapabilityTest` added and passing.
- [ ] `CHANGELOG.md` `[Unreleased]` updated.
- [ ] `docs/specs/mcp-endpoint.md` stamped with the WP03 closing
      note.
- [ ] All local gates pass.

## Risks and notes

- **`tools/list` capability:** the bridge's `getTools()` doesn't
  filter by account; an account with no capabilities still sees the
  full tool descriptor set. This is acceptable — the descriptors are
  metadata, not data. If a future MCP-discovery hardening requires
  per-account filtering, that's a separate WP.
- **NFR-003 5 ms budget:** the original spec's microbenchmark target;
  this WP's integration tests use a relaxed 50 ms budget that
  accounts for PHPUnit + kernel-boot overhead. The actual capability
  check is a single `array_search`-equivalent inside
  `AbstractAgentTool::requireCapability` — fast enough to satisfy
  the spec at the microbenchmark level.
- **Logging (NFR-004) deferred to WP04:** structured
  `{tool, capability_set, outcome}` logging is the doctrine concern;
  the bridge already calls each tool with the account, and audit logs
  are emitted upstream by `AgentExecutor` for agent-driven calls.
  Direct `/mcp` callers will get logging when WP04's spec edits land
  the contract.
