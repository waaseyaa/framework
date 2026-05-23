# Implementation Plan: Queue + Scheduler Admin

**Mission:** `queue-scheduler-admin-01KSBKQF`
**Spec:** [spec.md](./spec.md)
**Target branch:** `main`
**Tracking:** GitHub #1471 (umbrella #1414)
**Pattern reference:** M4A-1 workflows admin (PR #1429)

## Overview

Two WPs delivered as two PRs, sequenced. Each PR uses `Refs #1471` in commit footers. WP02 ends with a comment on #1414 updating the M4 status board.

| WP | Title | Est. LOC | Type | Worktree |
|---|---|---|---|---|
| WP01 | Queue admin (failed-jobs MVP) | ~350 | Backend + Frontend | yes |
| WP02 | Scheduler admin | ~250 | Backend prep + Backend + Frontend | yes |

Implementation pattern: sonnet implements per WP, opus reviews, one PR per WP. Standard implement-review loop.

## WP01 — Queue admin (failed-jobs MVP)

### Backend (`packages/api`)

**New files:**
- `packages/api/src/Controller/QueueController.php` — three actions:
  - `index(Request): Response` — paginated list. Reads `?page=1&per_page=20`, calls `FailedJobRepositoryInterface::all()`, slices for the page, returns `{data: [...], meta: {page, per_page, total}}`.
  - `retry(string $id): Response` — calls `FailedJobRepository::retry($id)`. Returns 204 on success, 404 if `find()` returns null.
  - `discard(string $id): Response` — calls `FailedJobRepository::forget($id)`. Returns 204.

**Route registration** (in the existing admin route service provider or `RouteBuilder` for the API layer):
- `GET /api/queue/jobs` → `QueueController::index`, options `_role: admin`
- `POST /api/queue/jobs/{id}/retry` → `QueueController::retry`, options `_role: admin`
- `POST /api/queue/jobs/{id}/discard` → `QueueController::discard`, options `_role: admin`

**Tests:**
- `packages/api/tests/Unit/Controller/QueueControllerTest.php` — Pest, mocks `FailedJobRepositoryInterface`.
- Integration: `tests/Integration/PhaseN/QueueAdminEndpointsTest.php` (or per existing M4A naming) — boots a kernel with `DBALDatabase::createSqlite()` + `DatabaseFailedJobRepository`, seeds a couple of failed-job rows, hits the endpoints with admin + non-admin accounts, asserts response shape and 403 enforcement.

### Frontend (`packages/admin`)

**New files:**
- `packages/admin/app/pages/admin/queue.vue` — table page. Mirror `pages/admin/workflows.vue` (M4A-1) for shape.
- `packages/admin/app/composables/useQueueJobs.ts` — `{jobs, meta, isLoading, fetch, retry, discard}` shape. Mirror `useWorkflowDefinitions` (M4A-1).
- `packages/admin/app/components/admin/QueueJobRow.vue` — single-row component with Retry/Discard/View-payload buttons.
- `packages/admin/app/components/admin/QueuePayloadModal.vue` — read-only JSON payload viewer.

**Modified files:**
- `packages/admin/app/i18n/en.json` — add `queue.title`, `queue.empty`, `queue.columns.*`, `queue.actions.*`.
- Admin nav file (whichever component renders the left rail — `packages/admin/app/components/AdminShell.vue` or similar) — add `/admin/queue` entry.

**Tests:**
- `packages/admin/test/unit/composables/useQueueJobs.test.ts` — vitest, mocks fetch.
- `packages/admin/e2e/queue.spec.ts` — Playwright smoke. Visits `/admin/queue`, asserts empty-state renders cleanly, then with a seeded failed job asserts Retry and Discard buttons fire the right API calls (use Playwright route mocking).

### CHANGELOG + spec stamp + follow-up

- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: failed-jobs queue dashboard at /admin/queue with retry and discard. (#1471)`
- File the follow-up issue for `TransportInterface::listJobs()` (text drafted in spec.md "Out-of-band" section).
- Commit footer: `Refs #1471`.

### Acceptance gate

- `vendor/bin/phpunit packages/api/tests/Unit/Controller/QueueControllerTest.php` green.
- `vendor/bin/phpunit tests/Integration/.../QueueAdminEndpointsTest.php` green.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- `cd packages/admin && npm run test:e2e -- queue.spec.ts` green (requires `nuxt dev` running).
- Manual smoke: `php -S 127.0.0.1:8000 -t public/` + `cd packages/admin && npm run dev`, visit `/admin/queue` as admin, confirm empty-state and seeded-job flows.

## WP02 — Scheduler admin

### Backend prep (`packages/scheduler`)

**Modified files:**
- `packages/scheduler/src/ScheduleRunner.php` — add public method:
  ```
  public function runOne(string $taskName, \DateTimeInterface $now): ScheduleRunResult
  ```
  Looks up `$this->schedule->getTasks()` for a task whose `name === $taskName`. If not found, throws `\InvalidArgumentException` (or a domain `TaskNotFoundException` if one already exists in the package). Executes the task using the same path `run()` uses internally — refactor that into a private `runTask(ScheduledTask, DateTimeInterface): ScheduleRunResult` if it isn't already factored out — and calls `$this->stateRepository->recordRun($taskName, $result->status)`.

**Tests:**
- `packages/scheduler/tests/Unit/ScheduleRunnerTest.php` — extend (or create) to cover:
  - Happy path: known task runs, `recordRun` called, result returned.
  - Unknown task: throws.
  - Closure tasks and string-command tasks both work (parity with existing `run()` coverage).

### Backend (`packages/api`)

**New files:**
- `packages/api/src/Controller/SchedulerController.php`:
  - `index(): Response` — resolves `Schedule` from container, iterates tasks. For each, calls `ScheduleStateRepository::getState($name)` and `ScheduledTask::getNextRunDate(new \DateTimeImmutable())`. Returns `{data: [{name, description, expression, timezone, last_run_at, last_status, next_run_at}, ...]}`.
  - `trigger(string $name): Response` — calls `ScheduleRunner::runOne($name, new \DateTimeImmutable())`. On `InvalidArgumentException` return 404. On success, return 200 with `{status, message}` (extract from `ScheduleRunResult`; never serialize a `\Throwable` directly — convert to `{status, message, exception_class}`).

**Route registration:**
- `GET /api/scheduler/tasks` → `SchedulerController::index`, `_role: admin`.
- `POST /api/scheduler/tasks/{name}/trigger` → `SchedulerController::trigger`, `_role: admin`.

**Tests:**
- Unit: `SchedulerControllerTest.php` — mock `Schedule` registry and `ScheduleRunner`.
- Integration: full kernel boot with two registered tasks, hit both endpoints, assert response shape and `recordRun` side effect.

### Frontend (`packages/admin`)

**New files:**
- `packages/admin/app/pages/admin/scheduler.vue`.
- `packages/admin/app/composables/useScheduledTasks.ts` — `{tasks, isLoading, fetch, trigger}`.
- `packages/admin/app/components/admin/SchedulerTaskRow.vue` — row with Run-now button.

**Modified files:**
- `packages/admin/app/i18n/en.json` — `scheduler.*` keys.
- Admin nav — add `/admin/scheduler`.

**Tests:**
- Vitest for `useScheduledTasks`.
- Playwright smoke `e2e/scheduler.spec.ts`: visits `/admin/scheduler`, asserts list renders, Run-now button fires the trigger endpoint.

### Spec + CHANGELOG + mission close-out

- Update `docs/specs/admin-spa.md` — add both routes (queue + scheduler) to the route inventory section with `_role: admin` annotations.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: scheduler dashboard at /admin/scheduler with manual task trigger. (#1471)`
- Add a comment to #1414 with M4 status update (M4B closed, M4A-5 + M4C still open).
- Commit footers: `Refs #1471`.

### Acceptance gate

- Both new unit-test files green.
- Integration tests green.
- `composer test` green.
- `cd packages/admin && npm test && npm run typecheck && npm run lint && npm run test:e2e -- scheduler.spec.ts` green.
- Manual smoke as above for `/admin/scheduler`.

## Sequencing

WP01 → WP02. Independent technically, sequenced for review focus.

## Risk log

- **`ScheduleRunner::run()` may not factor task-execution cleanly today.** If it inlines the execution logic, factor it into a private helper as part of WP02 backend prep. Keep the public `run()` signature unchanged.
- **`ScheduledTask` is `readonly`** — good, no concerns about state leaking across requests.
- **`Schedule` registry timing.** Resolve from the container per-request, not via a cached snapshot.
- **`FailedJobRepository::retry()` semantics.** Confirm during WP01 implementation that `retry()` actually re-enqueues onto the original transport (not just removes from the failed table). If it's only "remove from failed," the controller needs to fetch the original payload + queue and call `Transport::push()` explicitly.

## Reviewers

- WP01: opus review. Check (a) admin-only enforcement is via `_role` route option, not in-controller; (b) empty-state rendering; (c) the deferred-scope follow-up issue is actually filed before merge.
- WP02: opus review. Check (a) `ScheduleRunner::runOne()` test coverage matches existing `run()` coverage; (b) `\Throwable` never crosses the JSON boundary; (c) spec stamp on `docs/specs/admin-spa.md` lands in WP02, not WP01.
