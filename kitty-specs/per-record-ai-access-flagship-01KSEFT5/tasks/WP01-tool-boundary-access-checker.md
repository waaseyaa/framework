---
work_package_id: WP01
title: AI tool boundary — AccessChecker injection + governed-data marker
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- NFR-001
- NFR-002
- NFR-003
- NFR-004
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-per-record-ai-access-flagship-01KSEFT5
base_commit: 6463e7ddfacd57cd606eda5b9f216f3ef16cb280
created_at: '2026-05-25T04:25:28.022411+00:00'
subtasks:
- T001
- T002
- T003
- T004
phase: Phase 1 — Tool boundary OCAP
assignee: ''
agent: "claude:sonnet:implementer:implementer"
shell_pid: "4155912"
history:
- timestamp: '2026-05-25T02:35:50Z'
  agent: system
  action: Prompt generated via /spec-kitty.tasks
authoritative_surface: packages/ai-tools/src/AgentToolInterface.php
execution_mode: code_change
owned_files:
- packages/ai-tools/src/AgentToolInterface.php
- packages/ai-tools/src/AgentToolContext.php
- packages/ai-tools/src/AbstractAgentTool.php
- packages/ai-tools/src/Attribute/Capability.php
- packages/ai-tools/src/Attribute/AsAgentTool.php
- packages/ai-tools/src/Catalogue/AttributeToolRegistry.php
- packages/ai-tools/src/Entity/EntityCreateTool.php
- packages/ai-tools/src/Entity/EntityReadTool.php
- packages/ai-tools/src/Entity/EntityUpdateTool.php
- packages/ai-tools/src/Entity/EntitySearchTool.php
- packages/ai-tools/src/Entity/EntityDeleteTool.php
- packages/ai-tools/src/Entity/EntityListTool.php
- packages/ai-tools/src/Relationship/RelationshipTraverseTool.php
- packages/ai-tools/src/Vector/VectorSearchTool.php
- packages/ai-agent/src/AgentExecutor.php
- packages/ai-agent/src/AiAgentServiceProvider.php
- packages/ai-agent/src/Tool/Bimaaji/IntrospectGraphTool.php
- packages/ai-agent/src/Tool/Bimaaji/IntrospectSectionTool.php
- packages/ai-agent/src/Tool/Bimaaji/SearchSpecsTool.php
- packages/ai-agent/src/Tool/Bimaaji/ProposeMutationTool.php
- tools/phpstan/WaaseyaaEntrypointProvider.php
- packages/ai-tools/tests/Unit/AgentToolContextTest.php
- packages/ai-tools/tests/Unit/Catalogue/AttributeToolRegistryTest.php
- packages/ai-tools/tests/Unit/Entity/EntityReadToolTest.php
- packages/ai-agent/tests/Unit/AgentExecutorTest.php
- tests/Integration/PhasePerRecordAiAccess/AgentToolBoundaryTest.php
- CHANGELOG.md
tags: []
---

# WP01 — AI tool boundary: AccessChecker injection + governed-data marker (M-A5)

**Mission:** `per-record-ai-access-flagship-01KSEFT5` — closes gap-matrix row **A5**. Operational embodiment of charter directive **DIR-004** (OCAP-by-architecture invariant).
**Requirement refs:** FR-001, FR-002, FR-003, FR-004, NFR-001, NFR-002, NFR-003, NFR-004, C-001, C-002. See `spec.md` and `plan.md`.

## THE pattern to mirror (read these before writing anything)

This WP changes a **first-party interface signature** consumed by every shipped agent tool. It is layer-friendly (L5 → L1, already-allowed direction). DO NOT introduce backwards-compatibility shims — per DIR-003 (Greenfield Removal Policy) the alpha posture supports a clean break.

- READ `packages/ai-tools/src/AgentToolInterface.php` — the current contract.
- READ `packages/ai-tools/src/AbstractAgentTool.php` — the default-method base every tool extends.
- READ `packages/ai-tools/src/Attribute/AsAgentTool.php` — for the alternative attribute-parameter mechanism (per **D-D1**).
- READ `packages/ai-tools/src/Catalogue/AttributeToolRegistry.php` — for how attribute discovery currently surfaces ToolDescriptors.
- READ `packages/ai-agent/src/AgentExecutor.php` — for the call-site that invokes `$tool->execute($args, $account)` today.
- READ `packages/access/src/AccessChecker.php` + `packages/access/src/EntityAccessHandler.php` + `packages/access/src/FieldAccessPolicyInterface.php` — the substrate this WP wires into the tool boundary.
- READ `packages/access/src/Gate/PolicyAttribute.php` + `tools/phpstan/WaaseyaaEntrypointProvider.php` — for the discovery-attribute pattern the new `#[Capability]` marker MUST mirror so `bin/check-dead-code` stays green.
- READ `packages/ai-agent/src/Entity/AgentAuditLog.php` — for the audit-emission shape NFR-003 requires.
- READ `docs/specs/access-control.md` and `docs/specs/field-access.md` — the contract being applied to AI surfaces.
- READ the four bimaaji tools at `packages/ai-agent/src/Tool/Bimaaji/{IntrospectGraphTool,IntrospectSectionTool,SearchSpecsTool,ProposeMutationTool}.php` — to verify each is metadata-only and the `governed_data: false` marker is correct (D-D3).

