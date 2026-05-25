# Implementation Plan: Mercure Broadcast Monitor (M5D)

**Mission:** `mercure-broadcast-monitor-m5d-01KSEFTD` — see `spec.md`.
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — CodifiedContext cross-layer L0→L4 (read contract in api, adapter in foundation). M4B `QueueController`/`QueueAdminApiRouter`. M4A-5 `WorkflowGuardsApiRouter`.
**Two WPs, sequential:** WP02 (frontend) depends on WP01 (backend) — the page needs the endpoint shape locked.
**Cross-mission dependency:** None. Broadcasting is L0 and stands alone.

## WP01 — Backend: monitor read contracts, foundation adapters, BroadcastRouter extension, binding, routes, kernel-boot test

### api (L4) — read contract, DTOs, controller, router

- READ FIRST: M5A's shipped surface — `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + DTOs, `packages/api/src/Controller/AiObservabilityController.php`, `packages/api/src/Http/Router/AiObservabilityApiRouter.php`, and `httpDomainRouters()` in `ApiServiceProvider.php`. Mirror EXACTLY.
- READ existing broadcasting surface: `packages/api/src/Controller/BroadcastStorage.php` (the `_broadcast_log` writer/poller); `packages/foundation/src/Http/Router/BroadcastRouter.php` (SSE shape — `event:`/`data:`/`\n\n`, keepalive every 15s, `connection_aborted()`); `packages/foundation/src/Kernel/EventListenerRegistrar.php` (how listeners push to broadcasting).
- READ `docs/specs/broadcasting.md` for the canonical `_broadcast_log` schema + retention behaviour.
- `packages/api/src/MercureMonitor/ChannelInspectorInterface.php` — `@api`. `listChannels(): array<ChannelInspectorRow>`.
- `packages/api/src/MercureMonitor/EventStreamReadModelInterface.php` — `@api`. `recentEvents(EventStreamFilter $filter, int $limit = 100): array<BroadcastEventRow>`.
- `packages/api/src/MercureMonitor/SubscriberObserverInterface.php` — `@api`. `currentSubscribers(): array<SubscriberRow>`.
- DTOs: `ChannelInspectorRow`, `EventStreamFilter`, `BroadcastEventRow`, `SubscriberRow`. All readonly. All `@api`.
- `packages/api/src/Controller/MercureMonitorController.php` — constructor `(?ChannelInspectorInterface $inspector = null, ?EventStreamReadModelInterface $stream = null, ?SubscriberObserverInterface $observer = null)`. `channels(): array` (empty-shape when null), `events(Request $request): StreamedResponse` (uses Symfony `StreamedResponse`; sets `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`, `X-Accel-Buffering: no`; on null stream dep emits `event: disabled\ndata: {}\n\n` and closes), `subscribers(): array` (empty-shape when null). camelCase keys.
- `packages/api/src/Http/Router/MercureMonitorApiRouter.php` — `supports()` matches `_controller` in `{mercure.monitor.channels, mercure.monitor.events, mercure.monitor.subscribers}`; dispatch on `_controller` to controller methods.
- `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — extend with `resolveOptional` for the three interfaces; register the monitor router when any binds.

### foundation (L0) — adapters + BroadcastRouter extension + SP binding

- READ FIRST: `packages/foundation/src/Http/Router/BroadcastRouter.php` (the existing SSE primitive); `packages/api/src/Controller/BroadcastStorage.php` (the `_broadcast_log` table writer); the existing service provider in `packages/foundation/src/`.
- `packages/foundation/src/Http/Inbound/ChannelInspector.php` — implements api channel-inspector interface. SQL via `DatabaseInterface::select()`: `SELECT channel, COUNT(*) AS event_count_24h, MAX(created_at) AS last_event_at, MAX(event) AS last_event_name FROM _broadcast_log WHERE created_at >= ? GROUP BY channel ORDER BY last_event_at DESC` (parameter is `microtime(true) - 86400`).
- `packages/foundation/src/Http/Inbound/EventStreamReadModel.php` — implements api event-stream interface. `select` from `_broadcast_log` with filter conditions; JSON-decode `data` per row (malformed → `[]`); enforce `limit ≤ 1000`; ORDER BY `id DESC`.
- `packages/foundation/src/Http/Inbound/SubscriberObserver.php` — implements api subscriber-observer interface. `currentSubscribers()` reads `<storage>/broadcast/subscribers.json` (path resolved from foundation config; default `./storage/broadcast/subscribers.json`); filters entries where `lastHeartbeat >= now - 30`; returns `SubscriberRow[]`. Malformed JSON file → empty array (NEVER 500).
- `packages/foundation/src/Http/Router/BroadcastRouter.php` — extend the existing connect-loop additively:
  - On connect: read current subscribers.json, append `{accountId, accountLabel, channels, connectedSince, lastHeartbeat}`, write via atomic temp-then-rename.
  - On each poll iteration: update `lastHeartbeat = microtime(true)` for this connection's entry.
  - On shutdown (`register_shutdown_function`): remove this connection's entry.
  - All writes are atomic; reads tolerate missing/malformed file.
  - Connection identity = SHA-256(connectedSince + accountId + getmypid()) — never the raw session token.
- The foundation service provider — bind all three api interfaces → their adapters. Respect `broadcasting.monitor.enabled` (default true). If the existing SP doesn't expose a hook for the monitor bindings, add a new `MercureMonitorServiceProvider` sibling and register it in the foundation manifest.

### foundation (L0) — routes

- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes after the M5A observability block:
  - `api.mercure.monitor.channels` → `GET /api/mercure/channels`, `_role: admin`, controller string FQCN `'Waaseyaa\\Api\\Controller\\MercureMonitorController'`, action `channels`.
  - `api.mercure.monitor.events` → `GET /api/mercure/events`, `_role: admin`, action `events`. Query params: `channels`, `event`, `since`.
  - `api.mercure.monitor.subscribers` → `GET /api/mercure/subscribers`, `_role: admin`, action `subscribers`.

### root integration (FR-008 — dead-code guard)

- `tests/Integration/PhaseMercureMonitor/MercureMonitorEndpointTest.php` — boot full kernel; seed ≥10 `_broadcast_log` rows across ≥3 channels and ≥3 event names; simulate one SSE subscriber by directly writing to subscribers.json (matches what `BroadcastRouter` would write); hit `GET /api/mercure/channels` as admin → assert grouped counts; hit `GET /api/mercure/events?channels=admin` → assert SSE frames match filter and content-type/X-Accel headers are correct; hit `GET /api/mercure/subscribers` → assert at least one row; non-admin → 403 on all three. Assert response bodies contain no `Authorization` / `Cookie` / `User-Agent` substring. `#[CoversNothing]`. **MUST fail if any of the three SP bindings is removed** — verify by hand.

