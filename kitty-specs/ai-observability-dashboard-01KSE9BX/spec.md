# AI Observability Dashboard — Aggregations (M5 Phase 2, sub-mission A)

**Mission:** `ai-observability-dashboard-01KSE9BX`
**Target branch:** `main`
**Tracks:** GitHub umbrella #1415 (M5 — admin SPA Phase 2, AI/agentic surfaces). Closes audit entry **C-L5-01**. First of four M5 sub-missions (A=observability, B=pipeline inspector, C=MCP admin, D=Mercure monitor).
**Pattern reference (CANONICAL):** `CodifiedContextController` + `CodifiedContextSessionStoreInterface` (the L5/L6 → L4 admin-surface pattern) for the cross-layer wiring; M4B `QueueAdminApiRouter` / `QueueController` and M4A-5 `WorkflowGuardsApiRouter` for the router + `ApiServiceProvider::httpDomainRouters()` shape.

## Why

`packages/ai-observability` already records every agent run: the `trace` entity (`status`, `started_at`, `ended_at`, `label`) plus `trace_span` rows where `kind = 'llm_call'` carry `{model, input_tokens, output_tokens, cached_tokens, cost_usd}` in their JSON `attributes` (written by `TokenAccountant::record()`). Today that telemetry is invisible to operators — there is no admin surface. M5A ships a **read-only aggregated dashboard**: token / model / latency / error rollups per model and per pipeline, plus headline totals.

Per-run drill-in, the step timeline, artifacts, and replay are **explicitly out of scope** — they belong to M5B (AI pipeline inspector, audit C-L5-02). M5A owns no "runs list".

## The cross-layer constraint (read before designing)

`packages/ai-observability` is **Layer 5 (AI)**. `packages/api` is **Layer 4**. The layer rule forbids api from importing a higher layer, so the M4A-5 WorkflowGuards approach (inject an L3 service into an L4 controller) does **not** apply. M5A must mirror **CodifiedContext**, which solves exactly this for telescope (L6):

1. **api defines the read contract** — interface + DTOs live in `packages/api/src/AiObservability/`, using only api-local value types.
2. **The controller depends on the api-local interface**, nullable, and returns an empty payload when null.
3. **The adapter implementing the interface lives in `packages/ai-observability`** (L5 → L4 is downward = allowed). It reads the `trace` repository + `trace_span` table + `CostTracker`.
4. **`ai-observability`'s `ObservabilityServiceProvider` binds the interface → adapter.** This binding is the single thing that prevents the dead-code-in-production failure (see Risks).
5. **`ApiServiceProvider::httpDomainRouters()` resolves the interface via `resolveOptional` and wires the router.** Because the interface is api's *own* namespace, `ApiServiceProvider` may `use` it with no layer violation; `resolveOptional` returns the adapter the L5 provider bound.
6. **`waaseyaa/ai-observability` is added to api's `require-dev`** (NOT `require`) — mirrors how api require-devs `waaseyaa/telescope`. Keeps `bin/check-package-layers` green (it checks runtime `require` edges only) while letting api's tests instantiate the real adapter.

## Scope

### In scope

