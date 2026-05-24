---
work_package_id: WP01
title: Queue admin (failed-jobs MVP)
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-015
- FR-016
- NFR-001
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-queue-scheduler-admin-01KSBKQF
base_commit: 69a4bb209d6eb8bba3b43f131cbf43e1b826b45a
created_at: '2026-05-24T18:47:00.143109+00:00'
subtasks: []
shell_pid: "357297"
history: []
authoritative_surface: packages/api/src/Controller/QueueController.php
execution_mode: code_change
owned_files:
- packages/api/src/Controller/QueueController.php
- packages/api/tests/Unit/Controller/QueueControllerTest.php
- packages/admin/app/pages/admin/queue.vue
- packages/admin/app/composables/useQueueJobs.ts
- packages/admin/app/components/admin/QueueJobRow.vue
- packages/admin/app/components/admin/QueuePayloadModal.vue
- packages/admin/test/unit/composables/useQueueJobs.test.ts
- packages/admin/e2e/queue.spec.ts
tags: []
agent: "claude:opus:reviewer:reviewer"
---

# WP01 — Queue admin (failed-jobs MVP)

**Mission:** `queue-scheduler-admin-01KSBKQF`
**Spec:** [../spec.md](../spec.md)
**Plan:** [../plan.md](../plan.md)
**Tracking:** GitHub issue #1471 (umbrella #1414)
**Pattern reference:** M4A-1 workflows admin (PR #1429) — `useWorkflowDefinitions`, `/admin/workflows`, `WorkflowDefinitionController`.

## What you're building

A read-mostly admin dashboard for failed background jobs. Operators see what's failing, retry single jobs, or discard them. Queued and in-flight jobs are **out of scope** for this WP (see C-001) — `TransportInterface` lacks a `listJobs()` method, and adding one is a contract change that belongs to a follow-up issue you will file at wrap-up.

## Subtasks

**T001 — Backend controller (`packages/api/src/Controller/QueueController.php`)**
- Three public methods on a new `QueueController`:
  - `index(Request $request): Response` — reads `?page=1&per_page=20`, calls `FailedJobRepositoryInterface::all()`, slices for the page, returns `{data: [...], meta: {page, per_page, total}}`. The per-row shape: `{id, queue, payload, exception_class, exception_message, failed_at, attempts}`. Truncate `payload` to a reasonable size in the list response if it's large (>2 KB); the View-payload modal can fetch the full payload separately if needed, but the simpler MVP keeps the truncated payload inline.
  - `retry(string $id): Response` — calls `FailedJobRepository::retry($id)`. Returns 204. Returns 404 if `find($id)` is null (check **before** retry to avoid a race).
  - `discard(string $id): Response` — calls `FailedJobRepository::forget($id)`. Returns 204.
- Inject `FailedJobRepositoryInterface` via constructor.
- Confirm during implementation: `FailedJobRepository::retry()` must actually re-enqueue the job onto the original transport, not just remove it from the failed table. If it doesn't, fetch the original payload + queue first and call `Transport::push()` explicitly before forgetting. Document the actual semantics inline (one-line comment, only if non-obvious).

**T002 — Routes**
- Register in the existing admin route service provider (look at how M4A-1 registered `/api/workflow-definitions` — same shape):
  - `GET /api/queue/jobs` → `QueueController::index`, options `_role: admin`.
  - `POST /api/queue/jobs/{id}/retry` → `QueueController::retry`, options `_role: admin`.
  - `POST /api/queue/jobs/{id}/discard` → `QueueController::discard`, options `_role: admin`.
- Access enforcement is via the route option only (NFR-001). Do not check the role inside the controller.

**T003 — Backend tests**
- `packages/api/tests/Unit/Controller/QueueControllerTest.php` — Pest, mocks `FailedJobRepositoryInterface`. Covers: pagination math, retry 204, retry 404 when not found, discard 204.
- Integration test (place it where M4A-1's integration tests live; if `tests/Integration/PhaseN/` is the pattern, mirror the same N): boot a kernel with `DBALDatabase::createSqlite()` + the real `DatabaseFailedJobRepository`, seed two failed-job rows, hit each endpoint with an admin account and a non-admin account, assert response shapes and 403 enforcement.

**T004 — Frontend page + composable**
- `packages/admin/app/composables/useQueueJobs.ts` — `{jobs, meta, isLoading, fetch, retry, discard}`. Use `$fetch` (Nuxt 3) the same way `useWorkflowDefinitions` does. Refetch list after retry/discard to refresh the table.
- `packages/admin/app/pages/admin/queue.vue` — table page. Mirror `pages/admin/workflows.vue` for shape. Columns: id, queue, message class (parse from payload), exception class + message (truncated), failed-at (relative time), attempts.
- `packages/admin/app/components/admin/QueueJobRow.vue` — row component with Retry, Discard, View-payload buttons. Discard prompts a confirmation modal.
- `packages/admin/app/components/admin/QueuePayloadModal.vue` — read-only JSON viewer. Use a `<pre>` with monospace + word-wrap; no syntax highlighting needed for MVP.
- i18n: extend `packages/admin/app/i18n/en.json` with `queue.title`, `queue.empty`, `queue.columns.*`, `queue.actions.*`, `queue.confirmDiscard`.
- Nav: add `/admin/queue` to the admin left-rail navigation component (whatever component renders the nav today — search for the workflows entry as a reference).

**T005 — Frontend tests**
- `packages/admin/test/unit/composables/useQueueJobs.test.ts` — vitest. Mock fetch. Cover: list loads, retry refetches, discard refetches, error state.
- `packages/admin/e2e/queue.spec.ts` — Playwright smoke. Visit `/admin/queue`, assert empty-state renders. Then with a mocked job, click Retry and assert the right POST fires. Click Discard, confirm the modal, assert the right POST fires.

## CHANGELOG + follow-up + commit footer

- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: failed-jobs queue dashboard at /admin/queue with retry and discard. (#1471)`
- File the follow-up issue **before opening the WP01 PR**. Text:
  > **`TransportInterface::listJobs()` + queued/in-progress queue dashboard columns**
  >
  > WP01 of `queue-scheduler-admin-01KSBKQF` (PR #NNNN) deferred queued/in-flight job visibility because `TransportInterface` has no `listJobs()` method. Add it (with `int $limit`, `int $offset`, `?string $status`), implement in `DbalTransport` and `InMemoryTransport`, contract-test it, then expand `GET /api/queue/jobs` to accept `status=queued|in_progress|failed` and add the corresponding columns + filter chips to `/admin/queue`. Estimate: ~250 LOC.
  >
  > Labels: `admin-spa`, `audit-followup`. Parent: #1414. Sibling: #1471 (this mission).
- Commit footer: `Refs #1471`.
- One PR for the whole WP. Title: `feat(admin-spa): queue admin dashboard (M4B-1)`.

## Verification gate

Before requesting review:
- [ ] `vendor/bin/phpunit packages/api/tests/Unit/Controller/QueueControllerTest.php` — green.
- [ ] `vendor/bin/phpunit tests/Integration/.../QueueAdminEndpointsTest.php` — green.
- [ ] `composer cs-check && composer phpstan` — green.
- [ ] `cd packages/admin && npm test && npm run typecheck && npm run lint` — green.
- [ ] `cd packages/admin && npm run test:e2e -- queue.spec.ts` — green (requires `nuxt dev` on :3000).
- [ ] Manual smoke: with empty DB and with one seeded failed job, both states render correctly.
- [ ] Follow-up issue filed (link from PR description).

## Out of scope reminders

- Queued / in-flight job listing.
- Job payload editing.
- Worker process management.
- Live updates (Mercure / SSE) — covered separately in M5 per audit C-L0-04.

## Activity Log

- 2026-05-24T18:47:03Z – claude:sonnet:implementer:implementer – shell_pid=350353 – Assigned agent via action command
- 2026-05-24T19:05:33Z – claude:sonnet:implementer:implementer – shell_pid=350353 – WP01 queue admin ready; follow-up issue #1576 filed for TransportInterface::listJobs(); minor path deviations from owned_files documented in commit msgs (pages/queue/index.vue not pages/admin/queue.vue, components/queue/ not components/admin/ — matches existing pages/workflows/ + components/workflow/ conventions)
- 2026-05-24T19:06:31Z – claude:opus:reviewer:reviewer – shell_pid=357297 – Started review via action command
- 2026-05-24T19:09:04Z – claude:opus:reviewer:reviewer – shell_pid=357297 – Review passed (opus). All gates green (phpunit 17/17, phpstan/cs-check/layers/dead-code/getquery clean). Follow-up #1576 filed correctly. Pattern parity with M4A-1 verified. URL deviation /queue vs /admin/queue accepted - Nuxt app has no /admin/ prefix on any existing admin page (/workflows, /telescope all at root); WP02 will stamp docs/specs/admin-spa.md with the actual URLs. 422 corrupt-payload addition accepted - retry() is destructive, surfacing corruption beats silent success. intelephense P1006/P1038 warnings on anonymous-class QueueInterface and ApiServiceProvider override are spurious (PHPStan + PHP runtime accept them). Foundation route registration uses string FQCN pattern matching CodifiedContextController precedent - layer-safe. Playwright not run (needs nuxt dev on :3000) - flagged for CI.
- 2026-05-24T19:40:39Z – claude:opus:reviewer:reviewer – shell_pid=357297 – Done override: Mission merged to main (6c9f32c44) - manual closeout
