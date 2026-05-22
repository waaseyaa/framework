---
work_package_id: WP02
title: Read tools — IntrospectGraphTool + IntrospectSectionTool
dependencies:
- WP01
requirement_refs:
- FR-001
- FR-002
- FR-005
- FR-006
- FR-008
- FR-009
- FR-010
- NFR-001
- NFR-003
- NFR-004
- C-001
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main. Completed changes merge back into main.
subtasks:
- T004
- T005
- T006
- T007
history: []
authoritative_surface: packages/ai-agent/src/Tool/Bimaaji/
execution_mode: code_change
owned_files:
- packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php
- packages/ai-agent/src/Tool/Bimaaji/IntrospectSectionTool.php
- packages/ai-agent/tests/Contract/Bimaaji/IntrospectGraphToolTest.php
- packages/ai-agent/tests/Contract/Bimaaji/IntrospectSectionToolTest.php
- packages/ai-agent/tests/Contract/Bimaaji/BimaajiToolCapabilityTest.php
tags: []
---

## Objective

Ship the two read-only tools (`IntrospectGraphTool`, `IntrospectSectionTool`) plus the shared capability-gating contract test. Both tools wrap M1's `ApplicationGraphGenerator` via the AD-03 result envelope. The capability test is colocated here because it asserts the *gate*, which is shared across all four mission tools.

## Context

Both tools extend `Waaseyaa\AI\Tools\AbstractAgentTool` (verify exact base class — spec references `packages/ai-tools/src/AbstractAgentTool.php`) and carry `#[AsAgentTool]` with `capability: 'bimaaji.read'`. Discovery is automatic via `PackageManifestCompiler` attribute scan (FR-005). Constructor takes `ApplicationGraphGenerator` (resolved from the container).

The AD-03 envelope wraps `ApplicationGraph::toArray()` (full graph) or `GraphSection::toArray()` (single section). Unknown-section error envelopes mirror M1's `graph:dump` error path: `error.code: 'unknown_section'`, `error.details.available: [...keys]`.

## Subtasks

### T004 — `IntrospectGraphTool`

Create `packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php`. Constructor takes `ApplicationGraphGenerator $generator`. `execute(array $arguments = []): array` calls `$generator->generate()->toArray()`, wraps in the AD-03 envelope with `meta.tool = 'bimaaji_introspect_graph'`. Mark with `#[AsAgentTool(name: 'bimaaji_introspect_graph', capability: 'bimaaji.read', destructive: false)]`.

### T005 — `IntrospectSectionTool`

Same skeleton but takes a `section: string` argument (validate non-empty). On hit: return `GraphSection::toArray()` for that key. On miss: return the AD-03 failure envelope with `error.code: 'unknown_section'` and `error.details.available_sections: [...]`.

### T006 — Contract tests for both tools

`IntrospectGraphToolTest`:
- `executesAgainstBootedKernel` (FR-008): boot the WP01 audit kernel, resolve the tool, call `execute([])`, assert all 6 default section keys in `data.sections`.
- `envelopeShapeIsStable` (NFR-003): round-trip the result through `json_encode/json_decode(JSON_THROW_ON_ERROR)`; assert structural equality.
- `completesUnder200msMedian` (NFR-001): 5 warm-up + 20 measured invocations; assert median ≤ 200 ms. Soft warning to stderr if measured > 100 ms (M1's NFR-001 baseline).

`IntrospectSectionToolTest`:
- `returnsScopedSection` (FR-002): `execute(['section' => 'routing'])` returns only the `routing` section.
- `reportsUnknownSection` (FR-002 error path): `execute(['section' => 'nonexistent'])` returns `ok: false, error.code: 'unknown_section'`, `error.details.available_sections` lists the 6 known keys.

### T007 — Capability-gating contract test

`BimaajiToolCapabilityTest::missingCapabilityRejects` (FR-006, FR-010, NFR-004): Run an `AgentExecutor` invocation against a fixture agent whose `requires_capability` excludes `bimaaji.read`; assert `IntrospectGraphTool` invocation returns `ok: false, error.code: 'capability_required'` within 5 ms. Use the existing capability-test infrastructure under `packages/ai-agent/tests/` rather than building new fixtures (C-003).

## Test strategy

- 2 contract tests for `IntrospectGraphTool` (booted kernel + envelope + timing)
- 2 contract tests for `IntrospectSectionTool` (positive scope + unknown-section error)
- 1 capability test shared across both tools (placed in this WP so the gate contract lands with the first consumers)

## Definition of Done

- [ ] Both tool classes exist and are auto-discovered by `bin/waaseyaa optimize:manifest`.
- [ ] All 5 contract tests pass.
- [ ] `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code` clean.
- [ ] No `use` imports from layers above L5 (L4 bimaaji is allowed, downward).

## Risks and notes

- **Discovery contract:** Verify the auto-discovery path is `PackageManifestCompiler` attribute scan rather than a manifest list in `ai-agent`'s `composer.json` extras. If the latter, this WP adds the FQCNs to the manifest list.
- **AbstractAgentTool location:** Spec references `packages/ai-tools/src/AbstractAgentTool.php`. ai-tools is L5 (alongside ai-agent). Confirm the layer table — if AbstractAgentTool actually lives at L5, both tools `use` it without layer trouble. If at L6, fall back to inline-FQCN pattern (see M1 WP02 for the same trick with `\Waaseyaa\CLI\CommandDefinition`).
