# Implementation Plan: Notification Rules Admin

**Mission:** `notification-rules-admin-01KSDRNW`
**Spec:** [spec.md](./spec.md)
**Target branch:** `main`
**Tracking:** GitHub #1472 (umbrella #1414)
**Pattern reference:** M4B WP01 (queue admin, PR squash `f0317b429`).

## Overview

Single WP, single PR. Sonnet implements, opus reviews. The whole mission ships ~400 LOC because the data model is intentionally MVP-small.

| WP | Title | Est. LOC | Type |
|---|---|---|---|
| WP01 | Notification channels + test send | ~400 | Backend prep + Backend + Frontend |

## WP01 — Notification channels + test send

### Backend prep (`packages/notification`)

**Modified files:**
- `packages/notification/src/NotificationDispatcher.php` — add `public function channels(): array` returning `$this->channels`. No other changes.

**Tests:**
- `packages/notification/tests/Unit/NotificationDispatcherTest.php` — extend (or create) to assert `channels()` returns the constructor-provided map.

### Backend (`packages/api`)

**New files:**
- `packages/api/src/Controller/NotificationController.php` — `index(Request)`, `test(Request, string $type)`. Private inner anonymous-class definitions for `TestRecipient` (NotifiableInterface) and `TestNotification` (NotificationInterface). Channel lookup via `$dispatcher->channels()`. Try-catch wraps the `send()` call into the structured envelope.
- `packages/api/src/Http/Router/NotificationAdminApiRouter.php` — mirror `QueueAdminApiRouter`. Match `NotificationController::`, dispatch `index` / `test`.

**Modified files:**
- `packages/api/src/ApiServiceProvider.php` — append a third `resolveOptional()` block for `NotificationDispatcher::class`. Mirror the queue + scheduler blocks.
- `packages/api/composer.json` — add `"waaseyaa/notification": "^<current-tag>"` to `require`, and the `../notification` path repository entry.

**Routes — `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:**
- `api.notification.channels.index` — `GET /api/notification/channels` (`_role: admin`).
- `api.notification.channels.test` — `POST /api/notification/channels/{type}/test` (`_role: admin`).
- Use string FQCN `'Waaseyaa\\Api\\Controller\\NotificationController'`.

**Tests:**
- `packages/api/tests/Unit/Controller/NotificationControllerTest.php` — PHPUnit, anonymous classes for `NotificationDispatcher` (via subclass + custom `channels()` override or via anonymous extending) and `ChannelInterface`. Cover: index shape, test 200, test 404 (unknown type), test 500 (channel throws — assert envelope shape including `exception_class`).
- `tests/Integration/PhaseNotificationAdmin/NotificationAdminEndpointsTest.php` — boot kernel; register a fake `ChannelInterface` (anonymous class) that records calls in a public array. Hit endpoints as admin + non-admin, assert response shapes + 403.

### Frontend (`packages/admin`)

**New files:**
- `app/pages/notifications/index.vue` — table page. Columns: type, class (truncated FQCN with tooltip), action. Per-row Test button. Result row appears under the table after a test send (success chip or failure card).
- `app/composables/useNotificationChannels.ts` — `{channels, loading, error, fetchChannels, testChannel}`. `testChannel(type)` returns the parsed result envelope.
- `app/components/notification/NotificationChannelRow.vue` — row with Test button + confirm modal.

**Modified files:**
- `app/i18n/en.json` — `notifications_title`, `notifications_empty`, `notifications_column_type`, `notifications_column_class`, `notifications_column_action`, `notifications_action_test`, `notifications_confirm_test_title`, `notifications_confirm_test_body`, `notifications_status_success`, `notifications_status_failure`, `notifications_help`.
- `app/components/layout/NavBuilder.vue` — `<NuxtLink to="/notifications">` in the "Operations" section (after `/scheduler`).
- `tests/components/layout/NavBuilder.test.ts` — add assertion that the `/notifications` link renders in the Operations section.

**Tests:**
- `tests/unit/composables/useNotificationChannels.test.ts` — vitest. List load, test success, test failure shape.
- `e2e/notifications.spec.ts` — Playwright smoke. Visit, list renders, click Test, confirm, assert POST + result chip.

### Spec stamp + CHANGELOG + close-out

- `docs/specs/admin-spa.md` — append a stamp documenting `/notifications` + the two API endpoints under the existing route inventory comment thread.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: notification channels dashboard at /notifications with per-channel test send. (#1472)`
- File the follow-up GitHub issue for delivery log + channel enable/disable + 2-tab UI (text in spec.md "Out-of-band" section).
- After PR lands: comment on parent #1414 with M4 status update (M4B + M4C closed, M4A-5 remaining).
- Close issue #1472 from the merge commit footer (`Closes #1472`).
- Commit footers use `Refs #1472` for in-progress commits, `Closes #1472` only on the final merge.

## Verification gate

In the lane worktree, BEFORE `move-task --to for_review`:

1. `composer install` (lane worktrees lack vendor)
2. `vendor/bin/phpunit packages/notification/tests/Unit/NotificationDispatcherTest.php`
3. `vendor/bin/phpunit packages/api/tests/Unit/Controller/NotificationControllerTest.php`
4. `vendor/bin/phpunit tests/Integration/PhaseNotificationAdmin/`
5. `vendor/bin/phpunit tests/Integration/PhaseQueueAdmin/ tests/Integration/PhaseSchedulerAdmin/` (regression — M4B is the closest neighbour)
6. `composer cs-check` (cs-fix + cache clear if needed — memory `feedback_cs_fix_two_passes`)
7. `composer phpstan`
8. `bin/check-package-layers`
9. `bin/check-dead-code`
10. `bin/check-getquery-bindings`
11. `bin/check-composer-policy`
12. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
13. Playwright NOT run; flag in handoff.

## Risk log

- **`NotificationDispatcher::send()` is sync but routes through every applicable channel.** The controller MUST call the SINGLE channel's `send()` directly to scope the test to one type — do not use `$dispatcher->send($recipient, $notification)`.
- **Test recipient routing.** If the requesting account doesn't have an email, the `mail` channel's test will likely throw; that's actually GOOD — it surfaces the misconfiguration. The 500 envelope is the right place to communicate that.
- **`DatabaseChannel::send()`** writes to the `notifications` table. A test send leaves a row. Acceptable for MVP; document the row will appear in the user's in-app inbox.

## Reviewer (opus) focus

- (a) `_role: admin` enforced at route level only, controller does NOT re-check.
- (b) `\Throwable` never crosses JSON boundary — `getMessage()` + `::class` extraction.
- (c) Test action targets exactly ONE channel (not all of them).
- (d) Follow-up issue actually filed before merge.
- (e) Pattern parity with `QueueController` and `useQueueJobs.ts`.
- (f) Spec stamp on `docs/specs/admin-spa.md` lands in this WP.
