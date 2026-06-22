# AI Observability — Recent Runs, Detail, Replay (M5 Phase 2, sub-mission B)

**Mission:** `ai-observability-recent-runs-m5b-01KSEFT9`
**Umbrella issue:** #1415 (M5 — admin observability cluster)
**Audit row:** C-L5-02 (per-record-ai-access audit / AI pipeline inspector)
**Mission type:** software-dev
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — cross-layer L5→L4 via CodifiedContext; M4B `QueueController`/`QueueAdminApiRouter`; M4A-5 `WorkflowGuardsApiRouter`.

## Why

M5A shipped the aggregated rollup (`/api/ai/observability` → `summary` / `byModel` / `byPipeline`). Operators can now see *that* AI pipelines are running, but not *which* runs happened, *what* each step did, or replay a single run with the exact inputs that produced an anomaly. The telemetry is already on disk: M5A's WP01 confirmed the `trace` entity (type `trace`, group `ai`) carries `uuid`, `label`, `status`, `started_at`, `ended_at`; the `trace_span` table carries `uuid`, `trace_uuid`, `kind`, `name`, `started_at`, `ended_at`, `status`, and a JSON `attributes` blob per span. For `kind = 'llm_call'`, attributes include `{model, input_tokens, output_tokens, cached_tokens, cost_usd}`. Other kinds (e.g. `tool_call`, `prompt`, `retrieval`) carry their own JSON payloads — those are the artifacts and the inputs the operator needs to inspect and replay.

M5B turns that on-disk telemetry into the **runs surface**: a paginated, filterable list of recent traces, a per-run detail view rendering the full span tree as a timeline, and a single-shot **replay action** that re-executes a recorded run using its captured inputs as a test fixture. Replay is the closest equivalent the M5 cluster has to a mutation surface — it triggers an AI pipeline. Because replay invokes an authenticated, capability-gated AI surface, the route is gated by `_role: admin` AND a per-request `AccessChecker` invocation that runs the trace's owning policy through the per-record AccessChecker integration shipped by `per-record-ai-access-flagship-01KSEFT5` (M-A5 flagship).

Per-trace timing charts (Gantt-style sub-second timelines), cost budget editing, anomaly surfacing, and MCP/Mercure scopes are explicitly out of scope — they belong to M5C (`mcp-endpoint-admin-m5c-01KSEFTB`), M5D (`mercure-broadcast-monitor-m5d-01KSEFTD`), and the cost-budget mission tracked separately.

## The cross-layer constraint (read before designing)

`packages/ai-observability` is **Layer 5 (AI)**. `packages/api` is **Layer 4**. The layer rule forbids api from importing a higher layer, so the only correct cross-layer wiring is the **CodifiedContext three-tier pattern** that M5A shipped:

