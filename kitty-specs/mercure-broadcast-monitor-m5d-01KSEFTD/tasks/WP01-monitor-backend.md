# WP01 — Backend: monitor read contracts, foundation adapters, BroadcastRouter extension, binding, routes, kernel-boot test (M5D)

**Mission:** `mercure-broadcast-monitor-m5d-01KSEFTD` (#1415, audit C-L0-04)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/mercure-broadcast-monitor-m5d-01KSEFTD-lane-a
```
(Exact path is printed by `spec-kitty agent action implement WP01`.) Do NOT edit the main worktree.

## THE pattern to mirror (read these before writing anything)

This mission is a **cross-layer** read surface: `packages/foundation` is **Layer 0**, `packages/api` is **Layer 4**. The layer rule allows api to import foundation (downward), but **CodifiedContext discipline** keeps the read contract in api anyway (so a future Redis/Mercure swap is a one-file adapter change). The shipped pattern is **CodifiedContext** (M5A used it for `AiObservabilityReadModelInterface`):

- READ `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + M5A DTOs — the api-owned read contract + value DTOs.
- READ `packages/api/src/Controller/AiObservabilityController.php` — nullable dependency, returns empty-shape when null.
- READ `packages/ai-observability/src/ReadModel/AiObservabilityReadModel.php` — adapter implementing the api interface.
- READ `packages/api/src/Http/Router/AiObservabilityApiRouter.php` + the `AiObservabilityApiRouter`/`resolveOptional` block in `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — the router + wiring shape.
- READ `packages/foundation/src/Http/Router/BroadcastRouter.php` — the existing SSE shape this mission EXTENDS (additive only): `event:`/`data:`/`\n\n` frames, `: keepalive\n\n` every 15s, `connection_aborted()` exit, polls `BroadcastStorage::poll` every 500ms.
- READ `packages/api/src/Controller/BroadcastStorage.php` — the `_broadcast_log` writer/poller (the data model).
- READ `docs/specs/broadcasting.md` — the canonical `_broadcast_log` schema (`id`, `channel`, `event`, `data`, `created_at`) + retention behaviour via `BroadcastStorageScheduleEntries`.

### Data model (already shipped — you only READ it, plus subscribers.json which you EXTEND)

- `_broadcast_log` table: `id` INTEGER PK AUTOINCREMENT, `channel` TEXT, `event` TEXT, `data` TEXT (JSON), `created_at` REAL (microtime).
- `<storage>/broadcast/subscribers.json` (NEW shared file written by `BroadcastRouter`): `[{accountId, accountLabel?, channels: string[], connectedSince: float, lastHeartbeat: float, connectionId: string}]`.

## Subtasks

**T001 — api read contract (interfaces + DTOs)**
- `packages/api/src/MercureMonitor/ChannelInspectorInterface.php` — `@api`. `listChannels(): array<ChannelInspectorRow>`.
- `packages/api/src/MercureMonitor/EventStreamReadModelInterface.php` — `@api`. `recentEvents(EventStreamFilter $filter, int $limit = 100): array<BroadcastEventRow>`.
- `packages/api/src/MercureMonitor/SubscriberObserverInterface.php` — `@api`. `currentSubscribers(): array<SubscriberRow>`.
- DTOs: `ChannelInspectorRow`, `EventStreamFilter` (with `fromQuery(array $query): self` parsing `channels` CSV, `event`, `since` ISO 8601), `BroadcastEventRow`, `SubscriberRow`. All readonly. All `@api`.

**T002 — api controller + router + SP wiring**
- `packages/api/src/Controller/MercureMonitorController.php` — constructor `(?ChannelInspectorInterface $inspector = null, ?EventStreamReadModelInterface $stream = null, ?SubscriberObserverInterface $observer = null)`. Three actions. camelCase JSON for `channels` + `subscribers`. `events()` returns Symfony `StreamedResponse` with the established SSE headers (`Content-Type: text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`, `X-Accel-Buffering: no`); poll loop mirrors `BroadcastRouter::handle()` exactly (500ms poll, `: keepalive\n\n` every 15s, exit on `connection_aborted()`). On null stream dep: emit one `event: disabled\ndata: {}\n\n` then close.
- `packages/api/src/Http/Router/MercureMonitorApiRouter.php` — `supports()` matches `_controller` in the three names; dispatch on `_controller`.
- `packages/api/src/ApiServiceProvider.php::httpDomainRouters()` — extend with `resolveOptional` for the three interfaces; register the monitor router when any binds.

**T003 — foundation adapters + BroadcastRouter extension + SP binding + foundation routes**
- `packages/foundation/src/Http/Inbound/ChannelInspector.php` — `DatabaseInterface::select()` SQL: GROUP BY channel + COUNT + MAX(created_at) + MAX(event) WHERE created_at >= (now - 86400). Returns rows ordered by `last_event_at DESC`.
- `packages/foundation/src/Http/Inbound/EventStreamReadModel.php` — `select` from `_broadcast_log` with filter conditions (`channels` IN, `event` =, `created_at >= since`); JSON-decode `data` per row (malformed → empty `[]`, never fatal); clamp limit ≤ 1000; ORDER BY `id DESC`.
- `packages/foundation/src/Http/Inbound/SubscriberObserver.php` — read subscribers.json (resolved from config; default `./storage/broadcast/subscribers.json`); JSON_THROW_ON_ERROR; malformed → empty array; filter rows where `lastHeartbeat >= now - 30`.
- `packages/foundation/src/Http/Router/BroadcastRouter.php` — additive extension:
  - On connect: append `{accountId, accountLabel, channels, connectedSince, lastHeartbeat, connectionId}` to subscribers.json (atomic temp-then-rename).
  - On each poll iteration: update `lastHeartbeat` for this entry; rewrite atomically.
  - On shutdown via `register_shutdown_function`: remove this entry; rewrite atomically.
  - `connectionId = substr(hash('sha256', $connectedSince . ':' . $accountId . ':' . getmypid()), 0, 16)` — never raw session token.
- `packages/foundation/src/MercureMonitorServiceProvider.php` — new SP. `register()` binds all three api interfaces → their adapters. Respects `broadcasting.monitor.enabled` (default true; skip bindings when disabled). Add the SP FQCN to foundation's `composer.json` `extra.waaseyaa.providers` array.
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three new routes; all `_role: admin`; string FQCN.

**T004 — tests (unit + FR-008 dead-code guard)**
- `packages/api/tests/Unit/Controller/MercureMonitorControllerTest.php` — anonymous-class fakes for each interface; assert mapped camelCase JSON; null deps → zeroed empty shapes; SSE headers on `events()` correct; null stream dep → single `disabled` frame.
- `packages/api/tests/Unit/Http/Router/MercureMonitorApiRouterTest.php` — `supports()` true/false; dispatch routes to controller methods; unknown action → 404.
- `packages/foundation/tests/Unit/Http/Inbound/ChannelInspectorTest.php` — `DBALDatabase::createSqlite()`, run `_broadcast_log` migration (or schema setup mirroring `BroadcastStorage::ensureTable`), seed rows across ≥3 channels + ≥3 events, assert grouped counts + last event metadata.
- `packages/foundation/tests/Unit/Http/Inbound/EventStreamReadModelTest.php` — seed rows; assert filter combinations; assert malformed-JSON row returns empty `data`, not 500; assert `limit > 1000` clamps to 1000.
- `packages/foundation/tests/Unit/Http/Inbound/SubscriberObserverTest.php` — seed subscribers.json fixtures (active + stale); assert active rows returned; assert stale rows dropped; assert malformed file → empty array; concurrent-write fixture exercises atomic rename.
- `tests/Integration/PhaseMercureMonitor/MercureMonitorEndpointTest.php` (`#[CoversNothing]`) — boot full kernel; seed ≥10 `_broadcast_log` rows across ≥3 channels + ≥3 event names; write a fixture subscribers.json with one active row; hit `GET /api/mercure/channels` as admin → assert grouped counts; hit `GET /api/mercure/events?channels=admin` → assert SSE frames + correct headers (`X-Accel-Buffering`, `Cache-Control`); hit `GET /api/mercure/subscribers` → assert one row; non-admin → 403 on all three; assert response bodies contain zero matches for `Authorization`, `Cookie`, `User-Agent`. **MUST fail if any of the three SP bindings from T003 is removed** — verify by hand.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/api/tests/ packages/foundation/tests/ tests/Integration/PhaseMercureMonitor/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. Confirm: `rg -n 'Waaseyaa\\\\Foundation\\\\Http\\\\Inbound' packages/api/src` returns **nothing** (C-002).
6. Confirm by hand: removing any one of the three `MercureMonitorServiceProvider` bindings causes `MercureMonitorEndpointTest` to fail. Note this in the WP report.
7. Confirm by hand using `curl -N http://localhost:8000/api/mercure/events` (with an admin session cookie): keepalive `: keepalive\n\n` frames arrive every ~15s.

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(api): Mercure monitor read contracts + DTOs + controller + router (#1415)`
  - `feat(foundation): broadcast monitor adapters + BroadcastRouter subscriber tracking (#1415)`
  - `feat(foundation): /api/mercure monitor routes + SP bindings (#1415)`
  - `test(foundation): kernel-boot Mercure monitor integration test + SSE header + identity-leak assertions (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission mercure-broadcast-monitor-m5d-01KSEFTD
  spec-kitty agent tasks move-task WP01 --to for_review --mission mercure-broadcast-monitor-m5d-01KSEFTD --note "M5D backend ready; FR-008 kernel-boot test verified to fail without each of the three SP bindings; no identity leak (Auth/Cookie/UA grep clean); SSE keepalive verified by hand"
  ```

## Report back with

- Confirmation that FR-008 fails when each binding is removed.
- Confirmation that no `Authorization`/`Cookie`/`User-Agent` substrings appear in the integration responses.
- Confirmation that SSE keepalive frames are observed by hand on a real connection.
- Commit SHAs + final gate output.

## Activity Log

(implementer appends here)
