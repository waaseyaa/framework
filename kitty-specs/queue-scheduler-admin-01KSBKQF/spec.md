# Queue + Scheduler Admin Dashboards

**Mission:** `queue-scheduler-admin-01KSBKQF`
**Status:** Spec
**Target branch:** `main`
**Tracks:** GitHub issue #1471 (umbrella #1414). Closes audit rows C-L0-01 + C-L0-02 in `docs/audits/admin-spa-modernization-2026-05-10.md`.
**Pattern reference:** M4A-1 workflows admin (`/api/workflow-definitions` + `/admin/workflows`), shipped as PR #1429.

## Why this mission exists

Operators today inspect the job queue and the scheduler only via CLI (`bin/waaseyaa queue:list`, `schedule:list` — neither even exists yet as a command directory) or by reading the SQLite/MySQL rows directly. The admin SPA covers content workflows and entity management but has no visibility into the runtime plumbing that keeps the site alive: which scheduled tasks ran when, what's failing in the queue, how to retry a stuck job.

The audit (`docs/audits/admin-spa-modernization-2026-05-10.md`, rows C-L0-01 and C-L0-02) flagged these as the most operator-facing gaps in Phase 1 because they're already exercised in production by every site running scheduled syncs or queued jobs. M4A (workflows admin) covered the editorial side; M4B covers the operational side.

This is deliberately scoped narrow: read-mostly dashboards with a few imperative actions (retry, discard, trigger). No payload editing, no cron-expression editing, no worker-process management — those belong elsewhere (the supervisor, the code).

## Scope

### In scope

**WP01 — Queue admin (failed-jobs MVP):**
- `GET /api/queue/jobs` — paginated list of *failed* jobs via existing `FailedJobRepositoryInterface::all()`. Filter param accepts `failed` (only value in MVP). Pagination via `?page=&per_page=`. Admin-role-gated.
- `POST /api/queue/jobs/{id}/retry` — calls `FailedJobRepository::retry()`. Returns 204.
- `POST /api/queue/jobs/{id}/discard` — calls `FailedJobRepository::forget()`. Returns 204.
- `/admin/queue` Nuxt page with table (id, queue, message class, exception, failed-at, attempts) and per-row Retry / Discard / View-payload actions.
- `useQueueJobs()` composable, i18n strings, vitest + Pest tests, Playwright smoke.

**WP02 — Scheduler admin:**
- Backend prep: add `ScheduleRunner::runOne(string $taskName, \DateTimeInterface $now): ScheduleRunResult` (~20 LOC + unit test). Looks up the named task in the registry, executes it, records the result via `ScheduleStateRepository::recordRun()`.
- `GET /api/scheduler/tasks` — list registered `ScheduledTask`s from the `Schedule` registry. Each row: `{name, description, expression, timezone, last_run_at, last_status, next_run_at}`. last_* from `ScheduleStateRepository::getState()`, next from `ScheduledTask::getNextRunDate()`. Admin-role-gated.
- `POST /api/scheduler/tasks/{name}/trigger` — calls `ScheduleRunner::runOne()`. Returns 200 with `ScheduleRunResult`.
- `/admin/scheduler` Nuxt page with table (name, description, schedule, last run, last status chip, next run) and per-row Run-now action.
- `useScheduledTasks()` composable, i18n, vitest + Pest, Playwright smoke.

**Cross-WP:**
- Nav: wire `/admin/queue` and `/admin/scheduler` into the admin left rail.
- Spec stamp: update `docs/specs/admin-spa.md` with both routes and their access policies during WP02 wrap-up.
- CHANGELOG: one `[Unreleased]` → **Added** bullet per WP.
- Both PRs use `Refs #1471` in commit footers. Final PR comments on parent #1414 with M4B status update.

### Out of scope

