# Implementation Plan: AI Observability Dashboard (M5A)

**Mission:** `ai-observability-dashboard-01KSE9BX` — see `spec.md`.
**Pattern reference:** CodifiedContext (cross-layer L5→L4), M4B `QueueController`/`QueueAdminApiRouter`, M4A-5 `WorkflowGuardsApiRouter`.
**Two WPs, sequential:** WP02 (frontend) depends on WP01 (backend) — the page needs the endpoint shape locked.

## WP01 — Backend: read contract, adapter, binding, route, kernel-boot test

### api (L4)
- `src/AiObservability/AiObservabilityReadModelInterface.php` — `summary(): ObservabilitySummary`, `byModel(): array`, `byPipeline(): array`. `@api`. No `Waaseyaa\AI\*` references.
- `src/AiObservability/ObservabilitySummary.php` — readonly DTO: `totalRuns`, `totalCostUsd`, `totalTokens`, `errorRate`, `runningCount`, `periodFrom`, `periodTo`. `@api`.
- `src/AiObservability/ModelUsageRow.php` — `model`, `runs`, `inputTokens`, `outputTokens`, `totalTokens`, `costUsd`, `avgLatencyMs`, `errorRate`. `@api`.
- `src/AiObservability/PipelineUsageRow.php` — `pipeline`, `runs`, `totalTokens`, `costUsd`, `avgLatencyMs`, `errorRate`. `@api`.
- `src/Controller/AiObservabilityController.php` — `index(): array` → `{data: {summary, byModel, byPipeline}}` camelCase. Null read model → zeroed empty shape. Does NOT re-check role.
- `src/Http/Router/AiObservabilityApiRouter.php` — mirror `WorkflowGuardsApiRouter`; match `AiObservabilityController::`; dispatch `index`; JSON:API errors.
- `src/ApiServiceProvider.php` — in `httpDomainRouters()`: `resolveOptional(AiObservabilityReadModelInterface::class)`; wire `new AiObservabilityApiRouter(new AiObservabilityController($readModel))` only when non-null. `use Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface;` (api-local — no layer issue).
- `composer.json` — add `"waaseyaa/ai-observability": "^0.1.0-alpha.188"` to **require-dev** + `"../ai-observability"` path repo if absent. `composer update --lock waaseyaa/ai-observability`.
- `tests/Unit/Controller/AiObservabilityControllerTest.php` — happy path with an anonymous-class fake read model; null read model → empty shape.
- `tests/Unit/Http/Router/AiObservabilityApiRouterTest.php` — supports() match + index dispatch + unknown action 404.

### ai-observability (L5)
- `src/ReadModel/AiObservabilityReadModel.php implements Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface`. Ctor: trace `EntityRepositoryInterface` (or `EntityTypeManager`), `DatabaseInterface`, `CostTracker` (or inline cost sum). Aggregation:
  - Load traces (repo `findBy([])` or a bounded `select('trace')`). Group by `label` for byPipeline; status counts for error rate + running count; `ended_at - started_at` for latency.
  - `select('trace_span','ts')->fields('ts',['trace_uuid','started_at','ended_at','attributes'])->condition('kind','llm_call')->execute()`; decode attributes (`try/catch JsonException continue`); group by `model` for byModel; join to trace label for byPipeline token/cost rollups.
  - Latency in ms; exclude null `ended_at` from averages.
- `src/ObservabilityServiceProvider.php` — `register()` adds: `$this->singleton('Waaseyaa\\Api\\AiObservability\\AiObservabilityReadModelInterface', fn() => new AiObservabilityReadModel(...))` guarded by `observability.enabled` (when disabled, bind an instance that returns empties OR skip — match `TraceRecorderInterface` style). Keep the existing `use` set layer-clean (L5→L4 is allowed if importing the api interface; string key avoids needing the import).
- `tests/Unit/ReadModel/AiObservabilityReadModelTest.php` — seed an in-memory SQLite (`DBALDatabase::createSqlite()`) with traces + llm_call spans; assert byModel/byPipeline/summary numbers; malformed-attributes span is skipped.

### root integration (FR-007 — dead-code guard)
- `tests/Integration/PhaseAiObservability/AiObservabilityDashboardEndpointTest.php` — boot full kernel (`observability.enabled = true`), seed ≥2 traces + llm_call spans across ≥2 models and ≥2 pipelines, hit `GET /api/ai/observability` as admin → assert non-empty `byModel`/`byPipeline` + correct summary; hit as non-admin → 403. `#[CoversNothing]`.

### foundation (L0)
- `src/Kernel/BuiltinRouteRegistrar.php` — `api.ai.observability.index` → `GET /api/ai/observability`, `_role: admin`, string FQCN `'Waaseyaa\\Api\\Controller\\AiObservabilityController'`. After the workflow-guards block.

## WP02 — Frontend: composable, page, components, nav, i18n, docs

- READ FIRST: `app/composables/useQueueJobs.ts`, `app/pages/queue/index.vue`, and how `/queue` + `/notifications` register their nav entries.
- `app/composables/useAiObservability.ts` — `{summary, byModel, byPipeline, loading, error, fetchDashboard()}`. camelCase TS types matching the controller payload.
- `app/components/ai/AiObservabilitySummaryCards.vue` — runs / cost USD / total tokens / error% cards.
- `app/components/ai/AiUsageTable.vue` — generic rows table (props: columns + rows) reused for model + pipeline.
- `app/pages/ai/observability.vue` — composes cards + two tables + empty state + loading/error.
- Nav: add an "AI" group entry → `/ai/observability`, mirroring the `/queue` static nav registration.
- `app/i18n/en.json` — `ai_observability_title`, `ai_observability_summary_runs`, `..._cost`, `..._tokens`, `..._error_rate`, `..._running`, `ai_observability_by_model`, `ai_observability_by_pipeline`, `ai_observability_empty`, plus column labels.
- `tests/unit/composables/useAiObservability.test.ts` — vitest: fetch success populates state; error path sets `error`.
- `e2e/ai-observability.spec.ts` — Playwright smoke (run deferred).
- `docs/specs/admin-spa.md` — stamp `<!-- Spec reviewed 2026-05-25 - ai-observability-dashboard-01KSE9BX ... -->` + AI observability section.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: AI observability dashboard at /ai/observability — token/model/latency/error rollups per model and pipeline. (#1415)`

## Verification gate (each WP, in lane worktree)

1. `composer install`
2. (WP02 / admin) `cd packages/admin && npm install && cd -`
3. `vendor/bin/phpunit packages/api/tests/ packages/ai-observability/tests/ tests/Integration/PhaseAiObservability/` (WP01); admin vitest (WP02)
4. `composer cs-check && composer phpstan`
5. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
6. `cd packages/admin && npm test && npm run typecheck && npm run lint` (WP02)

## Reviewer focus

- (a) **C-003 / NFR-001:** zero `use Waaseyaa\AI\*` in `packages/api/src/**`; `ai-observability` is api **require-dev** only; `bin/check-package-layers` green.
- (b) **FR-007 dead-code guard:** grep `ObservabilityServiceProvider` for the `AiObservabilityReadModelInterface` binding; confirm the integration test FAILS without it.
- (c) Read-only — no mutate endpoint/UI (C-001); no runs list/detail/replay (C-002).
- (d) Admin-only via route option, not controller.
- (e) JSON keys camelCase, aligned to the TS composable types.
