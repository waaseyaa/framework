---
work_package_id: WP02
title: Scheduler admin
dependencies:
- WP01
requirement_refs:
- FR-008
- FR-009
- FR-010
- FR-011
- FR-012
- FR-013
- FR-014
- FR-015
- NFR-001
- NFR-002
- NFR-003
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks: []
history: []
authoritative_surface: packages/api/src/Controller/SchedulerController.php
execution_mode: code_change
owned_files:
- packages/scheduler/src/ScheduleRunner.php
- packages/scheduler/tests/Unit/ScheduleRunnerTest.php
- packages/api/src/Controller/SchedulerController.php
- packages/api/tests/Unit/Controller/SchedulerControllerTest.php
- packages/admin/app/pages/admin/scheduler.vue
- packages/admin/app/composables/useScheduledTasks.ts
- packages/admin/app/components/admin/SchedulerTaskRow.vue
- packages/admin/test/unit/composables/useScheduledTasks.test.ts
- packages/admin/e2e/scheduler.spec.ts
- docs/specs/admin-spa.md
tags: []
agent: "claude:opus:reviewer:reviewer"
shell_pid: "367264"
---

# WP02 — Scheduler admin

**Mission:** `queue-scheduler-admin-01KSBKQF`
**Spec:** [../spec.md](../spec.md)
**Plan:** [../plan.md](../plan.md)
**Tracking:** GitHub issue #1471 (umbrella #1414)
**Depends on:** WP01 — merge after WP01 PR lands (for shared admin-nav surface and shared composable patterns).
**Pattern reference:** M4A-1 workflows admin (PR #1429) for frontend; WP01 (this mission) for controller layout.

## What you're building

A read-mostly admin dashboard for scheduled tasks (cron registry). Operators see what's registered, when each last ran and how, when each will next run, and can trigger a manual run. Tasks themselves remain code-defined via attributes — there is no edit UI (C-002).

This WP also closes M4B. The final commit comments on parent issue #1414 to update the M4 status board.

## Subtasks

**T006 — Scheduler backend prep (`packages/scheduler/src/ScheduleRunner.php`)**
- Add public method:
  ```php
  public function runOne(string $taskName, \DateTimeInterface $now): ScheduleRunResult
  ```
- Look up the task in the `Schedule` registry by name. If not found, throw `\InvalidArgumentException` (or a domain `TaskNotFoundException` if one exists in the package — check).
- Execute the task using the same code path `run()` uses internally. If `run()` inlines its execution logic, factor it out into a private `runTask(ScheduledTask $task, \DateTimeInterface $now): ScheduleRunResult` and call it from both `run()` and `runOne()`. Do not change `run()`'s public signature or behavior.
- After execution, call `$this->stateRepository->recordRun($taskName, $result->status)` so the dashboard's "last_run / last_status" stays consistent with the runner's own runs.

**T007 — Scheduler tests (`packages/scheduler/tests/Unit/ScheduleRunnerTest.php`)**
- Extend (or create) to cover `runOne()`:
  - Happy path: known task runs, `recordRun` is called once with the right args, the result is returned.
  - Unknown task: throws.
  - Closure tasks and string-command tasks both execute (parity with `run()` coverage).

**T008 — Scheduler API controller (`packages/api/src/Controller/SchedulerController.php`)**
- `index(): Response` — resolve `Schedule` from the container (NFR-003 — per-request). Iterate `Schedule::getTasks()`. For each task, look up state via `ScheduleStateRepository::getState($task->name)` and compute next run via `$task->getNextRunDate($now)`. Return:
  ```json
  {"data": [
    {"name": "...", "description": "...", "expression": "...", "timezone": null,
     "last_run_at": "2026-05-23T22:00:00Z", "last_status": "success", "next_run_at": "2026-05-24T00:00:00Z"}
  ]}
  ```
  If a task has no recorded state, `last_run_at` and `last_status` are `null`.
- `trigger(string $name): Response` — call `ScheduleRunner::runOne($name, new \DateTimeImmutable())`. On `\InvalidArgumentException` return 404. On success return 200 with `{status, message, exception_class?}` derived from `ScheduleRunResult`. **Never** serialize a `\Throwable` directly; extract `getMessage()` and `::class`.

**T009 — Scheduler API tests**
- Unit `SchedulerControllerTest.php` — mock `Schedule` registry + `ScheduleRunner` + `ScheduleStateRepository`. Cover happy path, unknown task, and the `\Throwable` extraction.
- Integration: boot a kernel with two registered tasks (one closure, one string-command), hit `/api/scheduler/tasks` and `/api/scheduler/tasks/{name}/trigger` as admin + non-admin. Assert response shape, 403 enforcement, and that `recordRun()` actually got called after the trigger.

**T010 — Frontend page + composable + tests**
- `packages/admin/app/composables/useScheduledTasks.ts` — `{tasks, isLoading, fetch, trigger}`. Mirror the WP01 composable shape.
- `packages/admin/app/pages/admin/scheduler.vue` — table page. Columns: name, description, schedule (cron expression, monospace), last run (relative time + absolute on hover), last status (status chip: success/failure/never), next run.
- `packages/admin/app/components/admin/SchedulerTaskRow.vue` — row with Run-now button. Confirm modal optional — manual run is generally idempotent for cron jobs, but if any registered task is destructive, prompt. For MVP, a confirmation modal is recommended.
- i18n: extend `packages/admin/app/i18n/en.json` with `scheduler.*` keys.
- Nav: add `/admin/scheduler` to the admin left-rail.
- `packages/admin/test/unit/composables/useScheduledTasks.test.ts` — vitest.
- `packages/admin/e2e/scheduler.spec.ts` — Playwright smoke: visit page, assert list renders, click Run-now (confirm), assert POST fires.

## Spec stamp + CHANGELOG + mission close-out

- Update `docs/specs/admin-spa.md`:
  - Add **both** new routes (queue from WP01 + scheduler from WP02) to the route inventory section.
  - Annotate access policy: `_role: admin`.
  - Stamp the spec with `<!-- Spec reviewed 2026-05-NN - queue + scheduler admin (M4B) -->` (replace NN with the actual date).
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: scheduler dashboard at /admin/scheduler with manual task trigger. (#1471)`
- After WP02 PR merges, add a comment to #1414 (M4 parent) updating the status board:
  ```
  M4B closed:
  - M4B-1 queue admin (#1471) → PR #NNNN
  - M4B-2 scheduler admin (#1471) → PR #NNNN

  Remaining M4:
  - M4A-5 guard editing (#1470)
  - M4C notification rules (#1472)
  ```
- Close issue #1471 with a reference to both PRs.
- Commit footers: `Refs #1471`.
- One PR for the whole WP. Title: `feat(admin-spa): scheduler admin dashboard (M4B-2)`.

## Verification gate

Before requesting review:
- [ ] `vendor/bin/phpunit packages/scheduler/tests/Unit/ScheduleRunnerTest.php` — green.
- [ ] `vendor/bin/phpunit packages/api/tests/Unit/Controller/SchedulerControllerTest.php` — green.
- [ ] `vendor/bin/phpunit tests/Integration/.../SchedulerAdminEndpointsTest.php` — green.
- [ ] `composer cs-check && composer phpstan && composer verify` — green.
- [ ] `cd packages/admin && npm test && npm run typecheck && npm run lint` — green.
- [ ] `cd packages/admin && npm run test:e2e -- scheduler.spec.ts` — green.
- [ ] `docs/specs/admin-spa.md` updated and stamped.
- [ ] Manual smoke: visit `/admin/scheduler`, see the registered tasks list, trigger one, see `last_run_at` + `last_status` update on refresh.

## Out of scope reminders

- Cron expression editing.
- Queue dashboard concerns — those belong to WP01.
- Live updates — separate M5 work per audit row C-L0-04.

## Activity Log

- 2026-05-24T19:09:11Z – claude:sonnet:implementer:implementer – shell_pid=358724 – Started implementation via action command
- 2026-05-24T19:29:39Z – claude:sonnet:implementer:implementer – shell_pid=358724 – WP02 scheduler admin ready; closes M4B; docs/specs/admin-spa.md stamped; CHANGELOG updated
- 2026-05-24T19:30:44Z – claude:opus:reviewer:reviewer – shell_pid=367264 – Started review via action command
- 2026-05-24T19:32:59Z – claude:opus:reviewer:reviewer – shell_pid=367264 – Review passed (opus). All gates green (51/51 scheduler+queue+integration tests, phpstan/cs-check/layers/dead-code/getquery/composer-policy all clean). ScheduleRunner::run() public behavior preserved via runTask() helper (count-of-fired semantics intact, 5 pre-existing tests pass). runOne() correctly bypasses isDue() while honoring preventOverlap lock. Throwable extraction correct - {status, message, exception_class} via FQCN string, throwable never crosses JSON boundary (FR-010). ScheduleRunResult extension purely additive (new params default null, run() callsite unchanged). SchedulerServiceProvider rebinding is additive (same singleton instance, exposed to L4 resolver). Layer hygiene clean (api→scheduler is L4→L0; foundation kernel uses string FQCN per CodifiedContextController/QueueController precedent). Pattern parity with WP01 verified. Spec stamp on docs/specs/admin-spa.md is comprehensive and documents both M4B WP01+WP02. Pre-existing AdminSurface and DriftDetector test failures correctly flagged as not WP02-caused. Playwright not run (needs nuxt dev on :3000) - flagged for CI.
- 2026-05-24T19:40:41Z – claude:opus:reviewer:reviewer – shell_pid=367264 – Done override: Mission merged to main (6c9f32c44) - manual closeout
