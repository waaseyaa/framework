---
work_package_id: WP03
title: Mutation tools — ProposeMutationTool + GeneratePatchTool
dependencies:
- WP01
requirement_refs:
- FR-003
- FR-004
- FR-005
- FR-009
- NFR-002
- NFR-003
- C-001
- C-005
- SC-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts generated on main. Implementation may branch from main. Completed changes merge back into main.
subtasks:
- T008
- T009
- T010
history: []
authoritative_surface: packages/ai-agent/src/Tool/Bimaaji/
execution_mode: code_change
owned_files:
- packages/ai-agent/src/Tool/Bimaaji/ProposeMutationTool.php
- packages/ai-agent/src/Tool/Bimaaji/GeneratePatchTool.php
- packages/ai-agent/tests/Contract/Bimaaji/ProposeMutationToolTest.php
- packages/ai-agent/tests/Contract/Bimaaji/GeneratePatchToolTest.php
tags: []
---

## Objective

Ship the two mutation-side tools. Both carry `capability: 'bimaaji.mutate'` and `destructive: false` (per AD-02 — neither writes to disk). `ProposeMutationTool` wraps `MutationValidator::validate()`; `GeneratePatchTool` wraps `PatchGenerator::generate()`. The headline safety invariant (C-005, SC-005) is that `GeneratePatchTool` never touches the filesystem — the consuming agent loop decides what to do with the returned `PatchSet`.

## Context

Both tools extend the same `AbstractAgentTool` base used by WP02. Constructors take their respective bimaaji service (resolved from the container, audited by WP01). The result envelope wraps `MutationResult::toArray()` and `PatchSet::toArray()` respectively. Sovereignty deny paths surface in the envelope as `ok: true, data: { valid: false, denied_profile: 'Local', reason: '...' }` — the deny is a domain outcome, not a tool failure.

## Subtasks

### T008 — `ProposeMutationTool`

Create `packages/ai-agent/src/Tool/Bimaaji/ProposeMutationTool.php`. Arguments: `operation: string`, `entity_type: string`, `field?: string`, `parameters?: array` (mirrors `TaskDefinition`). Constructor takes `MutationValidator`. `execute()` calls `validator->validate(new TaskDefinition(...))` and wraps the `MutationResult::toArray()` in the AD-03 envelope. Mark with `#[AsAgentTool(name: 'bimaaji_propose_mutation', capability: 'bimaaji.mutate', destructive: false)]`.

### T009 — `GeneratePatchTool`

Same skeleton but constructor takes `PatchGenerator`. Arguments mirror `ProposeMutationTool` plus optionally a pre-validated `MutationResult` (so the agent can chain). Calls `patchGenerator->generate(...)` and wraps `PatchSet::toArray()`. Mark with `#[AsAgentTool(name: 'bimaaji_generate_patch', capability: 'bimaaji.mutate', destructive: false)]`. **Must not write to the filesystem under any code path** — assert this in the test.

### T010 — Contract tests for both tools

`ProposeMutationToolTest`:
- `delegatesToValidator` (FR-003): assert `MutationValidator::validate()` is called with the constructed `TaskDefinition`; assert returned envelope wraps `MutationResult::toArray()`.
- `sovereigntyDenyPath` (spec edge case): construct a profile that denies the operation; assert envelope is `ok: true, data: { valid: false, denied_profile: <name>, reason: <str> }` — no information leak beyond what `MutationResult::toArray()` exposes.
- `overheadUnder50ms` (NFR-002): microbenchmark — run `validator->validate()` direct vs. the tool's `execute()`, 20 iterations each. Assert tool overhead ≤ 50 ms median. Soft warning + hard ceiling at 250 ms.
- `envelopeShapeIsStable` (NFR-003): JSON round-trip assertion.

`GeneratePatchToolTest`:
- `delegatesToPatchGenerator` (FR-004): assert `PatchGenerator::generate()` is called; assert envelope wraps `PatchSet::toArray()`.
- `doesNotWriteToFilesystem` (SC-005): snapshot the contents of a temp directory before + after `execute()`; assert no files created, modified, or deleted. Use `sys_get_temp_dir() . '/m2-wp03-' . uniqid()` as the snapshot root (don't sandbox the whole test — just verify no incidental writes).
- `envelopeShapeIsStable` (NFR-003): JSON round-trip assertion.
- `chainsFromPreValidatedResult`: if the tool accepts an optional pre-validated `MutationResult` arg, assert it short-circuits the validator call. If the implementation re-validates instead, document the choice in the test comment.

## Test strategy

- 4 contract tests for `ProposeMutationTool`
- 4 contract tests for `GeneratePatchTool` (including the no-filesystem-write invariant)
- All envelope assertions use `json_encode/json_decode(JSON_THROW_ON_ERROR)`

## Definition of Done

- [ ] Both tool classes exist and are auto-discovered.
- [ ] All 8 contract tests pass.
- [ ] `GeneratePatchToolTest::doesNotWriteToFilesystem` asserts a clean before/after snapshot (SC-005).
- [ ] NFR-002 overhead microbenchmark recorded in PR description.
- [ ] `composer cs-check`, `composer phpstan`, layer + dead-code + getQuery gates clean.

## Risks and notes

- **`TaskDefinition` shape:** Verify the actual constructor signature of `TaskDefinition` before writing the tool — argument names may differ from the spec's `operation/entity_type/field/parameters`. Inspect `packages/bimaaji/src/Mutation/TaskDefinition.php` first.
- **PatchGenerator side effects:** Even though the spec is explicit that `PatchSet` is returned in-memory, double-check `PatchGenerator::generate()` doesn't write any intermediate files (e.g. tempfile cache). The SC-005 snapshot test catches it either way.
- **Chained-validation overhead:** If `GeneratePatchTool` re-validates rather than accepting the pre-validated result, NFR-002's overhead budget effectively doubles for the chain. Note in WP05 verification.md.
