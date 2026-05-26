---
work_package_id: WP01
title: Runs read contracts, adapters, replay service, binding, routes, kernel-boot test
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-008
- NFR-001
- NFR-002
- NFR-003
- C-001
- C-002
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-ai-observability-recent-runs-m5b-01KSEFT9
base_commit: c15e6b8fa21a002f54f57e2814ade14230cee0e8
created_at: '2026-05-25T05:37:06.839801+00:00'
subtasks:
- T001
- T002
- T003
- T004
shell_pid: "71197"
history: []
authoritative_surface: packages/api/src/AiObservability/Runs
execution_mode: code_change
owned_files:
- packages/api/src/AiObservability/Runs/RunListReadModelInterface.php
- packages/api/src/AiObservability/Runs/RunDetailReadModelInterface.php
- packages/api/src/AiObservability/Runs/RunReplayServiceInterface.php
- packages/api/src/AiObservability/Runs/RunListFilter.php
- packages/api/src/AiObservability/Runs/RunListPage.php
- packages/api/src/AiObservability/Runs/RunListRow.php
- packages/api/src/AiObservability/Runs/RunDetail.php
- packages/api/src/AiObservability/Runs/RunSpanNode.php
- packages/api/src/AiObservability/Runs/RunReplayResult.php
- packages/api/src/Controller/AiObservabilityRunsController.php
- packages/api/src/Http/Router/AiObservabilityRunsApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/tests/Unit/Controller/AiObservabilityRunsControllerTest.php
- packages/api/tests/Unit/Http/Router/AiObservabilityRunsApiRouterTest.php
- packages/ai-observability/src/ReadModel/RunListReadModel.php
- packages/ai-observability/src/ReadModel/RunDetailReadModel.php
- packages/ai-observability/src/Replay/RunReplayService.php
- packages/ai-observability/src/ObservabilityServiceProvider.php
- packages/ai-observability/tests/Unit/ReadModel/RunListReadModelTest.php
- packages/ai-observability/tests/Unit/ReadModel/RunDetailReadModelTest.php
- packages/ai-observability/tests/Unit/Replay/RunReplayServiceTest.php
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- tests/Integration/PhaseAiObservability/AiObservabilityRunsEndpointTest.php
tags: []
agent: "claude"
---

# WP01 — Backend: runs read contracts, adapters, replay service, binding, routes, kernel-boot test (M5B)

