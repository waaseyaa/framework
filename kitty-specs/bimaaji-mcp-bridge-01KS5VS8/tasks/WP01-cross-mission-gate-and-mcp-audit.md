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
- packages/mcp/src/ServiceProvider.php
tags: []
---

## Objective

Audit `packages/mcp/`'s readiness to host the bimaaji tool family. Confirm a tool-registration mechanism exists (attribute, service-tag, or registry-add API); confirm stdio transport handles the expected payload sizes; confirm name-uniqueness gating at registration time (NFR-005). If any of these is missing, add the minimum needed before WP02 begins.

## Subtasks

### T001 — `packages/mcp/` registration mechanism audit

Inspect `packages/mcp/src/` for the existing tool-registration entry point. Document findings in a comment block at the top of `BimaajiMcpBootSmokeTest`:

- How are MCP tools registered? (attribute scan via `PackageManifestCompiler`? Service-tag in ServiceProvider? Explicit registry-add?)
- Does the registry enforce name uniqueness? If not, add the assertion.
- Is the boot-time overhead measurable? Plan WP01 bench: register 10 dummy tools, measure boot delta — assert ≤ 10 ms (NFR-001).

If a registration mechanism exists, no source change in WP01. If not, add the minimum: an attribute (`#[AsMcpTool]`) + a registrar that scans for it. The mechanism must exist before WP02 can land any bimaaji tool.

### T002 — `BimaajiMcpBootSmokeTest`

Boot a minimal kernel + MCP server harness. Assert:

1. The server boots without errors.
2. The tool registry is reachable (`$registry->all()` returns at least the framework's existing tool set).
3. NFR-001 boot-time budget: registering 10 dummy `bimaaji_smoke_*` tools adds ≤ 10 ms (median over 5 runs).
4. NFR-005 uniqueness: attempting to register two tools with the same name throws or returns a structured error.

Use `#[CoversNothing]`.

### T003 — Cross-mission gate against M2

Import M2's tool argument schemas (as documented in M2's `WP05-docs-and-verify.md` verification.md). If M2 is not yet merged when WP01 lands, read M2's `tasks/WP02-read-tools.md` + `tasks/WP03-mutation-tools.md` for the canonical envelope shape and record the contract in `BimaajiMcpBootSmokeTest`'s class-level docblock so WP02/WP03 implementations match.

The cross-mission gate test asserts that each M2 tool FQCN resolves (`class_exists()`) when M2 has merged. If M2 is parallel-in-flight, the test is conditional: skip with a clear "M2 not yet merged — using M2 spec contract directly" message.

## Definition of Done

- [ ] `BimaajiMcpBootSmokeTest` exists; all assertions pass.
- [ ] If `packages/mcp/` lacked a registration mechanism, the minimum addition lands here.
- [ ] Boot-time NFR-001 budget is measured and recorded in the test.
- [ ] M2 cross-reference is documented in the test docblock.
- [ ] All local gates pass (cs-check, phpstan, layer, composer-policy, dead-code, getQuery).

## Risks and notes

- **mcp package maturity:** Per spec assumption, mcp may need substantial scaffolding. If WP01's audit reveals the gap is bigger than a few classes, raise a re-scope decision in the WP01 commit message — better to land the registration mechanism as its own mini-mission than to bury it in WP01.
- **Coordination with M2:** Communicate with whoever lands M2's WP05 before this WP01 commits — the cross-mission contract anchor lives in M2's verification.md.
