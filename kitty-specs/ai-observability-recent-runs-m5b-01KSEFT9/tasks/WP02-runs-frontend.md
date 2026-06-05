---
work_package_id: WP02
title: Runs admin SPA — list page, detail page, composables, nav, i18n, docs
dependencies:
- WP01
requirement_refs:
- FR-009
- FR-010
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T005
- T006
- T007
history: []
authoritative_surface: packages/admin/app/pages/ai/observability/runs/[uuid].vue
execution_mode: code_change
owned_files:
- packages/admin/app/composables/useAiObservabilityRuns.ts
- packages/admin/app/composables/useAiObservabilityRunDetail.ts
- packages/admin/app/pages/ai/observability/runs/index.vue
- packages/admin/app/pages/ai/observability/runs/[uuid].vue
- packages/admin/app/components/ai/RunListTable.vue
- packages/admin/app/components/ai/RunFilterBar.vue
- packages/admin/app/components/ai/RunSpanNode.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useAiObservabilityRuns.test.ts
- packages/admin/tests/unit/composables/useAiObservabilityRunDetail.test.ts
- packages/admin/e2e/ai-observability-runs.spec.ts
- docs/specs/admin-spa.md
- docs/specs/ai-integration.md
- CHANGELOG.md
tags: []
---

# WP02 — Frontend: runs list page, detail page, composables, nav, i18n, docs (M5B)

