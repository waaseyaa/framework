---
work_package_id: WP01
title: Notification channels + test send
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-008
- FR-009
- FR-010
- FR-011
- FR-012
- FR-013
- NFR-001
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-notification-rules-admin-01KSDRNW
base_commit: 24dfbfd416f4116d8d50de49088316dc1a2babef
created_at: '2026-05-24T19:57:23.685384+00:00'
subtasks: []
shell_pid: "389474"
history: []
authoritative_surface: packages/api/src/Controller/NotificationController.php
execution_mode: code_change
owned_files:
- packages/notification/src/NotificationDispatcher.php
- packages/notification/tests/Unit/NotificationDispatcherTest.php
- packages/api/src/Controller/NotificationController.php
- packages/api/src/Http/Router/NotificationAdminApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/tests/Unit/Controller/NotificationControllerTest.php
- packages/api/composer.json
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- packages/admin/app/pages/notifications/index.vue
- packages/admin/app/composables/useNotificationChannels.ts
- packages/admin/app/components/notification/NotificationChannelRow.vue
- packages/admin/app/components/layout/NavBuilder.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useNotificationChannels.test.ts
- packages/admin/e2e/notifications.spec.ts
- docs/specs/admin-spa.md
- CHANGELOG.md
tags: []
agent: "claude:opus:reviewer:reviewer"
---

# WP01 — Notification channels + test send

**Mission:** `notification-rules-admin-01KSDRNW`
**Spec:** [../spec.md](../spec.md)
**Plan:** [../plan.md](../plan.md)
**Tracking:** GitHub issue #1472 (umbrella #1414)
**Pattern reference (CANONICAL):** M4B WP01 (queue admin) — `QueueController`, `QueueAdminApiRouter`, `useQueueJobs.ts`, `pages/queue/index.vue`. They landed in the squash merge `f0317b429`. **Read those files first** and mirror them exactly.

## What you're building

Single-PR mission. Admin dashboard at `/notifications` that lists registered notification channels (mail, in-app database, future) and lets the operator fire a synthetic test notification through any one of them. Delivery log and channel enable/disable are explicitly out of scope (no persistence for those yet — file a follow-up issue).

## Required reading before you start