- **Queued + in-flight job listing.** `TransportInterface` has `size()` but no `listJobs()`. Adding that requires touching the transport contract, `DbalTransport`, `InMemoryTransport`, and their contract tests (~150 LOC of work in a layer that other consumers depend on). File a follow-up issue at WP01 wrap-up; defer the queued/in-progress filter and the dashboard columns that depend on it.
- **Job payload editing.** Read-only view via "View payload" modal only.
- **Cron-expression editing.** Tasks are code-defined via attributes; the registry is the source of truth, not a database row.
- **Worker process management** (start / stop / supervise). Belongs to systemd or whatever process supervisor the deployment uses.
- **Mercure / SSE live updates.** Audit row C-L0-04 covers live updates separately in M5 (Coverage Phase 2 — AI/agentic surfaces).

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `GET /api/queue/jobs` returns a paginated JSON envelope `{data: [...], meta: {page, per_page, total}}` listing failed jobs via `FailedJobRepositoryInterface::all()`. Pagination via `?page=&per_page=` query parameters. |
| FR-002 | Mandatory | `POST /api/queue/jobs/{id}/retry` calls `FailedJobRepository::retry($id)` and returns 204; returns 404 if `find()` is null. |
| FR-003 | Mandatory | `POST /api/queue/jobs/{id}/discard` calls `FailedJobRepository::forget($id)` and returns 204. |
| FR-004 | Mandatory | All three queue endpoints enforce admin-only access via the existing `_role: admin` route option; non-admin callers receive 403. |
| FR-005 | Mandatory | `/admin/queue` Nuxt page renders a table of failed jobs with columns: id, queue, message class, exception, failed-at, attempts. Each row has Retry, Discard, and View-payload actions. The empty state renders cleanly when no failed jobs exist. |
| FR-006 | Mandatory | `useQueueJobs()` composable exposes `{jobs, meta, isLoading, fetch, retry, discard}` and is covered by vitest. |
| FR-007 | Mandatory | Playwright smoke `e2e/queue.spec.ts` verifies the page renders empty and that Retry / Discard buttons fire the correct API calls. |
| FR-008 | Mandatory | Add `ScheduleRunner::runOne(string $taskName, \DateTimeInterface $now): ScheduleRunResult`. Looks up the task in the registry, executes it, calls `ScheduleStateRepository::recordRun()`. Throws on unknown task. |
| FR-009 | Mandatory | `GET /api/scheduler/tasks` returns `{data: [{name, description, expression, timezone, last_run_at, last_status, next_run_at}, ...]}` resolved from the `Schedule` registry + `ScheduleStateRepository::getState()` + `ScheduledTask::getNextRunDate()`. Admin-only. |
| FR-010 | Mandatory | `POST /api/scheduler/tasks/{name}/trigger` calls `ScheduleRunner::runOne()` and returns 200 with `{status, message, exception_class?}`. Returns 404 on unknown task. Never serializes a `\Throwable` directly. |
| FR-011 | Mandatory | `/admin/scheduler` Nuxt page renders a table of scheduled tasks with columns: name, description, schedule, last run, last status (chip), next run. Each row has a Run-now action. |
| FR-012 | Mandatory | `useScheduledTasks()` composable exposes `{tasks, isLoading, fetch, trigger}` and is covered by vitest. Playwright smoke `e2e/scheduler.spec.ts` verifies the page and trigger button. |
| FR-013 | Mandatory | Both `/admin/queue` and `/admin/scheduler` are wired into the admin left-rail nav. |
| FR-014 | Mandatory | `docs/specs/admin-spa.md` is updated during WP02 to document the new routes and their access policies. |
| FR-015 | Mandatory | `CHANGELOG.md` `[Unreleased]` → **Added** entries land: one per WP, each citing #1471. |
| FR-016 | Mandatory | Follow-up issue is filed during WP01 wrap-up for `TransportInterface::listJobs()` + queued/in-progress dashboard columns. |
| NFR-001 | Mandatory | Backend response shape and access-control enforcement match M4A-1's pattern (PR #1429) — same envelope, same `_role` mechanism, no new policy classes. |
| NFR-002 | Mandatory | Frontend composable + page + i18n key shape mirror M4A-1. Reviewers should be able to diff structurally. |
| NFR-003 | Mandatory | `Schedule` registry is resolved per-request from the container, never via a cached snapshot. |
| C-001 | Constraint | Queued and in-flight jobs are explicitly out of scope (deferred). The dashboard shows failed jobs only. |
| C-002 | Constraint | No job-payload editing. No cron-expression editing. No worker process management. No live updates (Mercure/SSE). |