**Mission:** `ai-observability-recent-runs-m5b-01KSEFT9` (#1415, audit C-L5-02)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on WP01** — the endpoints `GET /api/ai/observability/runs`, `GET /api/ai/observability/runs/{uuid}`, `POST /api/ai/observability/runs/{uuid}/replay` and their camelCase payloads must already be merged/approved.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/ai-observability-recent-runs-m5b-01KSEFT9-lane-b
```
(Exact path is printed by `spec-kitty agent action implement WP02`.) Lane worktree has no `node_modules` — `cd packages/admin && npm install` first.

## Pattern to mirror (READ FIRST)

- `packages/admin/app/composables/useAiObservability.ts` (M5A's shipped composable)
- `packages/admin/app/pages/ai/observability.vue` (M5A's shipped page)
- `packages/admin/app/composables/useQueueJobs.ts` (filter + pagination)
- `packages/admin/app/pages/queue/index.vue` (table + filter shape)
- M5A's "AI" nav-group registration (mirror exactly; do not invent a new mechanism)

## Endpoint contract (from WP01 — AS SHIPPED; use these exact field names)

```
GET /api/ai/observability/runs?pipeline=&status=&from=&to=&page=1&perPage=25
→ {
  data: {
    rows: [{ traceUuid, pipeline, status, startedAt, endedAt?, durationMs?, costUsd, totalTokens, spanCount }],
    page, perPage, total
  }
}

GET /api/ai/observability/runs/{uuid}
→ {
  data: {
    header: { traceUuid, pipeline, status, startedAt, endedAt?, durationMs?, costUsd, totalTokens, spanCount },
    spans: [{ spanUuid, parentSpanUuid?, kind, name, status, startedAt, endedAt?, durationMs?, attributes, children: [...], truncated }]
  }
}

POST /api/ai/observability/runs/{uuid}/replay
→ { data: { newRunUuid, status, startedAt } }
```

## Subtasks

**T005 — composables + components + pages**
- `app/composables/useAiObservabilityRuns.ts` — TS types match payload above; `{rows, page, perPage, total, filter, loading, error, fetchRuns(), setFilter(partial), setPage(n)}`. `useApi`.
- `app/composables/useAiObservabilityRunDetail.ts` — `{run, loading, error, fetchRun(uuid), replay(uuid)}`. `replay` returns the new uuid (string) on success or throws on failure.
- `app/components/ai/RunListTable.vue` — props `{rows, page, perPage, total}`, `@page-change` event. Row link to detail.
- `app/components/ai/RunFilterBar.vue` — pipeline text input, status select, from/to date pickers. `update:filter` event with partial filter.
- `app/components/ai/RunSpanNode.vue` — recursive: renders one span (kind, name, status, duration bar, attribute popover) + recurses into `children`. Honours `truncated`.
- `app/pages/ai/observability/runs/index.vue` — title, filter bar, table, pagination. Empty + loading + error states.
- `app/pages/ai/observability/runs/[uuid].vue` — header card, span-tree section, "Replay" button (on success navigates to `/ai/observability/runs/<newUuid>` via `useRouter`).

**T006 — nav + i18n + tests**
- Register a "Recent runs" nav entry under the existing "AI" group → `/ai/observability/runs` (mirror M5A's registration exactly).
- `app/i18n/en.json` — `ai_runs_title`, `ai_runs_empty`, `ai_runs_col_pipeline`, `ai_runs_col_status`, `ai_runs_col_started`, `ai_runs_col_duration`, `ai_runs_col_cost`, `ai_runs_col_tokens`, `ai_runs_col_spans`, `ai_runs_filter_pipeline`, `ai_runs_filter_status`, `ai_runs_filter_from`, `ai_runs_filter_to`, `ai_run_detail_title`, `ai_run_detail_replay`, `ai_run_detail_replay_success`, `ai_run_detail_replay_failure`.
- `tests/unit/composables/useAiObservabilityRuns.test.ts` — vitest: fetch success populates rows + totals; failure sets `error`; `setFilter` updates state then refetches; `setPage` clamps to ≥1.
- `tests/unit/composables/useAiObservabilityRunDetail.test.ts` — vitest: fetch detail populates `run`; replay success returns uuid; replay failure sets `error`.
- `e2e/ai-observability-runs.spec.ts` — Playwright smoke (run deferred): visit `/ai/observability/runs`, assert title + table; visit detail page; assert span tree renders.

**T007 — docs**
- `docs/specs/admin-spa.md` — add `<!-- Spec reviewed 2026-05-25 - ai-observability-recent-runs-m5b-01KSEFT9: runs list + detail + replay -->` near the top + an "AI observability — runs" subsection describing the two pages + their endpoints.
- `docs/specs/ai-integration.md` — stamp + a "Runs read contract" sub-section under "AI Observability" listing the three endpoints + the replay-service gate name.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: AI observability runs list, per-run detail with span tree, and replay action. (#1415)`

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
2. `cd /home/jones/dev/waaseyaa/.worktrees/... && composer cs-check && composer phpstan` (docs touch).
3. `npm run build` to confirm Nuxt SSR compiles.
4. Confirm by hand: visiting `/ai/observability/runs` shows the M5A nav entry plus the new "Recent runs" entry, and the list renders against the WP01 endpoint shape.

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(admin): AI observability runs list page + composable (#1415)`
  - `feat(admin): AI observability run detail page + replay action (#1415)`
  - `feat(admin): AI runs i18n + nav entry (#1415)`
  - `docs(admin-spa): stamp AI observability runs surface (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission ai-observability-recent-runs-m5b-01KSEFT9
  spec-kitty agent tasks move-task WP02 --to for_review --mission ai-observability-recent-runs-m5b-01KSEFT9 --note "M5B frontend ready; vitest green; e2e smoke deferred per lane worktree limitation"
  ```

## Report back with

- vitest + typecheck + lint output.
- npm run build result.
- Confirmation the two pages render against the WP01 endpoints.

## Activity Log

(implementer appends here)
- 2026-05-25T06:06:57Z – unknown – Moved to for_review
- 2026-05-25T06:07:58Z – unknown – Opus review: lane-a disciplined; WP01 (backend recent-runs + detail + replay with _gate) + WP02 (frontend with paginated list, span timeline honoring truncated marker, filter bar, replay button); 10 Vitest cases pass; specs stamped
- 2026-05-26T18:58:35Z – unknown – Done override: Sprint merge to main
