# Implementation Plan: AI Observability — Recent Runs (M5B)

**Mission:** `ai-observability-recent-runs-m5b-01KSEFT9` — see `spec.md`.
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — CodifiedContext cross-layer L5→L4. M4B `QueueController`/`QueueAdminApiRouter`. M4A-5 `WorkflowGuardsApiRouter`.
**Two WPs, sequential:** WP02 (frontend) depends on WP01 (backend) — the pages need the endpoint shape locked.
**Cross-mission dependency:** WP01 is `blocked_by` `per-record-ai-access-flagship-01KSEFT5/WP01` (the `_gate: 'ai.trace.replay'` policy registration must exist before this mission's replay route can be wired).

## WP01 — Backend: read contracts, adapters, replay service, binding, routes, kernel-boot test

### api (L4) — read contract, DTOs, controller, router

- READ FIRST: M5A's shipped surface — `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php`, `ObservabilitySummary.php`, `ModelUsageRow.php`, `PipelineUsageRow.php`, `packages/api/src/Controller/AiObservabilityController.php`, `packages/api/src/Http/Router/AiObservabilityApiRouter.php`, and `httpDomainRouters()` in `ApiServiceProvider.php`. Mirror EXACTLY.
- READ M5A's per-record dependency: `packages/access/src/Gate/PolicyAttribute.php` + the policy registered by `per-record-ai-access-flagship-01KSEFT5/WP01` for `ai.trace.replay`. Confirm the gate name string matches what `AccessChecker` resolves.
- `packages/api/src/AiObservability/Runs/RunListReadModelInterface.php` — `recentRuns(RunListFilter $filter, int $page, int $perPage): RunListPage`. `@api`.
- `packages/api/src/AiObservability/Runs/RunDetailReadModelInterface.php` — `findByUuid(string $traceUuid): ?RunDetail`. `@api`.
- `packages/api/src/AiObservability/Runs/RunReplayServiceInterface.php` — `replay(string $traceUuid): RunReplayResult`. `@api`.
- `packages/api/src/AiObservability/Runs/RunListFilter.php` — `readonly class` with nullable filters: `pipeline`, `status`, `from`, `to`. Static `fromQuery(array $query): self`. `@api`.
- `packages/api/src/AiObservability/Runs/RunListPage.php` — `readonly class { rows: array<RunListRow>, page: int, perPage: int, total: int }`. `@api`.
- `packages/api/src/AiObservability/Runs/RunListRow.php` — readonly DTO: `traceUuid`, `pipeline`, `status`, `startedAt`, `endedAt?`, `durationMs?`, `costUsd`, `totalTokens`, `spanCount`. `@api`.
- `packages/api/src/AiObservability/Runs/RunDetail.php` — readonly DTO: `header: RunListRow`, `spans: array<RunSpanNode>`. `@api`.
- `packages/api/src/AiObservability/Runs/RunSpanNode.php` — recursive readonly DTO: `spanUuid`, `parentSpanUuid?`, `kind`, `name`, `status`, `startedAt`, `endedAt?`, `durationMs?`, `attributes: array<string, mixed>`, `children: array<RunSpanNode>`, `truncated: bool`. `@api`.
- `packages/api/src/AiObservability/Runs/RunReplayResult.php` — readonly DTO: `newRunUuid`, `status`, `startedAt`. `@api`.
- `packages/api/src/Controller/AiObservabilityRunsController.php` — constructor `(?RunListReadModelInterface $list = null, ?RunDetailReadModelInterface $detail = null, ?RunReplayServiceInterface $replay = null)`. Three actions: `index(Request)`, `show(string $uuid)`, `replay(string $uuid)`. Empty-shape payloads when any dep is null. **camelCase** keys. Reads `perPage` from query (clamp 1..100; default 25); reads `page` (clamp ≥1; default 1).
- `packages/api/src/Http/Router/AiObservabilityRunsApiRouter.php` — `supports()` matches `_controller` in the runs set; dispatch on `_controller` value to controller methods.
- `packages/api/src/ApiServiceProvider.php` — extend `httpDomainRouters()`: resolve the three interfaces via `resolveOptional`; if any present, register the runs router (the empty-shape contract means the router can ship even if only some bindings exist).
- `packages/api/composer.json` — NO change. `waaseyaa/ai-observability` already in `require-dev` from M5A.

### ai-observability (L5) — adapters + binding

- READ FIRST: `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php` (M5A's PHP-side aggregation pattern), `packages/ai-observability/src/ObservabilityServiceProvider.php` (M5A's binding shape), `packages/ai-observability/src/Recorder/TraceRecorder.php` + `Cost/CostTracker.php` (the data shapes).
- `packages/ai-observability/src/ReadModel/RunListReadModel.php` — paginated `trace` listing via the entity repository; per-trace span-count + cost + tokens aggregation via `DatabaseInterface::select()` on `trace_span` JOINed/grouped by `trace_uuid`; PHP-side JSON-decode of `attributes` for `kind = 'llm_call'`. Order `started_at DESC`.
- `packages/ai-observability/src/ReadModel/RunDetailReadModel.php` — read trace by uuid; read all spans for trace; build the tree by `parent_span_uuid`; recursion depth bounded at 32 (mark `truncated: true` at boundary). Malformed JSON attributes → empty array.
- `packages/ai-observability/src/Replay/RunReplayService.php` — locate the recorded pipeline by `trace.label` via `PipelineRegistry` (resolve from kernel-services bus); read the root span's `attributes` for replay inputs; invoke pipeline; return `RunReplayResult` with the freshly-created trace uuid.
- `packages/ai-observability/src/ObservabilityServiceProvider.php` — add three bindings after the M5A `AiObservabilityReadModelInterface` block: `RunListReadModelInterface` → `RunListReadModel`, `RunDetailReadModelInterface` → `RunDetailReadModel`, `RunReplayServiceInterface` → `RunReplayService`. Guard on `observability.enabled` (skip when disabled, same as M5A).

### foundation (L0) — routes

- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes after the M5A observability block:
  - `api.ai.observability.runs.index` → `GET /api/ai/observability/runs`, `_role: admin`, controller string FQCN `'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController'`, action `index`.
  - `api.ai.observability.runs.show` → `GET /api/ai/observability/runs/{uuid}`, `_role: admin`, action `show`.
  - `api.ai.observability.runs.replay` → `POST /api/ai/observability/runs/{uuid}/replay`, `_role: admin`, `_gate: 'ai.trace.replay'`, action `replay`.

### root integration (FR-008 — dead-code guard)

- `tests/Integration/PhaseAiObservability/AiObservabilityRunsEndpointTest.php` — boot full kernel (`observability.enabled = true`), seed ≥3 traces with mixed status across ≥2 pipelines + matching span trees, hit `GET /api/ai/observability/runs` as admin → assert paginated rows + correct totals; hit `GET /api/ai/observability/runs/{uuid}` → assert tree shape; hit `POST /api/ai/observability/runs/{uuid}/replay` → assert new trace uuid. Also assert 403 for non-admin on all three. `#[CoversNothing]`. **MUST fail if any of the three SP bindings is removed** — verify by hand and report in the WP wrap-up.

## WP02 — Frontend: composables, list page, detail page, components, nav, i18n, docs

- READ FIRST: `packages/admin/app/composables/useAiObservability.ts` (M5A's shipped composable), `packages/admin/app/pages/ai/observability.vue` (M5A's shipped page), `packages/admin/app/composables/useQueueJobs.ts` (filter + pagination pattern), `packages/admin/app/pages/queue/index.vue` (table + filter shape). Mirror EXACTLY.
- `app/composables/useAiObservabilityRuns.ts` — TS types matching the WP01 list payload; `{rows, page, perPage, total, filter, loading, error, fetchRuns(), setFilter(partial), setPage(n)}`. Use `useApi`.
- `app/composables/useAiObservabilityRunDetail.ts` — TS types matching the WP01 detail payload; `{run, loading, error, fetchRun(uuid), replay(uuid)}`. Replay posts via `useApi`; on success returns `newRunUuid` for the page to navigate.
- `app/pages/ai/observability/runs/index.vue` — title, filter bar, list table, pagination. Empty + loading + error states.
- `app/pages/ai/observability/runs/[uuid].vue` — header (pipeline + status + duration + cost + tokens), span-tree section (recursive `RunSpanNode.vue`), replay button.
- `app/components/ai/RunListTable.vue` — props `{rows, page, perPage, total}` + `@page-change` event. Generic-ish table reused for filtered lists.
- `app/components/ai/RunFilterBar.vue` — `pipeline` text input, `status` select, `from`/`to` date pickers. Emits `update:filter`.
- `app/components/ai/RunSpanNode.vue` — recursive: renders one node + recurses into children. Timing bar via `durationMs` proportional to root span. Attribute popover via `<details>`.
- Nav: register "Recent runs" entry under the existing "AI" group (mirror how M5A registered "Dashboard"; read M5A's WP02 commit for the exact mechanism).
- `app/i18n/en.json` — `ai_runs_title`, `ai_runs_empty`, `ai_runs_col_*` (pipeline, status, started, duration, cost, tokens, spans), `ai_runs_filter_*` (pipeline, status, from, to), `ai_run_detail_title`, `ai_run_detail_replay`, `ai_run_detail_replay_success`, `ai_run_detail_replay_failure`.
- `tests/unit/composables/useAiObservabilityRuns.test.ts` — vitest: fetch success populates rows; failure sets `error`; `setFilter` mutates state then refetch; `setPage` mutates page then refetch.
- `tests/unit/composables/useAiObservabilityRunDetail.test.ts` — vitest: fetch success populates `run`; replay success returns uuid; replay failure sets `error`.
- `e2e/ai-observability-runs.spec.ts` — Playwright smoke (visit `/ai/observability/runs`, assert table + nav; visit `/ai/observability/runs/<uuid>` assert header + spans). Run deferred — lane worktree limitation per CLAUDE.md gotcha.
- `docs/specs/admin-spa.md` — stamp `<!-- Spec reviewed 2026-05-25 - ai-observability-recent-runs-m5b-01KSEFT9: runs list + detail + replay -->` near the top + an "AI observability — runs" subsection describing the pages + endpoints.
- `docs/specs/ai-integration.md` — stamp + a sub-section under "AI Observability" describing the runs read contract + replay service.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: AI observability runs list, per-run detail with span tree, and replay action. (#1415)`

## Verification gate (each WP, in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/ai-observability/tests/ tests/Integration/PhaseAiObservability/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `rg -n 'Waaseyaa\\\\AI' packages/api/src` returns **nothing** (C-003).
6. WP02 only: `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`.

## Reviewer focus

- **Dead-code guard.** Confirm FR-008's integration test fails when any one of the three `ObservabilityServiceProvider` bindings is removed. M5A reviewer caught dead-code-in-production with the same approach; replicate the discipline.
- **Layer hygiene.** Grep `packages/api/src` for `Waaseyaa\AI\` — must be zero hits.
- **Gate wiring.** Confirm the replay route has BOTH `_role: admin` AND `_gate: 'ai.trace.replay'`. Removing either is a CI-blocking regression per C-002.
- **Empty-shape parity.** Controller must return zeroed empty payloads (not 500, not 503) when any dependency is null. Mirror M5A's `AiObservabilityController` exactly.
- **camelCase JSON.** All response keys camelCase to match TS types. M5A precedent.
- **Pagination clamps.** `perPage` clamped 1..100; `page` clamped ≥1. Reviewer should confirm both edge cases via a unit test on the controller.