1. **api defines the read contract** — interfaces + DTOs live in `packages/api/src/AiObservability/Runs/`, using only api-local value types. The new contract complements M5A's `AiObservabilityReadModelInterface` rather than replacing it.
2. **Controllers depend on the api-local interface**, nullable, and return an empty payload when null (M5A's `AiObservabilityController` is the precedent — same empty-shape behaviour).
3. **Adapters implementing the interfaces live in `packages/ai-observability`** (L5 → L4 is downward = allowed). They read the `trace` entity repository + `trace_span` table.
4. **`ai-observability`'s `ObservabilityServiceProvider` binds the new interfaces → adapters.** This binding is the single thing that prevents the dead-code-in-production failure for M5B (FR-008 guard test).
5. **`ApiServiceProvider::httpDomainRouters()` resolves the interfaces via `resolveOptional` and wires the router.** A new sibling `AiObservabilityRunsApiRouter` is registered alongside M5A's `AiObservabilityApiRouter`.
6. **`waaseyaa/ai-observability` is already in api's `require-dev`** (added by M5A WP01) — no composer change needed for the cross-layer plumbing. M5B reuses that path repo.
7. **Replay is a per-request privileged action.** Per DIR-004 (OCAP-by-architecture) and DIR-006 (codified policy gates), the replay route MUST go through `AccessChecker` against the per-record AI access policy shipped by `per-record-ai-access-flagship-01KSEFT5`/WP01. The controller MUST NOT re-implement access logic; it MUST defer to the access middleware via the `_gate` route option, identical to the pattern shipped for entity endpoints.

## Scope

### In scope

**api (L4) — read contract + DTOs + controllers + router extensions:**
- `packages/api/src/AiObservability/Runs/RunListReadModelInterface.php` — `recentRuns(RunListFilter $filter, int $page, int $perPage): RunListPage`. `@api`.
- `packages/api/src/AiObservability/Runs/RunDetailReadModelInterface.php` — `findByUuid(string $traceUuid): ?RunDetail`. `@api`.
- `packages/api/src/AiObservability/Runs/RunReplayServiceInterface.php` — `replay(string $traceUuid): RunReplayResult`. `@api`. The api interface declares the contract; the adapter is the only side that touches the AI pipeline.
- `packages/api/src/AiObservability/Runs/RunListFilter.php` — `pipeline?: string`, `status?: 'running'|'ok'|'error'`, `from?: \DateTimeImmutable`, `to?: \DateTimeImmutable`. Readonly value object. `@api`.
- `packages/api/src/AiObservability/Runs/RunListPage.php` — `rows: array<RunListRow>`, `page: int`, `perPage: int`, `total: int`. Readonly. `@api`.
- `packages/api/src/AiObservability/Runs/RunListRow.php` — `traceUuid`, `pipeline`, `status`, `startedAt`, `endedAt?`, `durationMs?`, `costUsd`, `totalTokens`, `spanCount`. Readonly. `@api`.
- `packages/api/src/AiObservability/Runs/RunDetail.php` — `header: RunListRow`, `spans: array<RunSpanNode>`. Readonly. `@api`.
- `packages/api/src/AiObservability/Runs/RunSpanNode.php` — `spanUuid`, `parentSpanUuid?`, `kind`, `name`, `status`, `startedAt`, `endedAt?`, `durationMs?`, `attributes: array<string, mixed>`, `children: array<RunSpanNode>`. Readonly. `@api`.
- `packages/api/src/AiObservability/Runs/RunReplayResult.php` — `newRunUuid`, `status`, `startedAt`. Readonly. `@api`.
- `packages/api/src/Controller/AiObservabilityRunsController.php` — `index(Request $request): array` (paginated list, reads filter from query string), `show(string $uuid): array` (per-run detail), `replay(string $uuid): array` (POST → new run id). Constructor `(?RunListReadModelInterface $listModel = null, ?RunDetailReadModelInterface $detailModel = null, ?RunReplayServiceInterface $replayService = null)`. Returns empty-shape payloads when any dependency is null.
- `packages/api/src/Http/Router/AiObservabilityRunsApiRouter.php` — three actions: `runs.index`, `runs.show`, `runs.replay`. Mirrors M5A's `AiObservabilityApiRouter` shape. `supports()` matches `_controller` in `{ai.observability.runs.index, ai.observability.runs.show, ai.observability.runs.replay}`.
- `packages/api/src/ApiServiceProvider.php` — `httpDomainRouters()` resolves the three interfaces via `resolveOptional` and wires the new router (mirrors the M5A block; sibling to it, not replacing it).
- `packages/api/composer.json` — no change; `waaseyaa/ai-observability` already in `require-dev` from M5A.

**ai-observability (L5) — adapters + binding:**
- `packages/ai-observability/src/ReadModel/RunListReadModel.php` — `implements Waaseyaa\Api\AiObservability\Runs\RunListReadModelInterface`. Reads `trace` via the entity repository with filter conditions; counts spans per trace via `DatabaseInterface::select()`; aggregates `cost_usd` + `total_tokens` PHP-side from `trace_span` rows where `kind = 'llm_call'` (mirrors M5A's `AiObservabilityReadModel`). Paginated.
- `packages/ai-observability/src/ReadModel/RunDetailReadModel.php` — `implements Waaseyaa\Api\AiObservability\Runs\RunDetailReadModelInterface`. Reads one `trace` by uuid + all `trace_span` rows for the trace; builds the span tree by `parent_span_uuid`; emits the recursive `RunSpanNode` structure. Malformed JSON attributes → skip (return empty `attributes: []`), never 500.
- `packages/ai-observability/src/Replay/RunReplayService.php` — `implements Waaseyaa\Api\AiObservability\Runs\RunReplayServiceInterface`. Reads the original trace's root span (the recorded input), invokes the original pipeline via the AI agent surface, captures the new trace uuid, returns `RunReplayResult`. The replay-execution mechanism reuses `Waaseyaa\AI\Pipeline\PipelineRegistry` resolution by `trace.label` (the pipeline name M5A documented).
- `packages/ai-observability/src/ObservabilityServiceProvider.php` — extend M5A's binding block with three new bindings: `RunListReadModelInterface` → `RunListReadModel`, `RunDetailReadModelInterface` → `RunDetailReadModel`, `RunReplayServiceInterface` → `RunReplayService`. Respects `observability.enabled` (skip the bindings when disabled, consistent with M5A).

**foundation (L0) — routes:**
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes added after the M5A observability block, all using **string FQCN** controller references (matches M5A pattern):
  - `api.ai.observability.runs.index` → `GET /api/ai/observability/runs`, `_role: admin`, controller `'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController'`, action `index`.
  - `api.ai.observability.runs.show` → `GET /api/ai/observability/runs/{uuid}`, `_role: admin`, controller `'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController'`, action `show`.
  - `api.ai.observability.runs.replay` → `POST /api/ai/observability/runs/{uuid}/replay`, `_role: admin` AND `_gate: 'ai.trace.replay'`, controller `'Waaseyaa\\Api\\Controller\\AiObservabilityRunsController'`, action `replay`. The `_gate` value matches the per-record AI access policy registered by `per-record-ai-access-flagship-01KSEFT5`/WP01.

**admin SPA (L6) — list page, detail page, composables, nav, i18n, docs:**
- `packages/admin/app/composables/useAiObservabilityRuns.ts` — `{rows, page, perPage, total, filter, loading, error, fetchRuns(), setFilter(), setPage()}`. TS types matching the controller payload (camelCase).
- `packages/admin/app/composables/useAiObservabilityRunDetail.ts` — `{run, loading, error, fetchRun(uuid), replay(uuid)}`. Replay action posts to the replay endpoint, returns the new trace uuid, leaves navigation to the page.
- `packages/admin/app/pages/ai/observability/runs/index.vue` — paginated list table (pipeline filter, status filter, date range filter), each row links to detail.
- `packages/admin/app/pages/ai/observability/runs/[uuid].vue` — header (pipeline, status, duration, cost, tokens), span-tree timeline (nested `RunSpanNode.vue`), "Replay" button.
- `packages/admin/app/components/ai/RunListTable.vue` — table with filter chips, pagination controls.
- `packages/admin/app/components/ai/RunFilterBar.vue` — pipeline picker (free-text), status select, from/to date pickers.
- `packages/admin/app/components/ai/RunSpanNode.vue` — recursive component rendering one span + children, with timing bars + attribute popover.
- Nav: add an entry under the existing "AI" group → `/ai/observability/runs` ("Recent runs"). Mirror the existing static nav mechanism (read `useNavMenu` registration that `/queue` uses).
- `packages/admin/app/i18n/en.json` — keys: `ai_runs_title`, `ai_runs_empty`, `ai_runs_col_pipeline`, `ai_runs_col_status`, `ai_runs_col_started`, `ai_runs_col_duration`, `ai_runs_col_cost`, `ai_runs_col_tokens`, `ai_runs_col_spans`, `ai_runs_filter_pipeline`, `ai_runs_filter_status`, `ai_runs_filter_from`, `ai_runs_filter_to`, `ai_run_detail_title`, `ai_run_detail_replay`, `ai_run_detail_replay_success`, `ai_run_detail_replay_failure`.
- `packages/admin/tests/unit/composables/useAiObservabilityRuns.test.ts` — vitest: filter set, paginate, fetch success/error.
- `packages/admin/tests/unit/composables/useAiObservabilityRunDetail.test.ts` — vitest: fetch detail, replay success, replay failure.
- `packages/admin/e2e/ai-observability-runs.spec.ts` — Playwright smoke: list + detail (run deferred per lane worktree limitation).

**Docs:**
- `docs/specs/admin-spa.md` — stamp `<!-- Spec reviewed 2026-05-25 - ai-observability-recent-runs-m5b-01KSEFT9 -->` + "AI observability — runs" subsection.
- `docs/specs/ai-integration.md` — stamp + note the runs read contract + replay surface.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: AI observability runs list, per-run detail with span tree, and replay action. (#1415)`

### Out of scope (→ later sub-missions)

- Live tailing of an in-flight run's spans (would need SSE; covered by M5D's broadcast monitor surface for AI events as a follow-up).
- Cost-budget editing or alerting UI (separate cost mission).
- Anomaly surfacing UI / outlier highlighting.
- MCP endpoint admin → M5C. Mercure broadcast monitor → M5D.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `RunListReadModelInterface`, `RunDetailReadModelInterface`, `RunReplayServiceInterface` (in `packages/api/src/AiObservability/Runs/`) declare their methods using only api-local DTOs (`RunListFilter`, `RunListPage`, `RunListRow`, `RunDetail`, `RunSpanNode`, `RunReplayResult`). No reference to any `Waaseyaa\AI\*` type. |
| FR-002 | Mandatory | `RunListReadModel` (in `packages/ai-observability`) implements the api list interface; reads `trace` entities via the entity repository with filter conditions (`label`, `status`, `started_at` range); paginates with stable order (`started_at DESC`); aggregates `cost_usd` + `total_tokens` + `span_count` per trace PHP-side from `trace_span` rows where `kind = 'llm_call'`. |
| FR-003 | Mandatory | `RunDetailReadModel` (in `packages/ai-observability`) implements the api detail interface; reads one trace by uuid plus all its spans; builds a recursive `RunSpanNode` tree keyed by `parent_span_uuid`; malformed-JSON span attributes are skipped (return empty `attributes`), never fatal. Recursion is bounded at 32 levels; deeper trees mark the boundary node `truncated: true`. |
| FR-004 | Mandatory | `RunReplayService` (in `packages/ai-observability`) implements the api replay interface; resolves the original pipeline by `trace.label` via `PipelineRegistry`; replays with the captured root-span inputs; returns `RunReplayResult{newRunUuid, status, startedAt}`. |
| FR-005 | Mandatory | `ObservabilityServiceProvider` binds all three api interfaces → their adapters. Respects `observability.enabled` (skips bindings when disabled, consistent with M5A `TraceRecorderInterface` behaviour). |
| FR-006 | Mandatory | `AiObservabilityRunsController` returns `{data: ...}` envelopes with **camelCase** keys; returns zeroed empty-shape payloads when its dependencies are null (degrades cleanly when ai-observability is absent or disabled). Controller does NOT re-check role; routing layer enforces `_role: admin`. |
| FR-007 | Mandatory | `GET /api/ai/observability/runs`, `GET /api/ai/observability/runs/{uuid}`, `POST /api/ai/observability/runs/{uuid}/replay` are registered in `BuiltinRouteRegistrar` with **string FQCN** controller references. The replay route additionally declares `_gate: 'ai.trace.replay'` so `AccessChecker` enforces the per-record AI access policy shipped by `per-record-ai-access-flagship-01KSEFT5`/WP01 before the controller is invoked (DIR-004, DIR-006). |
| FR-008 | Mandatory | A **kernel-boot integration test** boots the full kernel with `observability.enabled = true`, seeds ≥3 traces with mixed status across ≥2 pipelines, hits `GET /api/ai/observability/runs` as admin and asserts non-empty paginated rows + correct totals; hits `GET /api/ai/observability/runs/{uuid}` and asserts the span tree shape; hits the replay endpoint and asserts a new trace uuid is returned. Also asserts 403 for a non-admin account on all three. (Dead-code-in-production guard — must FAIL if any of the three SP bindings is removed.) |
| FR-009 | Mandatory | `/ai/observability/runs` and `/ai/observability/runs/[uuid]` admin pages render. List page supports filter + pagination. Detail page renders the recursive span tree, header summary, and a working "Replay" action that navigates to the new run on success. Composables covered by vitest; Playwright smoke present (run deferred). Nav entry "Recent runs" registered under the existing AI group. |
| FR-010 | Mandatory | `docs/specs/admin-spa.md` + `docs/specs/ai-integration.md` stamped. `CHANGELOG.md` `[Unreleased]` updated. |
| NFR-001 | Mandatory | Cross-layer wiring mirrors the CodifiedContext three-tier pattern shipped by M5A: read contracts + DTOs in api; adapters in ai-observability; `ai-observability` stays in api **require-dev** only; `bin/check-package-layers` stays green. |
| NFR-002 | Mandatory | Controller / router / composable shapes mirror M5A + M4B. camelCase JSON aligned to TS types. |
| NFR-003 | Mandatory | All response payloads paginate via stable keys (`page`, `perPage`, `total`). Default `perPage = 25`; max enforced at 100 to bound query cost. |
| C-001 | Constraint | Read-mostly: only mutation surface is replay, which delegates entirely to the existing AI pipeline. No new entity types. No new database tables. |
| C-002 | Constraint | Replay route MUST be gated by `_role: admin` AND `_gate: 'ai.trace.replay'`. Removing either gate is a CI-blocking regression (covered by FR-008). |
| C-003 | Constraint | No upward import: api source must never `use` a `Waaseyaa\AI\*` symbol. `ai-observability` is api's `require-dev` only — `bin/check-package-layers` enforces. |
| C-004 | Constraint | No SSE/live-tail in this mission. The list endpoint is request/response. Live AI-event tailing is a follow-up that may layer on M5D's broadcast monitor. |

## Acceptance

- All FRs met.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Kernel-boot integration test (FR-008) demonstrably fails when any of the three `ObservabilityServiceProvider` bindings (list / detail / replay) is removed — verify by hand and report in the WP wrap-up.
- Replay route demonstrably returns 403 when invoked with an account that lacks the `ai.trace.replay` gate — verify by hand using the per-record AI access policy fixtures.
- Commit footers `Refs #1415` (umbrella stays open until all four M5 sub-missions land).
- M5 progress comment posted on #1415 at wrap-up.

## Risks

- **Dead code in production (primary).** If any of the three runs interfaces is wired via `resolveOptional` but no real `singleton` binds the api interface, the runs surface silently returns empty in production while tests using a fake pass. FR-008's kernel-boot test, which seeds real data and asserts non-empty rows / tree / replay, is the mandatory guard. Reviewer MUST grep `ObservabilityServiceProvider` for all three bindings and confirm the integration test fails without each.
- **Replay invokes the AI pipeline.** Replay actually re-runs a pipeline, so it costs tokens. The `_gate: 'ai.trace.replay'` gate is the only mechanism that prevents an authenticated-but-unprivileged admin from triggering arbitrary spend. C-002 covers this.
- **Per-record access dependency.** The replay gate depends on `per-record-ai-access-flagship-01KSEFT5`/WP01 having shipped the `AccessChecker` integration for AI traces. Mission `blocked_by` declares this; do NOT release M5B before that lands.
- **Layer violation.** Any `use Waaseyaa\AI\…` in `packages/api/src/**` fails C-003. Adapters are the only classes touching both sides and they live in ai-observability.
- **Span tree depth.** Pathological traces could produce deeply nested span trees that blow up JSON. Detail endpoint MUST limit recursion to a sane depth (proposed: 32 levels deep, truncate beyond with a `truncated: true` marker on the node). Caught in `RunDetailReadModelTest`.
- **getQuery / unbound chains.** The adapters use `DatabaseInterface::select()` (query builder) and `EntityRepository::findBy()`, not `getQuery()` — no new getquery-baseline entries expected.

## Decisions pre-resolved

- Two WPs, sequential (frontend depends on backend contract).
- Backend WP01 writes the kernel-boot integration test that fails if any SP binding is missing (M5A FR-007 pattern, expanded for three bindings).
- Authoritative surface: the JSON contract under `packages/api/src/AiObservability/Runs/`.
- Replay reuses `PipelineRegistry` resolution by `trace.label` — no new pipeline lookup mechanism.
- camelCase JSON across all run payloads (matches M5A as-shipped).
- All response payloads are paginated; default 25, max 100.
- Implementer preference order: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Decisions deferred to implementer

- Exact CSS / visual treatment of the span-tree Gantt bars (must respect existing AdminShell tokens; brand-teal palette).
- Exact filter widget polish — pipeline picker may be a free-text autocomplete or a fixed select; pick whichever matches the existing admin patterns.
- Whether to expose `_data` blob keys under `attributes` on the span node or pre-flatten common fields — pick the option that mirrors how M5A's `byPipeline` row surfaces `attributes.model`.

## Out-of-band

- Dependency: `per-record-ai-access-flagship-01KSEFT5`/WP01 MUST be MERGED before M5B's WP01 can land (the `_gate` integration prerequisite). Declared in `wps.yaml` as `blocked_by`.
- Pattern reference (read every file ONCE before writing): `/home/fsd42/dev/waaseyaa/kitty-specs/ai-observability-dashboard-01KSE9BX/` (M5A — shipped CodifiedContext cross-layer pattern).
- Strategic context: `/home/fsd42/dev/waaseyaa/docs/specs/codified-context-integration.md` (three-tier inheritance model), `/home/fsd42/dev/waaseyaa/docs/specs/ai-integration.md` (pipeline / trace data model), `.kittify/charter/charter.md` DIR-004 (OCAP-by-architecture invariant) + DIR-006 (codified policy gates as trust substrate).
