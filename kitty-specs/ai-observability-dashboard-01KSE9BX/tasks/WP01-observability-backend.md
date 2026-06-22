---
work_package_id: WP01
title: AI observability read contract, adapter, binding, route, kernel-boot test
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- NFR-001
- NFR-002
- C-001
- C-002
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-ai-observability-dashboard-01KSE9BX
base_commit: c8c31f146eac38d1dbb757cddd4345db19ecbaae
created_at: '2026-05-25T01:18:16.156123+00:00'
subtasks: []
agent: claude:opus:reviewer:reviewer
shell_pid: '450032'
history: []
authoritative_surface: packages/api/src/AiObservability
execution_mode: code_change
mission_id: 01KSE9BXVFDDBGCPZ4KN1F8YPM
owned_files:
- packages/api/src/AiObservability/AiObservabilityReadModelInterface.php
- packages/api/src/AiObservability/ObservabilitySummary.php
- packages/api/src/AiObservability/ModelUsageRow.php
- packages/api/src/AiObservability/PipelineUsageRow.php
- packages/api/src/Controller/AiObservabilityController.php
- packages/api/src/Http/Router/AiObservabilityApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/composer.json
- packages/api/tests/Unit/Controller/AiObservabilityControllerTest.php
- packages/api/tests/Unit/Http/Router/AiObservabilityApiRouterTest.php
- packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php
- packages/ai-observability/src/ObservabilityServiceProvider.php
- packages/ai-observability/tests/Unit/ReadModel/AiObservabilityReadModelTest.php
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php
tags: []
wp_code: WP01
---

# WP01 — Backend: read contract, adapter, binding, route, kernel-boot test (M5A)