## WP02 — Frontend: composable, monitor page, components, nav, i18n, docs

- READ FIRST: `packages/admin/app/composables/useAiObservability.ts` (M5A pattern); `packages/admin/app/pages/ai/observability.vue` (M5A page + nav-group registration); any existing EventSource usage in the admin SPA (Nuxt's `import.meta.client` gate is essential — EventSource is browser-only).
- `app/composables/useMercureMonitor.ts` — combined composable. `useApi` for `channels` + `subscribers`. Native `EventSource` for `events` (constructed only when `import.meta.client` is true). State: `{channels, events, subscribers, filter, paused, loading, error}`. Methods: `refreshChannels()`, `refreshSubscribers()`, `setFilter(partial)`, `startStream()` (creates new EventSource, attaches handlers for `event: <name>` frames), `stopStream()` (closes EventSource), `togglePause()` (when paused, drop incoming frames). Event ring buffer capped at 500 to bound memory.
- `app/pages/mercure/monitor.vue` — three-panel layout: filter bar (top), channel inspector + subscriber list (side), live event stream (centre). Pause/Resume button.
- `app/components/mercure/ChannelInspectorPanel.vue` — table; emits `channel-click` event for filter quick-add.
- `app/components/mercure/EventStreamPanel.vue` — virtualised log view; each row `[time] channel/event {data-preview}`; click row expands full JSON via `<details>`.
- `app/components/mercure/SubscriberListPanel.vue` — table.
- `app/components/mercure/MercureFilterBar.vue` — channel multi-select, event-name input, since picker.
- Nav: register a new "Broadcasting" group with one entry "Broadcast monitor" → `/mercure/monitor`. Mirror M5A's "AI" group exactly.
- `app/i18n/en.json` — all `mercure_monitor_*` keys from spec.
- `tests/unit/composables/useMercureMonitor.test.ts` — vitest with `EventSource` mock; assert channels + subscribers fetch; assert stream start opens EventSource; assert `setFilter` triggers refresh; assert paused state drops frames.
- `e2e/mercure-monitor.spec.ts` — Playwright smoke (run deferred): visit page, assert panels render, assert pause/resume toggles state.
- `docs/specs/broadcasting.md` — stamp + "Admin monitor surface" section describing the three endpoints, the subscribers.json mechanism, and the single-host limitation (C-005).
- `docs/specs/admin-spa.md` — stamp + "Broadcast monitor" section.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: Mercure broadcast monitor — live SSE debugger, channel inspector, subscriber list. (#1415)`

## Verification gate (each WP, in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/foundation/tests/ tests/Integration/PhaseMercureMonitor/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `rg -n 'Waaseyaa\\\\Foundation\\\\Http\\\\Inbound' packages/api/src` returns **nothing** (C-002).
6. WP02 only: `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`.

## Reviewer focus

- **Dead-code guard.** Confirm FR-008 integration test fails when any of the three foundation SP bindings is removed.
- **Subscriber identity leak.** Grep the integration test JSON response for `Authorization`, `Cookie`, `User-Agent`. Confirm zero matches.
- **SSE shape parity.** The monitor's `/api/mercure/events` MUST emit the same frame shape as `BroadcastRouter` (no new format). Reviewer reads `BroadcastRouter` first and confirms parity.
- **SSE headers.** Response MUST include `X-Accel-Buffering: no` + `Cache-Control: no-cache`. Without these, production proxies buffer the stream and the page renders blank.
- **Atomic writes.** subscribers.json writes MUST use temp-then-rename. Reviewer greps `SubscriberObserver` and `BroadcastRouter` for `rename(` after `file_put_contents(` on a temp path.
- **Empty-shape parity.** Controller returns zeroed empty payloads when deps null; SSE stream emits `disabled` event and closes. Mirror M5A's `AiObservabilityController`.
- **camelCase JSON.** All response keys camelCase to match TS types.
