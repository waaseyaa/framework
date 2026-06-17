---
work_package_id: WP02
title: AI observability dashboard page, composable, nav, i18n, docs
dependencies:
- WP01
requirement_refs:
- FR-008
- FR-009
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks: []
history: []
authoritative_surface: packages/admin/app/pages/ai/observability.vue
execution_mode: code_change
mission_id: 01KSE9BXVFDDBGCPZ4KN1F8YPM
owned_files:
- packages/admin/app/composables/useAiObservability.ts
- packages/admin/app/pages/ai/observability.vue
- packages/admin/app/components/ai/AiObservabilitySummaryCards.vue
- packages/admin/app/components/ai/AiUsageTable.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useAiObservability.test.ts
- packages/admin/e2e/ai-observability.spec.ts
- docs/specs/admin-spa.md
- CHANGELOG.md
tags: []
wp_code: WP02
---

# WP02 — Frontend: dashboard page, composable, nav, i18n, docs (M5A)

**Mission:** `ai-observability-dashboard-01KSE9BX` (#1415, audit C-L5-01)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on WP01** — the endpoint `GET /api/ai/observability` and its camelCase payload must already be merged/approved.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/ai-observability-dashboard-01KSE9BX-lane-b
```
(Exact path is printed by `spec-kitty agent action implement WP02`.) Lane worktree has no `node_modules` — `cd packages/admin && npm install` first.

## Pattern to mirror (READ FIRST)
- `packages/admin/app/composables/useQueueJobs.ts` — composable shape (`{data, loading, error, fetch...}`, `useApi`).
- `packages/admin/app/pages/queue/index.vue` — page layout (cards + table + empty/loading/error).
- How `/queue` and `/notifications` register their **static nav entries** — find the nav source (grep `queue` under `packages/admin/app/` for the nav registration; mirror it for an "AI" group → `/ai/observability`). Do NOT invent a new nav mechanism.
- `packages/admin/app/i18n/en.json` — key style.

## Endpoint contract (from WP01 — AS SHIPPED; use these exact field names)
`GET /api/ai/observability` →
```
{ data: {
  summary: { totalTraces, runningTraces, errorTraces, errorRate, totalCostUsd, totalInputTokens, totalOutputTokens, avgLatencyMs },
  byModel: [{ model, callCount, inputTokens, outputTokens, cachedTokens, costUsd }],
  byPipeline: [{ pipeline, traceCount, runningCount, errorCount, errorRate, inputTokens, outputTokens, costUsd, avgLatencyMs }]
} }
```
Notes: `byModel` has no per-model latency/error (call counts + tokens + cost only); `byPipeline` carries latency + error rate. Summary has no `periodFrom/To` (not shipped in WP01 — out of scope unless you add it). Authoritative source: the DTOs in `packages/api/src/AiObservability/` + the controller mappers in `AiObservabilityController` — read them rather than trusting this block.

## Subtasks

**T005 — composable + components + page**
- `app/composables/useAiObservability.ts` — TS types for summary/model/pipeline rows; `{summary, byModel, byPipeline, loading, error, fetchDashboard()}`. Use `useApi`. Mirror `useQueueJobs.ts` error handling.
- `app/components/ai/AiObservabilitySummaryCards.vue` — cards: runs, cost (USD, formatted), total tokens, error rate (%). Props from `summary`.
- `app/components/ai/AiUsageTable.vue` — generic table: props `{columns, rows, emptyLabel}`. Reused for byModel and byPipeline.
- `app/pages/ai/observability.vue` — title, summary cards, "By model" table, "By pipeline" table, empty state when both arrays empty, loading + error states. Calls `fetchDashboard()` on mount.

**T006 — nav + i18n + tests**
- Register an "AI" nav group entry → `/ai/observability` (mirror `/queue`).
- `app/i18n/en.json` — `ai_observability_title`, `ai_observability_summary_runs`, `ai_observability_summary_cost`, `ai_observability_summary_tokens`, `ai_observability_summary_error_rate`, `ai_observability_summary_running`, `ai_observability_by_model`, `ai_observability_by_pipeline`, `ai_observability_empty`, and column labels (`ai_col_model`, `ai_col_pipeline`, `ai_col_runs`, `ai_col_tokens`, `ai_col_cost`, `ai_col_latency`, `ai_col_error_rate`).
- `tests/unit/composables/useAiObservability.test.ts` — vitest: success populates state; failure sets `error`, leaves arrays empty.
- `e2e/ai-observability.spec.ts` — Playwright smoke: visit `/ai/observability`, assert title + tables render. (Run deferred — lane worktree limitation per CLAUDE.md gotcha.)

**T007 — docs**
- `docs/specs/admin-spa.md` — add `<!-- Spec reviewed 2026-05-25 - ai-observability-dashboard-01KSE9BX: AI observability dashboard page -->` near the top and an "AI observability" subsection describing the page + endpoint.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: AI observability dashboard at /ai/observability — token/model/latency/error rollups per model and pipeline. (#1415)`

## Verification gate (in lane worktree)
1. `cd packages/admin && npm install`
2. `npm test && npm run typecheck && npm run lint`
3. From repo root: `composer cs-check` (docs/CHANGELOG only — should be clean) and `tools/drift-detector.sh` sanity if available.

## Commit + handoff
- Commits (footer `Refs #1415`):
  - `feat(admin): AI observability dashboard page + composable (#1415)`
  - `feat(admin): AI nav entry + i18n for observability (#1415)`
  - `docs(specs): admin-spa.md AI observability section + CHANGELOG (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission ai-observability-dashboard-01KSE9BX
  spec-kitty agent tasks move-task WP02 --to for_review --mission ai-observability-dashboard-01KSE9BX --note "M5A frontend ready; Playwright run deferred (lane worktree)"
  ```

## Report back with
1. Commit SHAs.
2. Which file/mechanism registers the "AI" nav entry (and how `/queue` does it).
3. Screenshot-free confirmation that `npm run typecheck` + `npm test` are green.

## Activity Log