**Mission:** `ai-observability-recent-runs-m5b-01KSEFT9` (#1415, audit C-L5-02)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Blocked by:** `per-record-ai-access-flagship-01KSEFT5/WP01` MUST be merged before this WP starts — the `_gate: 'ai.trace.replay'` policy registration is the runtime hook this WP wires.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/ai-observability-recent-runs-m5b-01KSEFT9-lane-a
```
(Exact path is printed by `spec-kitty agent action implement WP01`.) Do NOT edit the main worktree.

## THE pattern to mirror (read these before writing anything)

This mission is a **cross-layer** read surface plus one privileged action: `packages/ai-observability` is **Layer 5**, `packages/api` is **Layer 4**, the replay route is gated by an L1 access policy. api may NOT import a higher layer. The shipped pattern is **CodifiedContext** (M5A used it for `AiObservabilityReadModelInterface`):

- READ `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + the M5A DTOs (`ObservabilitySummary`, `ModelUsageRow`, `PipelineUsageRow`) — the api-owned read contract + value DTOs.
- READ `packages/api/src/Controller/AiObservabilityController.php` — nullable dependency, returns empty-shape when null.
- READ `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php` — adapter in L5 implementing the L4 interface; PHP-side JSON-decode aggregation.
- READ `packages/api/src/Http/Router/AiObservabilityApiRouter.php` + the `AiObservabilityApiRouter`/`resolveOptional` block in `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — the router + wiring shape.
- READ `packages/ai-observability/src/ObservabilityServiceProvider.php` — the SP binding shape that the FR-008 dead-code-guard depends on.
- READ `packages/api/src/CodifiedContext/CodifiedContextSessionStoreInterface.php` + `packages/telescope/src/CodifiedContext/Storage/CodifiedContextSessionStoreAdapter.php` — the original CodifiedContext three-tier reference. M5A and M5B both mirror this.
- READ the per-record AI access policy registered by `per-record-ai-access-flagship-01KSEFT5/WP01` — confirm the exact gate name (`ai.trace.replay`) the `_gate` route option binds to.

### Data model (already shipped — you only READ it)

- `trace` entity (type `trace`, group `ai`): `uuid`, `label` (= pipeline name), `status` (`running`|`ok`|`error`), `started_at`, `ended_at`.
- `trace_span` table: `uuid`, `trace_uuid`, `parent_span_uuid`, `kind`, `name`, `started_at`, `ended_at`, `status`, `attributes` (JSON text). For `kind = 'llm_call'`, `attributes` = `{model, input_tokens, output_tokens, cached_tokens, cost_usd}`. Other kinds (`tool_call`, `prompt`, `retrieval`) carry their own JSON.
- `PipelineRegistry` (from `Waaseyaa\AI\Pipeline`) resolves a pipeline by `trace.label` — used by the replay service.

## Subtasks

**T001 — api read contract (interfaces + DTOs)**
- `packages/api/src/AiObservability/Runs/RunListReadModelInterface.php` — `@api`. `recentRuns(RunListFilter $filter, int $page, int $perPage): RunListPage`.
- `packages/api/src/AiObservability/Runs/RunDetailReadModelInterface.php` — `@api`. `findByUuid(string $traceUuid): ?RunDetail`.
- `packages/api/src/AiObservability/Runs/RunReplayServiceInterface.php` — `@api`. `replay(string $traceUuid): RunReplayResult`.
- `packages/api/src/AiObservability/Runs/RunListFilter.php` — readonly. Static `fromQuery(array $query): self` clamps + parses dates.
- `packages/api/src/AiObservability/Runs/RunListPage.php` + `RunListRow.php` + `RunDetail.php` + `RunSpanNode.php` + `RunReplayResult.php` — readonly DTOs. All marked `@api`.

**T002 — api controller + router + SP wiring**
- `packages/api/src/Controller/AiObservabilityRunsController.php` — three actions; empty-shape on null deps; camelCase keys; pagination clamps (`perPage` 1..100 default 25; `page` ≥1 default 1).
- `packages/api/src/Http/Router/AiObservabilityRunsApiRouter.php` — `supports()` matches `_controller` in `{ai.observability.runs.index, ai.observability.runs.show, ai.observability.runs.replay}`; dispatch dispatches on `_controller` to the controller method.
- `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — extend with `resolveOptional` for each of the three interfaces; register the runs router when any binds.

**T003 — ai-observability adapters + SP binding + foundation route**
- `packages/ai-observability/src/ReadModel/RunListReadModel.php` — implements the api list interface. Paginate via repo; aggregate per-trace span-count + cost + tokens via `DatabaseInterface::select()` PHP-side decode. Order `started_at DESC`. Malformed JSON → skip.
- `packages/ai-observability/src/ReadModel/RunDetailReadModel.php` — implements the api detail interface. Read trace + all spans; build tree via `parent_span_uuid`. Recursion bounded at 32 (mark `truncated: true` at boundary).
- `packages/ai-observability/src/Replay/RunReplayService.php` — implements the api replay interface. Resolve `PipelineRegistry` from kernel-services bus; lookup by `trace.label`; read root span `attributes` for inputs; invoke; return `RunReplayResult{newRunUuid, status, startedAt}`.
- `packages/ai-observability/src/ObservabilityServiceProvider.php` — three new bindings (`RunListReadModelInterface` → `RunListReadModel`, etc.). Guard on `observability.enabled` (skip when disabled).
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three new routes; replay route declares BOTH `_role: admin` AND `_gate: 'ai.trace.replay'`. String FQCN controller refs.

**T004 — tests (unit + FR-008 dead-code guard)**
- `packages/api/tests/Unit/Controller/AiObservabilityRunsControllerTest.php` — anonymous-class fakes for each interface → assert mapped camelCase payloads; null deps → zeroed empty shapes; pagination clamp edges.
- `packages/api/tests/Unit/Http/Router/AiObservabilityRunsApiRouterTest.php` — `supports()` true/false for each action; dispatch routes to controller methods; unknown action → 404.
- `packages/ai-observability/tests/Unit/ReadModel/RunListReadModelTest.php` — `DBALDatabase::createSqlite()`, run the trace + trace_span migrations, seed fixtures across pipelines and statuses; assert pagination + filter + ordering + per-row aggregates. Malformed-`attributes` span is skipped, not fatal.
- `packages/ai-observability/tests/Unit/ReadModel/RunDetailReadModelTest.php` — seed nested spans; assert tree shape; assert recursion bound at 32 marks `truncated: true`.
- `packages/ai-observability/tests/Unit/Replay/RunReplayServiceTest.php` — fake `PipelineRegistry`; assert lookup by label + invocation + `RunReplayResult` shape.
- `tests/Integration/PhaseAiObservability/AiObservabilityRunsEndpointTest.php` (`#[CoversNothing]`) — boot full kernel with `observability.enabled = true`, seed ≥3 traces with mixed status across ≥2 pipelines + matching span trees; `GET /api/ai/observability/runs` as admin → assert paginated rows + correct totals; `GET /api/ai/observability/runs/{uuid}` → assert tree shape; `POST /api/ai/observability/runs/{uuid}/replay` → assert new trace uuid; all three as non-admin → 403. **This test MUST fail if any of the three `ObservabilityServiceProvider` bindings from T003 is removed** — verify by hand and report in the WP wrap-up.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/ai-observability/tests/ tests/Integration/PhaseAiObservability/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. Confirm: `rg -n 'Waaseyaa\\\\AI' packages/api/src` returns **nothing** (C-003).
6. Confirm by hand: removing the `RunListReadModelInterface` binding, the `RunDetailReadModelInterface` binding, OR the `RunReplayServiceInterface` binding from `ObservabilityServiceProvider` causes `AiObservabilityRunsEndpointTest` to fail. Note this in the WP report.
7. Confirm the replay route returns 403 for an account that lacks `ai.trace.replay` (use the per-record AI access policy fixtures).

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(api): AI observability runs read contracts + DTOs + controller + router (#1415)`
  - `feat(ai-observability): runs list/detail read models + replay service + SP bindings (#1415)`
  - `feat(foundation): /api/ai/observability/runs routes + replay gate (#1415)`
  - `test(ai-observability): kernel-boot runs + replay integration test (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission ai-observability-recent-runs-m5b-01KSEFT9
  spec-kitty agent tasks move-task WP01 --to for_review --mission ai-observability-recent-runs-m5b-01KSEFT9 --note "M5B backend ready; FR-008 kernel-boot test verified to fail without each of the three SP bindings; replay 403-guard verified by hand"
  ```

## Report back with

- Confirmation that the FR-008 integration test fails when any of the three bindings is removed.
- Confirmation that the replay route returns 403 without the `ai.trace.replay` gate.
- Commit SHAs + final gate output.

## Activity Log

(implementer appends here)
- 2026-05-25T05:37:08Z – claude – shell_pid=71197 – Assigned agent via action command
- 2026-05-25T05:57:36Z – claude – shell_pid=71197 – Moved to for_review
- 2026-05-25T06:07:55Z – claude – shell_pid=71197 – Opus review: lane-a disciplined; WP01 (backend recent-runs + detail + replay with _gate) + WP02 (frontend with paginated list, span timeline honoring truncated marker, filter bar, replay button); 10 Vitest cases pass; specs stamped
- 2026-05-26T18:58:32Z – claude – shell_pid=71197 – Done override: Sprint merge to main