## What you're building

The framework's defining product claim today is "Waaseyaa is OCAP-by-architecture." That claim only holds for human HTTP requests. This WP extends the OCAP substrate to govern **agent tool execution**: every shipped tool that touches user-data MUST consult `AccessChecker` per record; metadata-only tools may opt out via an explicit `#[Capability(governed_data: false)]` marker. The agent runtime constructs an `AgentToolContext` per call carrying `AccountInterface`, `AccessCheckerInterface`, and the current `agentRunId`, and propagates it through `execute()`.

The dead-code-in-production guard is the WP01 integration test (FR-004). Reviewer MUST verify the test fails when the wiring is reverted.

## Implementation phases

### T001 — Interface signature change + context DTO (D-D1)

Pick one of the two D-D1 options and document the choice in your WP report:
- **Option (a)** add `AccessCheckerInterface $accessChecker` as a third parameter to `execute()`.
- **Option (b) — preferred** introduce `Waaseyaa\AI\Tools\AgentToolContext` as a readonly DTO and change the signature to `execute(array $arguments, AgentToolContext $context): AgentToolResult`. The DTO bundles `AccountInterface $account`, `AccessCheckerInterface $accessChecker`, `?string $agentRunId`.

Steps:
1. Implement the chosen change in `packages/ai-tools/src/AgentToolInterface.php`. Carry `@api`.
2. If (b): create `packages/ai-tools/src/AgentToolContext.php`. Readonly class, named-arg constructor. `@api`.
3. Update `packages/ai-tools/src/AbstractAgentTool.php` so `dryRun()` accepts the new shape and `argumentsForAudit()` is unaffected.
4. Add `packages/ai-tools/tests/Unit/AgentToolContextTest.php` (if (b)) covering construction + immutability.

### T002 — Governed-data marker (D-D1 / FR-003) + entrypoint discovery

1. Introduce the opt-out marker. Either:
   - Create `packages/ai-tools/src/Attribute/Capability.php` as a new `#[\Attribute(\Attribute::TARGET_CLASS)]` with `public function __construct(public bool $governedData = true) {}`, OR
   - Extend `packages/ai-tools/src/Attribute/AsAgentTool.php` with a `bool $governedData = true` parameter.
   Document the choice in the WP report.
2. Update `packages/ai-tools/src/Catalogue/AttributeToolRegistry.php` to read the marker per-tool and surface `ToolDescriptor::touchesGovernedData(): bool` (default `true` if the marker is absent).
3. Extend `tools/phpstan/WaaseyaaEntrypointProvider.php` to mark classes carrying the new `Capability` attribute as used (mirror the existing `#[PolicyAttribute]` / `#[AsMiddleware]` handling). This is the codified-gate fix for `bin/check-dead-code`.
4. Add `packages/ai-tools/tests/Unit/Catalogue/AttributeToolRegistryTest.php` covering marker discovery: governed_data=false vs default true.

### T003 — Tool implementations + agent-runtime wiring (FR-002 / FR-003 / NFR-003)

1. For every tool in `packages/ai-tools/src/Entity/` and the relationship / vector tools: update `execute()` to consume the new context and call `EntityAccessHandler::access($entity, $op, $context->account)` per record touched. Records that fail entity-level access are excluded from the result and represented by the structured shape `['accessDenied' => true, 'entityType' => ..., 'id' => ..., 'reason' => 'entity_forbidden_for_account']`. For read tools, also call `EntityAccessHandler::filterFields()` so field-level forbidden fields are shaped consistently with JSON:API.
2. For the four bimaaji tools at `packages/ai-agent/src/Tool/Bimaaji/`: add the `governed_data: false` marker per D-D3. Document the enumeration ("output is application metadata; no entity-data shape") in the WP report.
3. Update `packages/ai-agent/src/AgentExecutor.php`: at each tool invocation, construct `AgentToolContext` from the current account, the kernel `AccessChecker`, and the run id. Pass to `$tool->execute($args, $context)`.
4. Update `packages/ai-agent/src/AiAgentServiceProvider.php` so the executor's constructor resolves `AccessCheckerInterface`.
5. Audit lineage (NFR-003): every `AccessChecker` consultation inside a tool MUST cause an `AgentAuditLog` write carrying `{run_id, tool, entity_type, entity_id, field?, decision}`. The cleanest implementation is for the executor to wrap the supplied checker in a recording decorator that writes per call — implement that decorator inline in `AgentExecutor` or as `packages/ai-agent/src/Access/AuditingAccessChecker.php` (implementer choice).
6. Add `packages/ai-tools/tests/Unit/Entity/EntityReadToolTest.php` and `packages/ai-agent/tests/Unit/AgentExecutorTest.php` covering per-record gating + audit-log emission.

