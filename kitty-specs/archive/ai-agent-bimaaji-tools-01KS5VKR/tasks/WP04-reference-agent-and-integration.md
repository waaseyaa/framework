---
work_package_id: WP04
title: Reference agent + end-to-end integration
dependencies:
- WP02
- WP03
requirement_refs:
- FR-007
- FR-011
- SC-001
- SC-002
- SC-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main. Completed changes merge back into main.
subtasks:
- T011
- T012
- T013
history: []
authoritative_surface: tests/Integration/PhaseN/AgentRuntime/
execution_mode: code_change
owned_files:
- packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php
- tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRunTest.php
- tests/Integration/PhaseN/AgentRuntime/BimaajiAgentRunCapabilityTest.php
tags: []
---

## Objective

Create the reference agent fixture (`BimaajiDemoAgent`) and the two end-to-end integration tests that exercise the four tools via `AgentExecutor` against a booted kernel. Positive path produces a non-empty `PatchSet`; negative path (capability-restricted agent) surfaces a structured capability error in the audit log.

## Context

This is the first WP that exercises all four tools as a real agent would — through `AgentExecutor`'s run loop, with tool selection, capability checks, and audit logging all live. WP02 and WP03's contract tests cover per-tool correctness; this WP covers tool composition and agent-loop integration.

The reference agent uses fixture entity types registered in the kernel boot (not real `node` / `user` etc., to keep the test self-contained). Mutation operations target the fixture types so the deny-path isn't accidentally tripped by sovereignty defaults.

## Subtasks

### T011 — `BimaajiDemoAgent` fixture

Create `packages/ai-agent/tests/Fixture/BimaajiDemoAgent.php` with `#[AsAgentDefinition(name: 'bimaaji_demo', requires_capability: ['bimaaji.read', 'bimaaji.mutate'])]`. The agent's prompt template instructs it to: introspect entities, propose a mutation, generate a patch. Provider is stubbed (no real Anthropic call) — use the existing test-provider fixture from `packages/ai-agent/tests/`. The fixture must be marked `@api` to satisfy the dead-code gate (it's reachable only via reflection / test runner).

### T012 — `BimaajiAgentRunTest` (positive path, FR-011)

Boot a minimal kernel with `BimaajiDemoAgent` + a fixture entity type registered (`fixture_demo`). Invoke `AgentExecutor::run()` with a fixed prompt. Assert:

- `AgentRun::status === 'completed'`
- Audit log contains tool invocations for at least: `bimaaji_introspect_section`, `bimaaji_propose_mutation`, `bimaaji_generate_patch`
- Final tool result envelope from `bimaaji_generate_patch` has `data.operations` non-empty (PatchSet contains at least one operation)
- No capability errors in the audit log
- SC-001 + SC-002 verified end-to-end

### T013 — `BimaajiAgentRunCapabilityTest` (negative path, SC-003 / FR-010 at the run level)

Same fixture but with a clone of `BimaajiDemoAgent` that has `requires_capability: ['bimaaji.read']` only. Run the executor with a prompt that requires a mutation. Assert:

- `AgentRun::status === 'completed'` (not failed — the capability error is a domain outcome, not an executor crash)
- Audit log contains a tool result envelope with `ok: false, error.code: 'capability_required'`, `error.details.required: 'bimaaji.mutate'`
- No `PatchSet` produced
- SC-003 verified

## Test strategy

Two integration tests under `tests/Integration/PhaseN/AgentRuntime/`. Both use the same minimal-kernel pattern; the only difference is the agent definition's `requires_capability` list. The provider stub returns deterministic tool-call instructions per prompt so the run is reproducible.

## Definition of Done

- [ ] `BimaajiDemoAgent` fixture exists and is `@api`-marked.
- [ ] Both integration tests pass.
- [ ] Positive test produces a non-empty PatchSet (SC-002).
- [ ] Negative test asserts a capability error surface (SC-003).
- [ ] `composer cs-check`, `phpstan`, layer + dead-code + getQuery gates clean.

## Risks and notes

- **Provider stub:** If `ai-agent` already has a deterministic-provider test fixture (likely under `packages/ai-agent/tests/Fixture/` or `Stub/`), reuse it. Otherwise the smallest viable stub is a class implementing the provider interface that returns a hardcoded sequence of tool-call instructions.
- **Kernel boot reuse:** The WP01 audit kernel + the M1 WP03 stub pattern compose cleanly. If a richer boot helper is needed (e.g. real `EntityTypeManager` for the fixture entity), factor it into `tests/Integration/PhaseN/AgentRuntime/Fixture/BimaajiKernelBuilder.php`.
- **Audit log shape:** `AgentRun::audit_log` may be a JSON column on the run entity; assertions should hydrate it via `$run->getAuditLog()` rather than poking at raw DB rows.
