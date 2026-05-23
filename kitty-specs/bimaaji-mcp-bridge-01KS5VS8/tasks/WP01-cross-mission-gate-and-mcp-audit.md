---
work_package_id: WP01
title: Cross-mission gate + MCP infrastructure audit
dependencies: []
requirement_refs:
- NFR-001
- NFR-005
- C-001
- C-006
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts on main. Implementation may branch from main.
subtasks:
- T001
- T002
- T003
history: []
authoritative_surface: tests/Integration/PhaseN/Mcp/
execution_mode: code_change
owned_files:
- tests/Integration/PhaseN/Mcp/BimaajiMcpBootSmokeTest.php
- packages/foundation/src/Http/Router/McpRouter.php
- packages/foundation/src/Kernel/HttpKernel.php
- packages/foundation/tests/Unit/Http/Router/McpRouterTest.php
- docs/specs/mcp-endpoint.md
tags: []
---

## Objective

Audit `packages/mcp/` for tool-registration readiness, then land the minimum
artefacts proving the infrastructure already supports the M2 bimaaji surface.
Retire the dead foundation `McpRouter` intercept that pre-dated the
`McpEndpoint` + `AgentToolRegistryBridge` architecture. Pin the M2 SC-004
contract in a smoke test that downstream WPs (WP02 read-tool inventory, WP03
capability gating) cannot regress.

This WP01 has been re-scoped after the audit (see `plan.md` § "WP01 re-scope
rationale"). The original spec assumed `packages/mcp/` had no tool-registration
mechanism. The audit found a complete attribute-based registration system in
place, plus a fully-written `AgentToolRegistryBridge` adapter waiting to be
wired. The remaining WPs likewise shrink — see the plan for the new WP02–WP05
shape.

## Subtasks

### T001 — `packages/mcp/` registration mechanism audit (DONE)

Audit findings, recorded here for posterity (also reflected in the SmokeTest
docblock):

- **Registration mechanism:** `#[AsAgentTool]` attribute on classes
  implementing `Waaseyaa\AI\Tools\AgentToolInterface`. Discovery via
  `PackageManifestCompiler` populates the `agent_tools` manifest section.
  `AttributeToolRegistry` (bound by `AiToolsServiceProvider`) hydrates the
  catalogue lazily on first access.
- **MCP-side adapter:** `Waaseyaa\Mcp\Bridge\AgentToolRegistryBridge`
  implements both `Mcp\Bridge\ToolRegistryInterface` and
  `Mcp\Bridge\ToolExecutorInterface`. It wraps the framework-wide
  `AgentToolRegistryInterface` and forwards the per-request `AccountInterface`
  to every `execute()` call.
- **Name uniqueness:** `AttributeToolRegistry::register()` overwrites by name;
  the hydration loop short-circuits with `if (!isset($this->tools[$tool->name]))`.
  Hand-registered tools win over discovery (test-and-override pattern). M2's
  four bimaaji tools own unique `bimaaji_*` names; the contract is preserved
  by reflection assertions in the smoke test.
- **Pre-existing dead intercept:** `packages/foundation/src/Http/Router/McpRouter.php`
  short-circuits when `$_controller === 'mcp.endpoint'` (literal string). The
  new `McpRouteProvider` registers the route with `_controller =
  'Waaseyaa\\Mcp\\McpEndpoint::handle'`, so the foundation router never matches
  a real request — only artificial unit-test fixtures set the legacy string.
  Production dispatch flows through SSR's `AppControllerRouter`. The foundation
  router is retired in this WP.

### T002 — `BimaajiMcpBootSmokeTest`

Pure-reflection test pinning the SC-004 surface contract from M2's
`verification.md`. Asserts:

1. The four M2 bimaaji tool FQCNs exist (`class_exists`).
2. Each implements `AgentToolInterface`.
3. Each carries exactly one `#[AsAgentTool]` attribute with the SC-004
   `name` and `capability` rows.
4. Tool names are unique within the SC-004 surface.

Bridge construction is not exercised here; that path is proven end-to-end by
the existing `tests/Integration/PhaseN/AgentRuntime/McpControllerToolsSharingTest.php`.

Uses `#[CoversNothing]`. Lives in `tests/Integration/PhaseN/Mcp/`.

### T003 — Cross-mission gate against M2 (DONE — M2 merged)

M2 (`ai-agent-bimaaji-tools-01KS5VKR`) shipped at commits 87ddfe6a3 / de185c1c4 /
6c6da5c4b / 48348fde2 / cd5fa02c9 — five PRs, fully merged. SC-004's anchor
verification lives at
`kitty-specs/ai-agent-bimaaji-tools-01KS5VKR/verification.md`. The smoke test
references this anchor in its class-level docblock and asserts the four tool
contracts so any future M2 drift breaks WP01's gate before WP02 lands.

## Definition of Done

- [ ] `BimaajiMcpBootSmokeTest` exists; all reflection assertions pass.
- [ ] Foundation `McpRouter` deleted; HttpKernel router array no longer
      references it.
- [ ] Foundation `McpRouterTest` deleted.
- [ ] `docs/specs/mcp-endpoint.md` carries a reviewed stamp citing WP01.
- [ ] All local gates pass (cs-check, phpstan, layer, composer-policy,
      dead-code, getQuery, surface-map).

## Risks and notes

- **Bridge account is per-request.** `AgentToolRegistryBridge` takes
  `AccountInterface` at construction; `McpEndpoint::handle` receives the typed
  account from `AppControllerRouter` but does not currently forward it into the
  bridge. WP01 documents this as a known gap deferred to WP03 capability
  gating, which owns end-to-end auth → bridge passthrough. The smoke test does
  not exercise this path.
- **Legacy `McpController` and its `Tools/`/`Cache/`/`Rpc/` siblings remain in
  the repo**, kept alive by direct-instantiation tests in
  `tests/Integration/Phase14/AiMcpIntegrationTest.php` and the package's own
  unit tests. They're now unreachable via HTTP dispatch. A future cleanup
  mission may delete them; WP01 does not touch them to keep blast radius
  bounded.