## Acceptance criteria

WP01:
- [ ] `GET /api/queue/jobs?page=1&per_page=20` returns `{data: [...], meta: {page, per_page, total}}` when called as admin; returns 403 for non-admin.
- [ ] `POST /api/queue/jobs/{id}/retry` re-enqueues the job (verified via Pest by asserting `FailedJobRepository::find()` returns null after retry and the transport now has the job).
- [ ] `POST /api/queue/jobs/{id}/discard` removes the job and returns 204.
- [ ] `/admin/queue` page renders the list, Retry and Discard buttons function end-to-end via Playwright smoke.
- [ ] `useQueueJobs()` composable covered by vitest.
- [ ] Follow-up issue filed for `TransportInterface::listJobs()` + queued/in-progress dashboard columns.

WP02:
- [ ] `ScheduleRunner::runOne()` covered by a unit test (task found, task not found, recordRun side effect).
- [ ] `GET /api/scheduler/tasks` returns the registered task list with `last_*` and `next_run_at` populated; admin-only.
- [ ] `POST /api/scheduler/tasks/{name}/trigger` runs the task and returns the `ScheduleRunResult`.
- [ ] `/admin/scheduler` page renders the list, Run-now button works via Playwright smoke.
- [ ] `docs/specs/admin-spa.md` updated to document both new routes (queue + scheduler).

Cross-WP:
- [ ] Both routes appear in the admin left-nav.
- [ ] `composer test` passes.
- [ ] `cd packages/admin && npm test && npm run typecheck && npm run lint` all green.
- [ ] CHANGELOG `[Unreleased]` → **Added** entry per WP.
- [ ] Final WP02 PR comment on #1414 updates M4 status (M4B closed, M4A-5 + M4C still open).

## Implementation notes

- **`FailedJobRepositoryInterface`** already exposes `all()`, `find()`, `forget()`, `retry()` — the queue dashboard is purely a wiring exercise on the backend; no contract changes.
- **`ScheduleStateRepository::getState()`** already returns last-run data. Only `ScheduleRunner::runOne()` is new.
- **Pattern parity:** every shape choice (controller layout, JSON envelope, composable signature, i18n key scheme, Playwright file naming) mirrors M4A-1 (workflows admin, PR #1429). Reviewers should be able to diff WP01 against #1429 and see structural symmetry.
- **Admin-role enforcement:** use the existing `_role` route option (same mechanism M4A used). No new policy class.
- **Two PRs, sequenced.** WP01 lands first. WP02 follows. They're technically independent but sequential keeps review focused.

## Risks

- **Empty FailedJobRepository in fresh installs.** The dashboard should render an empty-state ("No failed jobs") cleanly. Covered by Playwright smoke against an empty SQLite.
- **Scheduler task discovery timing.** `Schedule` registry is populated at service-provider boot. Make sure the `/api/scheduler/tasks` controller resolves the registry through the container, not a snapshot at construct time.
- **`ScheduleRunResult` serialization.** It carries a `\Throwable` on failure. Convert to `{status, message, class}` in the controller — never serialize a Throwable directly into the response.

## Out-of-band

Follow-up issue (filed during WP01 wrap-up):

> **`TransportInterface::listJobs()` + queued/in-progress queue dashboard columns**
>
> WP01 of `queue-scheduler-admin-01KSBKQF` deferred queued/in-flight job visibility because `TransportInterface` has no `listJobs()` method. Add it (with `int $limit`, `int $offset`, `?string $status`), implement in `DbalTransport` and `InMemoryTransport`, contract-test it, then expand `GET /api/queue/jobs` to accept `status=queued|in_progress|failed` and add the corresponding columns + filter chips to `/admin/queue`. Estimate: ~250 LOC.