**Mission:** `ai-observability-dashboard-01KSE9BX` (#1415, audit C-L5-01)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/ai-observability-dashboard-01KSE9BX-lane-a
```
(Exact path is printed by `spec-kitty agent action implement WP01`.) The lane worktree has **no `vendor/`** — run `composer install` first.

## THE pattern to mirror (read these before writing anything)

This is a **cross-layer** surface: `packages/ai-observability` is **Layer 5**, `packages/api` is **Layer 4**. api may NOT import a higher layer. Do NOT copy the WorkflowGuards approach (it injected an L3 service into L4). Copy **CodifiedContext**, which solves this exact problem for telescope (L6):

- READ `packages/api/src/CodifiedContext/CodifiedContextSessionStoreInterface.php` + `CodifiedContextSessionRow.php` — the api-owned read contract + DTO.
- READ `packages/api/src/Controller/CodifiedContextController.php` — nullable store, returns `['data' => []]` when null.
- READ `packages/telescope/src/CodifiedContext/Storage/CodifiedContextSessionStoreAdapter.php` — adapter in the higher layer implementing the api interface (L5/L6 → L4 downward = allowed).
- READ `packages/api/src/Http/Router/WorkflowGuardsApiRouter.php` + the `httpDomainRouters()` method in `packages/api/src/ApiServiceProvider.php` (the `WorkflowGuardsApiRouter`/`resolveOptional` block) — the router + wiring shape.
- READ `packages/ai-observability/src/ObservabilityServiceProvider.php`, `Recorder/TraceRecorder.php`, `Cost/CostTracker.php`, `Cost/TokenAccountant.php`, and `migrations/2026_04_14_000001_create_trace_span_table.php` — the data you are aggregating.

### Data model (already shipped — you only READ it)
- `trace` entity (type `trace`, group `ai`): `uuid`, `label` (= pipeline name), `status` (`running`|`ok`|`error`), `started_at`, `ended_at`.
- `trace_span` table: `uuid`, `trace_uuid`, `kind`, `name`, `started_at`, `ended_at`, `status`, `attributes` (JSON text). For `kind = 'llm_call'`, `attributes` = `{model, input_tokens, output_tokens, cached_tokens, cost_usd}`.

## Subtasks

**T001 — api read contract + DTOs + controller + router**
- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` — `summary(): ObservabilitySummary`, `byModel(): array`, `byPipeline(): array`. Class-level `@api`. **No `use Waaseyaa\AI\*` — ever (C-003).**
- DTOs (readonly, `@api`): `ObservabilitySummary(totalRuns, totalCostUsd, totalTokens, errorRate, runningCount, ?periodFrom, ?periodTo)`; `ModelUsageRow(model, runs, inputTokens, outputTokens, totalTokens, costUsd, avgLatencyMs, errorRate)`; `PipelineUsageRow(pipeline, runs, totalTokens, costUsd, avgLatencyMs, errorRate)`.
- `Controller/AiObservabilityController.php` — `__construct(private readonly ?AiObservabilityReadModelInterface $readModel = null)`. `index(): array` → `{data: {summary: {...}, byModel: [...], byPipeline: [...]}}`, **camelCase**. When `$readModel === null`, return the zeroed empty shape (summary all-zero, empty arrays). Map DTOs → arrays. Does NOT re-check role.
- `Http/Router/AiObservabilityApiRouter.php` — mirror `WorkflowGuardsApiRouter`: `supports()` matches `'AiObservabilityController::'`; `handle()` dispatches `index`; JSON:API error envelope; `application/vnd.api+json`.

**T002 — ApiServiceProvider wiring + composer require-dev**
- `ApiServiceProvider.php` — `use Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface;` (api-local, fine). In `httpDomainRouters()` add: `$readModel = $this->resolveOptional(AiObservabilityReadModelInterface::class); if ($readModel instanceof AiObservabilityReadModelInterface) { $routers[] = new AiObservabilityApiRouter(new AiObservabilityController($readModel)); }`.
- `packages/api/composer.json` — add `"waaseyaa/ai-observability": "^0.1.0-alpha.188"` to **`require-dev`** (NOT `require` — C-003/NFR-001) and `{"type":"path","url":"../ai-observability"}` to `repositories` if absent. Run `composer update --lock waaseyaa/ai-observability` in the lane.

**T003 — ai-observability adapter + binding + route**
- `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php implements Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface`. Inject the `trace` repository (via `EntityTypeManager::getRepository('trace')`), `DatabaseInterface`, and `CostTracker` (or sum cost inline). Aggregate per FR-002. JSON decode with `try/catch (\JsonException) { continue; }` (mirror `CostTracker::sumCostFromRows`). Latency in ms; exclude rows with null `ended_at` from averages. Use `DatabaseInterface::select()` — never `getQuery()`.
- `ObservabilityServiceProvider::register()` — add `$this->singleton('Waaseyaa\\Api\\AiObservability\\AiObservabilityReadModelInterface', fn() => ...)` returning the real adapter when `observability.enabled` (default true), and an empty-shape instance (or skip the binding) when disabled — match the `TraceRecorderInterface` enabled-guard style already in this file.
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — add `api.ai.observability.index` → `GET /api/ai/observability`, `->requireRole('admin')`, `->methods('GET')`, controller **string FQCN** `'Waaseyaa\\Api\\Controller\\AiObservabilityController::index'`. Place after the workflow-guards block.

**T004 — tests (unit + the FR-007 dead-code guard)**
- `packages/api/tests/Unit/Controller/AiObservabilityControllerTest.php` — anonymous-class fake read model → asserts mapped camelCase payload; null read model → zeroed empty shape.
- `packages/api/tests/Unit/Http/Router/AiObservabilityApiRouterTest.php` — `supports()` true/false; `index` dispatch; unknown action → 404.
- `packages/ai-observability/tests/Unit/ReadModel/AiObservabilityReadModelTest.php` — `DBALDatabase::createSqlite()`, run the trace_span migration + register the `trace` entity type, seed ≥2 traces + llm_call spans across ≥2 models/pipelines, assert byModel/byPipeline/summary numbers; a malformed-`attributes` span is skipped, not fatal.
- `tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php` (`#[CoversNothing]`) — boot the full kernel with `observability.enabled = true`, seed real traces + spans, `GET /api/ai/observability` as **admin** → assert non-empty `byModel`/`byPipeline` + correct summary; as **non-admin** → 403. This test MUST fail if the `ObservabilityServiceProvider` binding in T003 is removed — verify that by hand and report it.

## Verification gate (in lane worktree)
1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/ai-observability/tests/ tests/Integration/PhaseAiObservability/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. Confirm: `rg -n 'Waaseyaa\\\\AI' packages/api/src` returns **nothing** (C-003).

## Commit + handoff
- Commits (footer `Refs #1415` on each):
  - `feat(api): AI observability read contract + controller + router (#1415)`
  - `feat(ai-observability): observability read-model adapter + SP binding (#1415)`
  - `feat(foundation): /api/ai/observability route (#1415)`
  - `test(ai-observability): kernel-boot dashboard integration test (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission ai-observability-dashboard-01KSE9BX
  spec-kitty agent tasks move-task WP01 --to for_review --mission ai-observability-dashboard-01KSE9BX --note "M5A backend ready; FR-007 kernel-boot test verified to fail without the SP binding"
  ```

## Report back with
1. Commit SHAs.
2. Confirmation `rg 'Waaseyaa\\AI' packages/api/src` is empty (C-003).
3. The exact binding line added to `ObservabilityServiceProvider`.
4. Proof the FR-007 integration test fails when that binding is removed (paste the failing assertion).
5. The endpoint's actual JSON payload for the seeded fixture.

## Activity Log
- 2026-05-25T01:18:17Z – claude:sonnet:implementer:implementer – shell_pid=443081 – Assigned agent via action command
- 2026-05-25T01:38:31Z – claude:sonnet:implementer:implementer – shell_pid=443081 – Moved to for_review
- 2026-05-25T01:55:14Z – claude:opus:reviewer:reviewer – shell_pid=450032 – Started review via action command
- 2026-05-25T01:57:26Z – claude:opus:reviewer:reviewer – shell_pid=450032 – Review passed (opus). Verified first-hand: ObservabilityServiceProvider binds concrete AiObservabilityReadModel (enabled-gated); FR-007 test resolves via the real binding (dead-code guard fails-closed) and asserts non-empty byModel/byPipeline + correct summary totals; AccessChecker test confirms admin allowed / non-admin forbidden on the real route; C-003 clean (no Waaseyaa\AI in packages/api/src); composer edges correct (ai-observability->api runtime downward, api->ai-observability dev-only); all gates green (496 tests, cs-check, phpstan, layers, dead-code, getquery, composer-policy). Non-blocking: summary DTO renamed/richer + dropped periodFrom/To; interface consolidated to fetch(). WP02 contract updated to match shipped payload.