**api (L4) — read contract + controller + router:**
- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` — `summary(): ObservabilitySummary`, `byModel(): array<ModelUsageRow>`, `byPipeline(): array<PipelineUsageRow>`. `@api`.
- `packages/api/src/AiObservability/ObservabilitySummary.php`, `ModelUsageRow.php`, `PipelineUsageRow.php` — readonly value DTOs. `@api`.
- `packages/api/src/Controller/AiObservabilityController.php` — `index(): array` returns `{data: {summary, byModel, byPipeline}}` with camelCase keys. Constructor `(?AiObservabilityReadModelInterface $readModel = null)`; returns the empty-shape payload (`summary` zeroed, `byModel: []`, `byPipeline: []`) when null.
- `packages/api/src/Http/Router/AiObservabilityApiRouter.php` — mirror `WorkflowGuardsApiRouter`. Match `AiObservabilityController::`, dispatch `index`. JSON:API error envelope, `application/vnd.api+json`.
- `packages/api/src/ApiServiceProvider.php` — add a block in `httpDomainRouters()`: `$readModel = $this->resolveOptional(AiObservabilityReadModelInterface::class); if ($readModel instanceof AiObservabilityReadModelInterface) { $routers[] = new AiObservabilityApiRouter(new AiObservabilityController($readModel)); }`.
- `packages/api/composer.json` — add `"waaseyaa/ai-observability": "^0.1.0-alpha.188"` to **`require-dev`** + `"../ai-observability"` path repo if absent.

**ai-observability (L5) — adapter + binding:**
- `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php` — `implements Waaseyaa\Api\AiObservability\AiObservabilityReadModelInterface`. Reads `trace` via the entity repository and aggregates `trace_span` (`kind = 'llm_call'`) attributes via `DatabaseInterface`. Joins spans → traces by `trace_uuid`. PHP-side JSON-decode aggregation, mirroring `CostTracker` (Phase-1 acceptable; SQL-side aggregation is a noted follow-up).
- `packages/ai-observability/src/ObservabilityServiceProvider.php` — bind `'Waaseyaa\\Api\\AiObservability\\AiObservabilityReadModelInterface'` → `AiObservabilityReadModel`. Reuse `EntityTypeManager` repo / `DatabaseInterface` / `CostTracker` already resolved here. Guard on `observability.enabled` like `TraceRecorderInterface` (skip the binding, or bind a null-shape that returns empties, when disabled).

**foundation (L0) — routes:**
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — `api.ai.observability.index` → `GET /api/ai/observability`, `_role: admin`, **string FQCN** `'Waaseyaa\\Api\\Controller\\AiObservabilityController'`. Place after the workflow-guards block.

**admin SPA (L6) — dashboard page:**
- `packages/admin/app/composables/useAiObservability.ts` — `{summary, byModel, byPipeline, loading, error, fetchDashboard()}`. Mirror `useQueueJobs.ts`.
- `packages/admin/app/pages/ai/observability.vue` — summary cards (runs, cost USD, total tokens, error rate) + by-model table + by-pipeline table. Empty state.
- `packages/admin/app/components/ai/AiObservabilitySummaryCards.vue` + `packages/admin/app/components/ai/AiUsageTable.vue` (reused for model + pipeline rows).
- Nav: register an "AI" group entry pointing at `/ai/observability` — mirror exactly how `/queue` and `/notifications` register their static nav entries (READ those first; do not invent a new mechanism).
- i18n keys in `packages/admin/app/i18n/en.json`.
- `packages/admin/tests/unit/composables/useAiObservability.test.ts` — vitest.
- `packages/admin/e2e/ai-observability.spec.ts` — Playwright smoke (deferred run; lane worktree limitation).

**Docs:**
- `docs/specs/admin-spa.md` — stamp + AI observability section.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

### Out of scope (→ M5B / later sub-missions)

- Recent-runs list, per-run detail, step timeline, artifacts, replay → **M5B** (C-L5-02).
- MCP endpoint admin → M5C (C-L6-01). Mercure broadcast monitor → M5D (C-L0-04).
- Cost budget editing / alerting UI. Anomaly surfacing UI.
- SQL-side aggregation (Phase-1 uses PHP-side decode like `CostTracker`).

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `AiObservabilityReadModelInterface` (in `packages/api`) declares `summary()`, `byModel()`, `byPipeline()` returning api-local DTOs. No reference to any `Waaseyaa\AI\*` type. |
| FR-002 | Mandatory | `AiObservabilityReadModel` (in `packages/ai-observability`) implements the api interface; aggregates `trace` + `trace_span(kind='llm_call')`. **byModel** groups by `attributes.model`: runs, input/output/total tokens, cost_usd, avg llm-call latency (ms), error rate. **byPipeline** groups by `trace.label`: runs, total tokens, cost_usd, avg trace latency (ms), error rate. **summary**: total runs, total cost_usd, total tokens, overall error rate, running count, period from/to. |
| FR-003 | Mandatory | `ObservabilityServiceProvider` binds `AiObservabilityReadModelInterface` → `AiObservabilityReadModel`. Respects `observability.enabled` (empty-shape behaviour when disabled, consistent with `TraceRecorderInterface`). |
| FR-004 | Mandatory | `GET /api/ai/observability` returns `{data: {summary, byModel, byPipeline}}`, camelCase keys, `_role: admin` at route level. Controller does NOT re-check role. |
| FR-005 | Mandatory | `AiObservabilityController` returns the zeroed empty-shape payload when its read model is null (degrades cleanly when ai-observability is absent or disabled). |
| FR-006 | Mandatory | `ApiServiceProvider::httpDomainRouters()` wires `AiObservabilityApiRouter` only when the read model resolves. Route uses string FQCN in `BuiltinRouteRegistrar`. |
| FR-007 | Mandatory | A **kernel-boot integration test** boots the full kernel with `observability.enabled`, seeds ≥2 traces + llm_call spans across ≥2 models/pipelines, hits `GET /api/ai/observability` as admin, and asserts **non-empty** `byModel` / `byPipeline` and correct summary totals. Also asserts 403 for a non-admin account. (Dead-code-in-production guard — must FAIL if the SP binding is removed.) |
| FR-008 | Mandatory | `/ai/observability` admin page renders summary cards + by-model + by-pipeline tables, with an empty state. `useAiObservability()` covered by vitest; Playwright smoke present (run deferred). An "AI" nav entry routes to it, registered the same way `/queue` is. |
| FR-009 | Mandatory | `docs/specs/admin-spa.md` stamped + section added. `CHANGELOG.md` `[Unreleased]` updated. |
| NFR-001 | Mandatory | Cross-layer wiring mirrors CodifiedContext: read contract + DTOs in api; adapter in ai-observability; `ai-observability` in api **require-dev** only; `bin/check-package-layers` stays green. |
| NFR-002 | Mandatory | Controller / router / composable shapes mirror M4B + M4A-5. camelCase JSON aligned to the TS types. |
| C-001 | Constraint | Read-only. No mutate endpoint, no edit UI. |
| C-002 | Constraint | No runs list, per-run detail, or replay (M5B owns those). M5A is aggregations only. |
| C-003 | Constraint | No upward import: api source must never `use` a `Waaseyaa\AI\*` symbol. ai-observability is api's `require-dev` dependency, not `require`. |

## Acceptance

- All FRs met.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Kernel-boot integration test (FR-007) demonstrably fails when the `ObservabilityServiceProvider` binding is removed (note the verification in the WP report).
- Commit footers `Refs #1415` (umbrella stays open until all four M5 sub-missions land).
- M5 progress comment posted on #1415 at wrap-up.

