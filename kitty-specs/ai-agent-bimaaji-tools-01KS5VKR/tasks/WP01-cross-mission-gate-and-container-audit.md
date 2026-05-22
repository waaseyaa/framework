---
work_package_id: WP01
title: Cross-mission gate + container audit
dependencies: []
requirement_refs:
- C-006
- SC-006
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main. Completed changes merge back into main.
subtasks:
- T001
- T002
- T003
history: []
authoritative_surface: tests/Integration/PhaseN/AgentRuntime/
execution_mode: code_change
owned_files:
- tests/Integration/PhaseN/AgentRuntime/BimaajiBindingsAuditTest.php
- packages/bimaaji/src/BimaajiServiceProvider.php
tags: []
---

## Objective

Verify the bimaaji services this mission depends on are container-resolvable after M1. If `ApplicationGraphGenerator`, `MutationValidator`, or `PatchGenerator` is missing a binding, add it to `BimaajiServiceProvider` under a one-line C-002 exception documented in the commit. This is the gate that all later WPs in M2 inherit from.

## Context

M1's `BimaajiServiceProvider` explicitly binds `ApplicationGraphGenerator` and the six default providers. The spec records an assumption that `MutationValidator` + `PatchGenerator` are also container-bound — the M1 plan does not enumerate those bindings, so this WP audits and (if needed) fixes them before the tool wrappers in WP03 try to resolve them.

## Subtasks

### T001 — Smoke test scaffold

Create `tests/Integration/PhaseN/AgentRuntime/BimaajiBindingsAuditTest.php`. Boot a minimal kernel (mirror the pattern from `tests/Integration/PhaseN/Bimaaji/ApplicationGraphIntegrationTest.php` — same stub `KernelServicesInterface` for EntityTypeManager / RouteCollection / SovereigntyConfigInterface). Resolve each of:

- `Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator` (must succeed — M1 guarantee)
- `Waaseyaa\Bimaaji\Mutation\MutationValidator` (verify)
- `Waaseyaa\Bimaaji\Patch\PatchGenerator` (verify — adjust class name to actual)

Use `#[CoversNothing]`; this is a cross-mission contract test, not coverage.

### T002 — Bindings fix (conditional)

If T001 reveals `MutationValidator` or `PatchGenerator` is not bound:

1. Add a singleton factory in `BimaajiServiceProvider::register()` matching the existing `ApplicationGraphGenerator` pattern.
2. Resolve any constructor dependencies via `$this->resolve(...)` or the kernel-services bus.
3. Update the M2 plan's AD-04 footnote to record what was added.
4. Re-run T001 to confirm green.

If both already resolve, this subtask is no-op and the WP01 commit only adds the smoke test.

### T003 — Capability mechanism sanity check

Resolve `Waaseyaa\AI\Agent\Access\AgentRunAccessPolicy` (or equivalent) from the same booted kernel and exercise a single capability check against a known-existing capability string (e.g. `agent.run`). Confirms the mechanism is string-key driven and accepts new strings without registration (M2 R2 mitigation).

## Test strategy

- `BimaajiBindingsAuditTest::resolveBimaajiServices` — asserts each of the three FQCNs resolves.
- `BimaajiBindingsAuditTest::capabilityMechanismAcceptsStringKeys` — asserts the existing capability check returns the expected verdict for a known capability string.

## Definition of Done

- [ ] `BimaajiBindingsAuditTest.php` exists; both tests pass.
- [ ] If a binding was missing, `BimaajiServiceProvider` is updated with the smallest viable factory and the commit message documents the C-002 exception.
- [ ] `composer cs-check`, `bin/check-package-layers`, `bin/check-composer-policy` all pass.
- [ ] `bin/check-dead-code` reports no new entries (the test file is reachable via the integration test runner).

## Risks and notes

- **Bimaaji class names:** Confirm `MutationValidator` and `PatchGenerator` actual FQCNs before writing the audit — they may be under `Waaseyaa\Bimaaji\Mutation\` / `Waaseyaa\Bimaaji\Patch\` per the spec's references section, but verify via `find packages/bimaaji/src/ -name 'MutationValidator*' -o -name 'PatchGenerator*'`.
- **Kernel-boot reuse:** The same stub pattern from M1 WP03 should work here. If a richer kernel is needed, factor it into a `Fixture/MinimalBimaajiKernel.php` helper rather than duplicating the stub in every M2 test.
