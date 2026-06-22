# Notification Rules Admin

**Mission:** `notification-rules-admin-01KSDRNW`
**Status:** Spec
**Target branch:** `main`
**Tracks:** GitHub issue #1472 (umbrella #1414). Closes audit rows C-L3-02 + C-L0-03 in `docs/audits/admin-spa-modernization-2026-05-10.md`.
**Pattern reference:** M4B WP01/WP02 (PR squash `f0317b429`) — same controller-router-page shape, same `_role: admin` enforcement, same scope-reduction-with-follow-up convention.

## Why this mission exists

Operators have no admin-side visibility into the notification pipeline today. Channel routing is dispatcher-internal (`NotificationServiceProvider::buildChannels()`), delivery audit lives in DB rows + log files, and there is no way to confirm "is mail actually working in this environment" without writing a test script. The audit (#1414 / `docs/audits/admin-spa-modernization-2026-05-10.md`, rows C-L3-02 + C-L0-03) calls out notification admin as the third Phase-1 operator gap.

This mission is deliberately scoped narrow — see the deferral block under "Out of scope" — because the notification package does not yet carry the persistence the full feature wants (no `delivery_log` table, no `ChannelConfig` model, no per-channel enable/disable state). Building those is its own package-level work that does not belong in an admin-SPA mission; this mission ships the visibility surface against what already exists and files the persistence work as a follow-up.

## Scope

### In scope (WP01 only)

**Backend — `packages/notification/src/NotificationDispatcher.php`:**
- Add `public function channels(): array<string, ChannelInterface>` (~5 LOC). Returns the dispatcher's internal channel map. Pure read accessor — no behavior change.

**Backend — `packages/api/src/Controller/NotificationController.php`:**
- `GET /api/notification/channels` — list configured channels. Returns `{data: [{type, class}, ...]}`. The map key (`'mail'`, `'database'`) is the public `type`; `class` is the `ChannelInterface::class` FQCN. Admin-only.
- `POST /api/notification/channels/{type}/test` — send a synthetic test notification through one named channel, bypassing the queue. Builds a `TestNotification` (in-controller anonymous-class-style notification with a fixed subject/body) and a `TestRecipient` (anonymous `NotifiableInterface` for the requesting admin account), then calls `ChannelInterface::send()` directly. Returns 200 `{type, status, message}` on success, 404 if `{type}` is not in the channel map, 500 with `{status: 'failed', message, exception_class}` if the channel throws (FR-010 style — never serialize a `\Throwable`). Admin-only.

**Backend — `packages/api/src/Http/Router/NotificationAdminApiRouter.php`:**
- Mirror `QueueAdminApiRouter` / `SchedulerAdminApiRouter`. `supports()` matches `NotificationController::`, `handle()` dispatches `index` and `test`. Same JSON:API error envelope.

**Backend — `packages/api/src/ApiServiceProvider.php`:**
- Append a third `resolveOptional()` block alongside the queue + scheduler ones. If `NotificationDispatcher` resolves, instantiate `NotificationController` + `NotificationAdminApiRouter`. Skip cleanly if the notification package is not present (slimmed-down installs).

**Routes — `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:**
- `api.notification.channels.index` — `GET /api/notification/channels`, `_role: admin`.
- `api.notification.channels.test` — `POST /api/notification/channels/{type}/test`, `_role: admin`.
- String FQCN `'Waaseyaa\\Api\\Controller\\NotificationController'`.

**Frontend — `packages/admin/`:**
- `app/pages/notifications/index.vue` — single-page dashboard (no tabs in MVP since delivery log is deferred). Table of channels with columns: type, class, test action. "Test" button on each row opens a confirm modal, then fires `POST /api/notification/channels/{type}/test` and surfaces the result inline (success chip or failure banner with message + exception class).
- `app/composables/useNotificationChannels.ts` — `{channels, loading, error, fetchChannels, testChannel}`. Returns the test result.
- `app/components/notification/NotificationChannelRow.vue` — row component with Test button.
- i18n: extend `app/i18n/en.json` with `notifications_title`, `notifications_empty`, `notifications_column_*`, `notifications_action_test`, `notifications_confirm_test_*`, `notifications_status_*`.
- Nav: add `<NuxtLink to="/notifications">` to the "Operations" section of `app/components/layout/NavBuilder.vue` (alongside `/queue` and `/scheduler`).

**Tests:**
- `packages/notification/tests/Unit/NotificationDispatcherTest.php` — extend (or create) to cover the new `channels()` accessor.
- `packages/api/tests/Unit/Controller/NotificationControllerTest.php` — PHPUnit 10.5, anonymous classes implementing dispatcher collaborators. Cover list shape, test happy path, test 404 on unknown type, test 500 with structured failure envelope on channel throw.
- `tests/Integration/PhaseNotificationAdmin/NotificationAdminEndpointsTest.php` — boot a kernel, register one real channel (e.g. `DatabaseChannel` against in-memory SQLite or an anonymous-class fake), hit each endpoint as admin + non-admin, assert response shape + 403.
- `packages/admin/tests/unit/composables/useNotificationChannels.test.ts` — vitest.
- `packages/admin/e2e/notifications.spec.ts` — Playwright smoke. Visit `/notifications`, assert list renders, click Test (confirm), assert POST fires and result is surfaced.

**Spec stamp:** Update `docs/specs/admin-spa.md` to add `/notifications` to the route inventory.

**CHANGELOG:** `[Unreleased]` → **Added**: `Admin SPA: notification channels dashboard at /notifications with per-channel test send. (#1472)`

### Out of scope

- **Delivery log.** `packages/notification/` has no `delivery_log` table — channels write to their endpoints (mail server, in-app `notifications` table) without recording a per-attempt audit row. Building that persistence touches `ChannelInterface` (add `send()` instrumentation), every `Channel` implementation, and adds a new migration. Filed as a follow-up at WP wrap-up.
- **Channel enable/disable.** Channels today are an in-memory array built at boot from successful service resolution (`MailerInterface`, `DatabaseInterface`). There is no `ChannelConfig` model, no enabled flag, no persistence. The full "channel admin" UI from the issue (`{ id, type, enabled, target, last_delivery_at, last_status }`) needs the deferred persistence work. WP01 ships only what's truthful against the current data model.
- **Template editing.** Notification templates are code-defined (`NotificationInterface::toArray()`). No edit UI in this mission.
- **Mercure / SSE live updates** for new delivery rows — separate concern, M5 territory per audit C-L0-04.
- **Tabs (channels / deliveries).** The original issue scopes a 2-tab UI; this MVP ships a single channels page. Deferred to the same follow-up as the delivery log.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `NotificationDispatcher` exposes `public function channels(): array<string, ChannelInterface>` returning the registered channel map. No behavior change to `send()` / `sendAsync()` / `sendToMany()`. |
| FR-002 | Mandatory | `GET /api/notification/channels` returns `{data: [{type, class}, ...]}` where `type` is the dispatcher map key and `class` is the FQCN of the bound `ChannelInterface`. Admin-only. |
| FR-003 | Mandatory | `POST /api/notification/channels/{type}/test` sends a synthetic `TestNotification` to a synthetic `TestRecipient` via the named channel only, bypassing the queue. Returns 200 `{type, status: "success", message}` on completion. Admin-only. |
| FR-004 | Mandatory | `POST /api/notification/channels/{type}/test` returns 404 when `{type}` is not a registered channel key. |
| FR-005 | Mandatory | When the channel's `send()` throws, the endpoint returns 500 with `{type, status: "failed", message, exception_class}`. Never serialize the `\Throwable` directly — extract `getMessage()` + `::class`. |
| FR-006 | Mandatory | Both endpoints enforce admin-only access via the `_role: admin` route option; non-admin callers receive 403. The controller does NOT re-check role. |
| FR-007 | Mandatory | `/notifications` Nuxt page renders a table of registered channels with columns: type, class, action. Empty state ("No channels configured.") renders cleanly when the dispatcher map is empty. |
| FR-008 | Mandatory | Test button confirms via modal, then surfaces the test result inline (success chip or failure banner with `message` + `exception_class`). |
| FR-009 | Mandatory | `useNotificationChannels()` exposes `{channels, loading, error, fetchChannels, testChannel}` and is covered by vitest. Playwright smoke `e2e/notifications.spec.ts` verifies the page and test action. |
| FR-010 | Mandatory | `/notifications` is wired into the admin left-rail "Operations" nav section (alongside `/queue` and `/scheduler`). |
| FR-011 | Mandatory | `docs/specs/admin-spa.md` stamped to add `/notifications` to the route inventory. |
| FR-012 | Mandatory | `CHANGELOG.md` `[Unreleased]` → **Added** entry filed. |
| FR-013 | Mandatory | Follow-up issue filed during WP wrap-up for `delivery_log` persistence + per-channel enable/disable + 2-tab UI. |
| NFR-001 | Mandatory | Controller, router, page, and composable shapes mirror M4B's queue + scheduler. Reviewers should be able to diff structurally against `QueueController` / `useQueueJobs.ts`. |
| NFR-002 | Mandatory | `NotificationDispatcher` resolved per-request from the container (not snapshotted at boot). |
| C-001 | Constraint | Delivery log, channel enable/disable, and the 2-tab UI from the original issue are explicitly deferred. The dashboard shows only what is truthful against the current data model. |
| C-002 | Constraint | No template editing. No worker / queue process management. No live updates (Mercure / SSE). |

## Acceptance criteria

- [ ] FR-001..FR-013 met.
- [ ] All gates green: `vendor/bin/phpunit` (mission-scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- [ ] `cd packages/admin && npm test && npm run typecheck && npm run lint` all green.
- [ ] Follow-up issue filed (link from PR description).
- [ ] Commit footers use `Refs #1472`.
- [ ] Audit rows C-L3-02 + C-L0-03 stamped CLOSED in wrap-up.

## Implementation notes

- **Channel iteration:** add `channels()` to `NotificationDispatcher`. Keep it simple — just `return $this->channels;`. The constructor already stores it as `private readonly array`.
- **Test recipient + notification:** the controller can hold private inner anonymous classes for `TestRecipient implements NotifiableInterface` and `TestNotification implements NotificationInterface`. `TestRecipient::routeNotificationFor($channel)` returns whatever the channel needs (email for `mail`, account id for `database`). Pull the requesting account from `$request->attributes->get('_account')` (with the underscore — see project CLAUDE.md "Request attribute is `_account`").
- **Bypass-the-queue test send:** call the channel's `send()` directly, do not go through `NotificationDispatcher::send()` (which dispatches via queue). The operator wants immediate feedback. Wrap in try-catch to convert `\Throwable` into the structured 500 envelope.
- **Layer compliance:** `packages/api` is L4, `packages/notification` is L3 — L4→L3 is allowed. `packages/api/composer.json` needs `waaseyaa/notification: ^<current-tag>` added; mirror the M4B pattern for `waaseyaa/queue`.
- **Foundation route registration** uses string FQCN — same M4B / M4A pattern. Layer-safe.

## Risks

- **`NotificationDispatcher` may not be in the container in every install.** The L4 `ApiServiceProvider` uses the established `resolveOptional()` pattern (introduced in M4B); the notification routers are appended only if `NotificationDispatcher::class` resolves cleanly. Slimmed-down installs without `waaseyaa/notification` get a working admin SPA with the channels link returning empty data — acceptable, mirrors the queue/scheduler graceful-skip path.
- **Test send may send a real email to the admin's address.** The `TestNotification` body should be unambiguous ("This is a test from /notifications — no action required") so a recipient who somehow sees it understands. Subject prefix `[Waaseyaa test]`.
- **`NotificationDispatcher` constructor takes a `private readonly` channels array.** PHP 8.4+ behaves correctly here — the new public accessor reads the same field. No reflection trickery.

## Out-of-band

Follow-up issue (filed during WP wrap-up):

> **`[notification] Delivery log + channel enable/disable + 2-tab notifications admin`**
>
> WP01 of `notification-rules-admin-01KSDRNW` shipped only the channels list + test send because the notification package has no `delivery_log` table, no `ChannelConfig` model, and no per-channel enable/disable state. To complete the dashboard from #1472:
>
> 1. Add a `delivery_log` migration + repository in `packages/notification/`. Hook `DeliveryAttempt` writes into every `ChannelInterface::send()` (success + failure rows).
> 2. Add a `ChannelConfig` model with enabled flag + target persistence. Adapt `NotificationServiceProvider::buildChannels()` to consult config.
> 3. Add `GET /api/notification/deliveries?since=&limit=` to the dashboard.
> 4. Add a deliveries tab to `/notifications`.
>
> Estimate: ~600 LOC across notification + api + admin. Parent: #1414. Sibling: #1472 (this mission).