1. `cat /tmp/spec-kitty-implement-WP01.md` — the spec-kitty implementation prompt.
2. `kitty-specs/notification-rules-admin-01KSDRNW/spec.md` and `plan.md`.
3. The shipped M4B files — your structural template:
   - `packages/api/src/Controller/QueueController.php`
   - `packages/api/src/Http/Router/QueueAdminApiRouter.php`
   - `packages/api/src/ApiServiceProvider.php` (the `resolveOptional()` pattern)
   - `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (the string-FQCN route registration)
   - `packages/admin/app/pages/queue/index.vue`
   - `packages/admin/app/composables/useQueueJobs.ts`
   - `packages/admin/app/components/queue/QueueJobRow.vue`
4. `packages/notification/src/NotificationDispatcher.php` — to understand the constructor's `private readonly array $channels` and add the accessor.
5. `packages/notification/src/ChannelInterface.php` — single `send(NotifiableInterface, NotificationInterface): void` method.
6. `packages/notification/src/{NotifiableInterface,NotificationInterface}.php` — to write the synthetic test recipient + test notification.

## Critical context

- **Lane worktree** — work in `/home/jones/dev/waaseyaa/.worktrees/notification-rules-admin-01KSDRNW-lane-a` (path will be reported by `spec-kitty agent action implement WP01`). NOT the main repo.
- **Run `composer install` first** — lane worktrees lack vendor.
- **Run `cd packages/admin && npm install` first** — admin SPA needs node_modules.
- **`_role: admin` enforced at route level only.** Do NOT re-check role in the controller (NFR-001 — M4B already established this).
- **PHP 8.5+.** Use modern features. `declare(strict_types=1)` in every new file.
- **No mocking of `final class`** — use anonymous classes implementing the relevant interfaces (project CLAUDE.md gotcha).
- **`\Throwable` never crosses the JSON boundary** — extract `getMessage()` + `::class`. This pattern is from M4B/FR-010.

## Subtasks

**T001 — `NotificationDispatcher::channels()` accessor**
- Add `public function channels(): array` to `packages/notification/src/NotificationDispatcher.php`. Return `$this->channels;` — that's it.
- Extend `packages/notification/tests/Unit/NotificationDispatcherTest.php` (create if missing) to assert the accessor returns the constructor-provided map.

**T002 — `NotificationController` + router + tests**
- New file `packages/api/src/Controller/NotificationController.php`:
  - Constructor takes `private readonly NotificationDispatcher $dispatcher`.
  - `index(Request $request): array` — returns `{data: [{type, class}, ...]}` from `$this->dispatcher->channels()`. The router wraps it in `JsonResponse` (mirror `QueueAdminApiRouter::handle()`'s `index` branch).
  - `test(Request $request, string $type): Response` — look up channel via `$this->dispatcher->channels()[$type] ?? null`. If null, return 404 JSON error envelope. Otherwise build a `TestRecipient` (anonymous class implementing `NotifiableInterface`; `routeNotificationFor()` reads the requesting account from `$request->attributes->get('_account')`) and a `TestNotification` (anonymous class implementing `NotificationInterface`; subject `[Waaseyaa test]`, body explains "this is a test from /notifications — no action required"). Call `$channel->send($recipient, $notification)` inside try-catch. On success: 200 `{type, status: "success", message: "Test sent."}`. On `\Throwable`: 500 `{type, status: "failed", message: $e->getMessage(), exception_class: $e::class}`.
- New file `packages/api/src/Http/Router/NotificationAdminApiRouter.php` — mirror `QueueAdminApiRouter`. Matches `NotificationController::` in `_controller`; `handle()` dispatches by action. JSON:API error envelope for the `default` arm.
- Modify `packages/api/src/ApiServiceProvider.php`: add a third `resolveOptional()` block for `NotificationDispatcher::class`. If it resolves, instantiate `NotificationController` + `NotificationAdminApiRouter`.

**T003 — Routes + composer wiring**
- Modify `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` to register the two routes using string FQCN `'Waaseyaa\\Api\\Controller\\NotificationController'`. Put the block right after the scheduler routes (so the layout reads "queue → scheduler → notification"). Both routes `->requireRole('admin')`.
- Modify `packages/api/composer.json`: add `"waaseyaa/notification": "^<current-tag>"` to `require` (look at how `waaseyaa/queue` and `waaseyaa/scheduler` are listed — match that floor). Add `"../notification"` path repository entry. Run `composer update --lock waaseyaa/notification` to refresh `composer.lock`.

**T004 — Backend tests**
- `packages/api/tests/Unit/Controller/NotificationControllerTest.php`:
  - Cover `index()`: constructor-built `NotificationDispatcher` with two anonymous-class `ChannelInterface` fakes; assert response shape `{data: [{type, class}, ...]}`.
  - Cover `test()` happy path: anonymous channel records the `send()` call; controller returns 200 with `status: success`.
  - Cover `test()` 404: type not in map.
  - Cover `test()` 500: anonymous channel's `send()` throws; assert response 500 + structured envelope including `exception_class`.
- `tests/Integration/PhaseNotificationAdmin/NotificationAdminEndpointsTest.php`: mirror `tests/Integration/PhaseQueueAdmin/QueueAdminEndpointsTest.php`. Boot kernel via `BuiltinRouteRegistrar` + `WaaseyaaRouter`. Hit both endpoints with admin and non-admin accounts via `AccessChecker`. Assert response shapes + 403 enforcement.

**T005 — Frontend + spec stamp + CHANGELOG**
- `packages/admin/app/pages/notifications/index.vue` — table page. Mirror `pages/queue/index.vue` for shape. Columns: type, class (FQCN, truncated with `title` attribute for full), action (Test button). When a test result lands, show a success chip or a failure card below the table (use the same brand CSS tokens M4B used for success/failure).
- `packages/admin/app/composables/useNotificationChannels.ts` — mirror `useQueueJobs.ts`. Expose `{channels, lastTestResult, loading, error, fetchChannels, testChannel}`.
- `packages/admin/app/components/notification/NotificationChannelRow.vue` — row with Test button + confirm modal (same shape as `QueueJobRow.vue`'s discard-confirm flow).
- Extend `packages/admin/app/i18n/en.json` with the keys listed in the spec.
- Modify `packages/admin/app/components/layout/NavBuilder.vue` — add `<NuxtLink to="/notifications" class="nav-item" data-testid="nav-notifications">{{ t('notifications_title') }}</NuxtLink>` to the Operations section (right after the scheduler link).
- Update `packages/admin/tests/components/layout/NavBuilder.test.ts` — add assertion that the new link renders.
- `packages/admin/tests/unit/composables/useNotificationChannels.test.ts` — vitest. Mirror `useQueueJobs.test.ts`. Cover list load, testChannel success, testChannel failure.
- `packages/admin/e2e/notifications.spec.ts` — Playwright smoke. Visit `/notifications`, mock the channel list, click Test, confirm, assert the POST fires and the result chip renders.
- Append a stamp to `docs/specs/admin-spa.md` (right after the M4B WP02 stamp at the top) summarising the new route + endpoints + deferred persistence work.
- Add to `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: notification channels dashboard at /notifications with per-channel test send. (#1472)`

## Verification gate (run BEFORE moving to for_review)

In the lane worktree:
1. `composer install`
2. `cd packages/admin && npm install && cd -`
3. `vendor/bin/phpunit packages/notification/tests/Unit/NotificationDispatcherTest.php`
4. `vendor/bin/phpunit packages/api/tests/Unit/Controller/NotificationControllerTest.php`
5. `vendor/bin/phpunit tests/Integration/PhaseNotificationAdmin/`
6. `vendor/bin/phpunit tests/Integration/PhaseQueueAdmin/ tests/Integration/PhaseSchedulerAdmin/` (regression — M4B should still be green)
7. `composer cs-check` (cs-fix + cache clear if needed)
8. `composer phpstan`
9. `bin/check-package-layers`
10. `bin/check-dead-code`
11. `bin/check-getquery-bindings`
12. `bin/check-composer-policy`
13. `cd packages/admin && npm test && npm run typecheck && npm run lint`
14. Playwright NOT run; flag in handoff note.

## Out-of-band: file the follow-up GitHub issue BEFORE moving to for_review

```bash
gh issue create \
  --title "[notification] Delivery log + channel enable/disable + 2-tab notifications admin" \
  --label admin-spa,audit-followup \
  --body "<text from spec.md 'Out-of-band' section>"
```

Record the issue number in the WP01 handoff note.

## Commit + handoff

- Commit messages: `feat(notification): NotificationDispatcher::channels() accessor` / `feat(api): notification admin controller + routes` / `feat(admin): /notifications dashboard` / `docs(specs): admin-spa.md stamp + CHANGELOG (M4C)`. Use `Refs #1472` footer on every commit.
- Mark subtasks:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 T005 --status done --mission notification-rules-admin-01KSDRNW
  ```
- Move to for_review:
  ```
  spec-kitty agent tasks move-task WP01 --to for_review --mission notification-rules-admin-01KSDRNW --note "M4C notification channels dashboard ready; follow-up #<N> filed for delivery log + channel enable/disable; closes audit C-L3-02 + C-L0-03"
  ```

## Report back with

1. Commit SHAs in the lane worktree.
2. The follow-up issue URL + number.
3. Whether you needed to alter `NotificationDispatcher`'s constructor beyond the accessor (you shouldn't — flag if you did).
4. Whether any pre-existing tests were touched (regression-only changes).
5. Any deviations from the WP file and why.

## Activity Log

- 2026-05-24T19:57:25Z – claude:sonnet:implementer:implementer – shell_pid=374462 – Assigned agent via action command
- 2026-05-24T20:15:27Z – claude:sonnet:implementer:implementer – shell_pid=374462 – M4C ready: notification channels dashboard + per-channel test send wired end-to-end. Follow-up #1578 filed for delivery log + channel enable/disable + 2-tab notifications admin. Closes audit C-L3-02 + C-L0-03. Backend gates all green (cs-check, phpstan, package-layers, dead-code, getquery-bindings, composer-policy). Unit + integration tests: 34 pass / 163 assertions across PhaseNotificationAdmin + queue/scheduler regression. Frontend: 259 vitest pass, typecheck clean, 0 new lint errors. Playwright e2e/notifications.spec.ts authored but not run locally (needs nuxt dev on 3000).
- 2026-05-24T20:16:11Z – claude:opus:reviewer:reviewer – shell_pid=389474 – Started review via action command
- 2026-05-24T20:18:00Z – claude:opus:reviewer:reviewer – shell_pid=389474 – Review passed (opus). 34/34 tests green (notification + api + integration + M4B regression). phpstan/cs-check/layers/dead-code/getquery/composer-policy all clean. NotificationController extracts Throwable correctly (FR-010). TestRecipient/TestNotification anonymous classes with proper interface implementations. toMail() returning Envelope is a sound correction to the WP spec (MailChannel forwards via method_exists). Account read from _account (project convention). Follow-up #1578 filed with correct labels. Pattern parity with QueueController/SchedulerController confirmed. Playwright deferred for CI as flagged. unused-Request linter warning on index() acceptable - DomainRouter dispatch signature requires it.
- 2026-05-24T20:18:35Z – claude:opus:reviewer:reviewer – shell_pid=389474 – Done override: Mission merged to main (2999abd)