### T004 — Boundary integration test (FR-004) + CHANGELOG

1. Create `tests/Integration/PhasePerRecordAiAccess/AgentToolBoundaryTest.php`:
   - Boot the kernel with a real `AccessChecker` and a test `AccessPolicy` that allows account A on a `node` and denies account B.
   - Resolve `AgentExecutor`; invoke `EntityReadTool` via the executor for both accounts against the same node id.
   - Assert: account A receives `{data: <node attributes>}`; account B receives the structured `accessDenied` shape.
   - Add a parallel assertion that an `AgentAuditLog` entry exists for both calls.
   - This test MUST fail with a clear message if `AgentExecutor` is reverted to invoking `$tool->execute($args, $account)` without the context.
2. Update `CHANGELOG.md` `[Unreleased]` → **Added**: `ai-tools / ai-agent: AccessChecker injected into every AgentToolInterface::execute() via AgentToolContext; per-record OCAP enforced at the tool boundary; #[Capability(governed_data: false)] marker for application-metadata-only tools. (gap-matrix-A5)`.
3. Run the WP verification gate (below) and confirm green.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/ai-tools/tests/ packages/ai-agent/tests/ tests/Integration/PhasePerRecordAiAccess/AgentToolBoundaryTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`

## Commit + handoff

Commits (footer `Refs gap-matrix-A5` on each):
- `feat(ai-tools): inject AccessChecker via AgentToolContext + #[Capability] marker (gap-matrix-A5)`
- `feat(ai-tools): per-record OCAP in entity / relationship / vector tools (gap-matrix-A5)`
- `feat(ai-agent): executor constructs AgentToolContext + audits AccessChecker calls (gap-matrix-A5)`
- `feat(ai-agent): bimaaji tools opt out via governed_data: false (gap-matrix-A5)`
- `test(integration): AgentToolBoundaryTest as FR-004 dead-code guard (gap-matrix-A5)`

Then:
```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission per-record-ai-access-flagship-01KSEFT5
spec-kitty agent tasks move-task WP01 --to for_review --mission per-record-ai-access-flagship-01KSEFT5 --note "WP01 tool boundary wired; FR-004 boundary test verified to fail without the AgentToolContext propagation"
```

## Report back with

- D-D1 choice (option (a) or (b)) + 1-sentence rationale.
- The exact attribute shape chosen for the `Capability` marker (standalone attribute vs parameter on `#[AsAgentTool]`).
- D-D3 bimaaji-tool enumeration: for each of the four tools, one sentence answering "does this tool's output expose entity-data-shaped content OR mutate governed user data?" and the marker decision.
- Confirmation FR-004 test was run with the wiring reverted and observed to fail.
- Any new `getQuery()->execute()` chains introduced; confirm they all carry `setAccount()`.
- Any layer-graph violations PHPStan flagged + how they were resolved.

## Activity Log

- 2026-05-25T04:25:29Z – claude:sonnet:implementer:implementer – shell_pid=4155912 – Assigned agent via action command
- 2026-05-25T04:52:29Z – claude:sonnet:implementer:implementer – shell_pid=4155912 – Ready for review: AgentToolContext DTO + Capability attribute wired into all 12 tool impls; bimaaji tools opt out via governed_data:false; executor builds context per call; all contract+unit tests updated to AgentToolContext signature
- 2026-05-25T04:57:16Z – claude:sonnet:implementer:implementer – shell_pid=4155912 – Opus review: 4 commits in lane-a match WP01 plan chunks (AgentToolContext+Capability, per-record OCAP, AgentExecutor wiring, bimaaji opt-outs). Structure clean. Implementer notes tests compile but local PHP 8.4 cannot execute (project needs 8.5; CI is the actual gate). FR-007 dead-code-guard test presence flagged for CI verification. Merge held pending OCAP-audit substrate landing (PL002 LAYER_BY_SHORT update from analytics→audit rename).
- 2026-05-26T18:52:31Z – claude:sonnet:implementer:implementer – shell_pid=4155912 – Done override: Sprint merge to main