## Risks

- **Dead code in production (primary).** If `AiObservabilityApiRouter` is wired via `resolveOptional` but no real `singleton` binds `AiObservabilityReadModelInterface`, the dashboard silently returns empty in production while tests using a fake pass. FR-007's kernel-boot test, which seeds real data and asserts non-empty rollups, is the mandatory guard. Reviewer MUST grep `ObservabilityServiceProvider` for the `AiObservabilityReadModelInterface` binding and confirm the integration test fails without it.
- **Layer violation.** Any `use Waaseyaa\AI\…` in `packages/api/src/**` fails C-003. The adapter is the only class touching both sides, and it lives in ai-observability.
- **Latency semantics.** Trace latency = `ended_at − started_at`; running/never-ended traces excluded from latency averages (still counted in totals/running count). llm-call latency for byModel = span `ended_at − started_at`. Document the choice in the adapter.
- **Empty `attributes` / malformed JSON.** Mirror `CostTracker::sumCostFromRows` — `try/catch (\JsonException) { continue; }`. Never let one bad span 500 the dashboard.
- **getQuery / unbound chains.** The adapter uses `DatabaseInterface::select()` (query builder), not `getQuery()` — no new getquery-baseline entries expected.

## Out-of-band

No new follow-up issue required at M5A wrap-up beyond the #1415 progress comment — M5B/M5C/M5D are represented by the umbrella. If PHP-side aggregation proves a perf concern, file a lightweight "SQL-side observability aggregation" follow-up; otherwise leave it noted here.
