# Mercure Broadcast Monitor — Live SSE Debugger + Channel Inspector (M5 Phase 2, sub-mission D)

**Mission:** `mercure-broadcast-monitor-m5d-01KSEFTD`
**Umbrella issue:** #1415 (M5 — admin observability cluster)
**Audit row:** C-L0-04 (broadcasting / Mercure visibility)
**Mission type:** software-dev
**Pattern reference:** **M5A** (`ai-observability-dashboard-01KSE9BX`) — cross-layer L0→L4 via CodifiedContext (same pattern even though L0 is downward; the read contract still lives in api for boundary discipline). M4B `QueueController`/`QueueAdminApiRouter`. M4A-5 `WorkflowGuardsApiRouter`.

## Why

The broadcasting subsystem (`packages/foundation/src/Http/Router/BroadcastRouter.php` + `packages/api/src/Controller/BroadcastStorage.php`) ships SSE events to the admin SPA and any other browser-side consumer over `GET /broadcast?channels=...`. It writes to the `_broadcast_log` table (`id`, `channel`, `event`, `data`, `created_at`), polls every 500ms, emits `event: <name>\ndata: <json>\n\n` frames, and prunes the log via `BroadcastStorageScheduleEntries`. Today the surface is observable only by attaching a browser EventSource and squinting at network panels — there is no admin tool for inspecting which channels are active, which events are flowing, who is subscribed, or for live-tailing a single channel during incident response.

The data is already on disk: the `_broadcast_log` row schema is documented; channel names are listener-chosen; subscriber identity is observable via the SSE connection's authenticated `_account` request attribute (set by `SessionMiddleware` before the broadcast endpoint runs). M5D adds a **monitor surface** that exposes three reads (channel list with recent activity counts, recent + live event stream, subscriber list) and a single live-debug page that combines them into an operator-friendly SSE inspector.

Per CLAUDE.md "Architecture Boundaries" + the broadcasting spec: the broadcast surface is L0 (foundation owns the router) but data + admin metadata live cross-cutting. M5D defines its read contract in api (L4) following the same CodifiedContext pattern M5A established, even though the adapter lives *downward* in `packages/foundation` (or, for the subscriber observer, in api itself if simpler). The discipline matters: any future replacement of `_broadcast_log` (Redis stream, vendor SSE service) only needs a new adapter, not a controller rewrite.

Per DIR-004 (OCAP-by-architecture invariant) and DIR-006 (codified policy gates as trust substrate), the admin SSE stream MUST NOT leak event payloads that the requesting admin's policy forbids. The monitor's SSE response is **read-only mirroring** of `_broadcast_log` — no new write surface. Subscriber identity is exposed as account id (NEVER the session token).

## The cross-layer constraint (read before designing)

`packages/foundation` is **Layer 0**. `packages/api` is **Layer 4**. The layer rule allows api to import foundation (downward), but the **CodifiedContext discipline** keeps the read contract in api anyway, so that:

1. **api defines the read contract** — interfaces + DTOs live in `packages/api/src/MercureMonitor/`, using only api-local value types.
2. **Controllers depend on the api-local interfaces**, nullable, and return empty payloads when null.
3. **Adapters implementing the interfaces live in `packages/foundation/src/Http/Inbound/`** (an existing namespace per CLAUDE.md orchestration table) — they read `_broadcast_log` directly via `DatabaseInterface` and observe live SSE connections via the existing `BroadcastStorage` poller.
4. **`foundation`'s service provider binds the new interfaces → adapters.** This binding is the single thing that prevents the dead-code-in-production failure (FR-008 guard test).
5. **`ApiServiceProvider::httpDomainRouters()` resolves the interfaces via `resolveOptional` and wires the router.** Mirrors M5A's `AiObservabilityApiRouter` registration block.
6. **`waaseyaa/foundation` is already in api's `require`** (L4 → L0 is downward and runtime) — no composer change needed for the cross-layer plumbing.
7. **The live SSE endpoint reuses `BroadcastRouter`'s streaming pattern** — emit `event: <name>\ndata: <json>\n\n` frames, poll every 500ms, send `: keepalive\n\n` every 15s, terminate on `connection_aborted()`. Per the broadcasting spec, this is the established framework primitive; M5D does NOT introduce a new SSE implementation.

## Scope

### In scope

**api (L4) — read contract + DTOs + controller + router:**
- `packages/api/src/MercureMonitor/ChannelInspectorInterface.php` — `listChannels(): array<ChannelInspectorRow>`. `@api`.
- `packages/api/src/MercureMonitor/EventStreamReadModelInterface.php` — `recentEvents(EventStreamFilter $filter, int $limit = 100): array<BroadcastEventRow>`. `@api`.
- `packages/api/src/MercureMonitor/SubscriberObserverInterface.php` — `currentSubscribers(): array<SubscriberRow>`. `@api`.
- `packages/api/src/MercureMonitor/ChannelInspectorRow.php` — readonly: `channel`, `eventCount24h`, `lastEventAt?`, `lastEventName?`. `@api`.
- `packages/api/src/MercureMonitor/EventStreamFilter.php` — readonly: `channels?: array<string>` (null = all), `eventName?: string`, `since?: \DateTimeImmutable`. `@api`.
- `packages/api/src/MercureMonitor/BroadcastEventRow.php` — readonly: `id: int`, `channel`, `event`, `data: array<string, mixed>`, `createdAt`. `@api`.
- `packages/api/src/MercureMonitor/SubscriberRow.php` — readonly: `accountId: int|string`, `accountLabel?`, `channels: array<string>`, `connectedSince`. `@api`. Account id is the framework's `AccountInterface::id()` value; NEVER includes session token or bearer secret.
- `packages/api/src/Controller/MercureMonitorController.php` — three actions: `channels(): array` (channel list), `events(Request $request): StreamedResponse` (SSE stream of recent + live events), `subscribers(): array` (subscriber list). Constructor `(?ChannelInspectorInterface $inspector = null, ?EventStreamReadModelInterface $stream = null, ?SubscriberObserverInterface $observer = null)`. Returns empty-shape payloads for the JSON endpoints when deps are null; for `events()` returns a stream that immediately closes with a `disabled` event when stream dep is null.
- `packages/api/src/Http/Router/MercureMonitorApiRouter.php` — three actions: `mercure.monitor.channels`, `mercure.monitor.events`, `mercure.monitor.subscribers`. Mirrors M5A's `AiObservabilityApiRouter`.
- `packages/api/src/ApiServiceProvider.php` — `httpDomainRouters()` resolves the three interfaces via `resolveOptional` and wires the router.

**foundation (L0) — adapters + binding:**
- `packages/foundation/src/Http/Inbound/ChannelInspector.php` — `implements Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface`. Reads `_broadcast_log` via `DatabaseInterface::select()`: GROUP BY channel, count rows where `created_at >= (now - 86400)`, take last event timestamp + name per channel. Returns `ChannelInspectorRow[]`.
- `packages/foundation/src/Http/Inbound/EventStreamReadModel.php` — `implements Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface`. Reads `_broadcast_log` rows matching filter, JSON-decodes `data` column (malformed → skip with empty `data: []`), enforces `limit ≤ 1000`, orders `id DESC`.
- `packages/foundation/src/Http/Inbound/SubscriberObserver.php` — `implements Waaseyaa\Api\MercureMonitor\SubscriberObserverInterface`. Tracks current subscribers via a process-shared file `<storage>/broadcast/subscribers.json` written by `BroadcastRouter` on connect/disconnect (NEW: extend `BroadcastRouter` to log connect + cleanup on disconnect). `currentSubscribers()` reads the file and returns active rows (last heartbeat within 30s).
- `packages/foundation/src/Http/Router/BroadcastRouter.php` — extend (additive, no breaking change): on connect, append `{accountId, accountLabel, channels, connectedSince}` to subscribers.json; on each poll, refresh `lastHeartbeat`; on disconnect (via `register_shutdown_function`), remove the entry. Atomic file writes via write-to-temp-then-rename per CLAUDE.md.
- `packages/foundation/src/<existing ServiceProvider>` — bind all three api interfaces → their adapters. Existing foundation SP if available; otherwise the BroadcasterServiceProvider scaffolded for the broadcasting subsystem.

**foundation (L0) — routes:**
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — three routes after the M5A observability block, using **string FQCN** controller references:
  - `api.mercure.monitor.channels` → `GET /api/mercure/channels`, `_role: admin`, controller `'Waaseyaa\\Api\\Controller\\MercureMonitorController'`, action `channels`.
  - `api.mercure.monitor.events` → `GET /api/mercure/events`, `_role: admin`, action `events`. Returns SSE stream. Query params: `channels` (comma-separated), `event` (single name), `since` (ISO 8601).
  - `api.mercure.monitor.subscribers` → `GET /api/mercure/subscribers`, `_role: admin`, action `subscribers`.

**admin SPA (L6) — monitor page, composable, components, nav, i18n, docs:**
- `packages/admin/app/composables/useMercureMonitor.ts` — combined composable: `{channels, events, subscribers, filter, loading, error, refreshChannels(), refreshSubscribers(), setFilter(), startStream(), stopStream()}`. Uses `useApi` for the JSON endpoints and a native `EventSource` for the SSE stream (gated on `import.meta.client` per Nuxt SSR rules).
- `packages/admin/app/pages/mercure/monitor.vue` — single-page live monitor: filter bar (channel multi-select, event-name input, since picker), channel inspector panel (top), live event stream (centre — scrollable, pinned latest), subscriber list (side). Pause/Resume button on the stream.
- `packages/admin/app/components/mercure/ChannelInspectorPanel.vue` — table: channel name, 24h count, last event name + timestamp.
- `packages/admin/app/components/mercure/EventStreamPanel.vue` — virtualised log view; each row shows `[time] channel/event { data preview }`; click expands full JSON.
- `packages/admin/app/components/mercure/SubscriberListPanel.vue` — table: accountLabel (or `Anonymous (#0)` for the AnonymousUser sentinel), channels, connected since.
- `packages/admin/app/components/mercure/MercureFilterBar.vue` — channel multi-select, event-name input, since date-time picker.
- Nav: add a new "Broadcasting" group with one entry — `/mercure/monitor` ("Broadcast monitor"). Mirror M5A's "AI" group registration.
- `packages/admin/app/i18n/en.json` — keys: `mercure_monitor_title`, `mercure_monitor_channels_title`, `mercure_monitor_channels_empty`, `mercure_monitor_col_channel`, `mercure_monitor_col_event_count`, `mercure_monitor_col_last_event`, `mercure_monitor_col_last_event_at`, `mercure_monitor_stream_title`, `mercure_monitor_stream_paused`, `mercure_monitor_stream_resume`, `mercure_monitor_stream_pause`, `mercure_monitor_subscribers_title`, `mercure_monitor_subscribers_empty`, `mercure_monitor_col_subscriber`, `mercure_monitor_col_subscriber_channels`, `mercure_monitor_col_subscriber_since`, `mercure_monitor_filter_channels`, `mercure_monitor_filter_event`, `mercure_monitor_filter_since`.
- `packages/admin/tests/unit/composables/useMercureMonitor.test.ts` — vitest: channels + subscribers fetch success/failure; EventSource mock for stream start/stop; filter updates trigger refresh.
- `packages/admin/e2e/mercure-monitor.spec.ts` — Playwright smoke (run deferred).

**Docs:**
- `docs/specs/broadcasting.md` — stamp `<!-- Spec reviewed 2026-05-25 - mercure-broadcast-monitor-m5d-01KSEFTD -->` + "Admin monitor surface" section describing the three endpoints + subscriber tracking extension to `BroadcastRouter`.
- `docs/specs/admin-spa.md` — stamp + "Broadcast monitor" section.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: Mercure broadcast monitor — live SSE debugger, channel inspector, subscriber list. (#1415)`

### Out of scope (→ later sub-missions)

- Publishing new broadcast events from the admin UI (the monitor is read-only; existing publishers ship via `BroadcastStorage::push()`).
- Channel ACL editing (channels are listener-chosen strings; no ACL surface in the framework).
- Persistent event-replay UI (the `_broadcast_log` is pruned per retention; replay of events older than the prune window is out of scope).
- Cross-host subscriber observation (the file-based subscribers.json is per-host; multi-host fleets need a follow-up using Redis or similar).
- AI observability runs / MCP admin / AI aggregations → M5B, M5C, M5A.

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `ChannelInspectorInterface`, `EventStreamReadModelInterface`, `SubscriberObserverInterface` (in `packages/api/src/MercureMonitor/`) declare their methods using only api-local DTOs. No reference to `Waaseyaa\Foundation\*` types in any api interface or DTO. |
| FR-002 | Mandatory | `ChannelInspector` (in `packages/foundation/src/Http/Inbound/`) implements the api interface; reads `_broadcast_log` via `DatabaseInterface::select()`; GROUP BY channel; returns 24h counts + last event metadata per channel. Empty table → empty array, never 500. |
| FR-003 | Mandatory | `EventStreamReadModel` reads `_broadcast_log` rows matching `EventStreamFilter` (channels, event, since); JSON-decodes `data` (malformed → empty `data: []`, never fatal); enforces `limit ≤ 1000`; orders `id DESC`. |
| FR-004 | Mandatory | `SubscriberObserver` reads `<storage>/broadcast/subscribers.json` (process-shared) and returns active rows (last heartbeat within 30s). `BroadcastRouter` is extended to write connect/heartbeat/disconnect events to that file using atomic write-to-temp-then-rename. Subscriber rows expose `accountId` + optional `accountLabel`; NEVER session tokens. |
| FR-005 | Mandatory | foundation's service provider binds all three api interfaces → their adapters. Respects a `broadcasting.monitor.enabled` config flag (default true; skip the bindings when disabled). |
| FR-006 | Mandatory | `MercureMonitorController` returns `{data: ...}` envelopes with **camelCase** keys for `channels` + `subscribers`; returns an SSE `StreamedResponse` for `events` matching the established `Content-Type: text/event-stream` shape (mirror `BroadcastRouter` exactly: `event:`/`data:`/`\n\n` frames, `: keepalive\n\n` every 15s, terminate on `connection_aborted()`). Returns zeroed empty-shape JSON when deps are null; stream immediately closes with a `disabled` event when the stream dep is null. Controller does NOT re-check role; routing enforces `_role: admin`. |
| FR-007 | Mandatory | `GET /api/mercure/channels`, `GET /api/mercure/events` (SSE), `GET /api/mercure/subscribers` are registered in `BuiltinRouteRegistrar` with string FQCN controller references and `_role: admin`. All three reject non-admin with 403 — controller-side delegation is not allowed (DIR-004, DIR-006). |
| FR-008 | Mandatory | A **kernel-boot integration test** boots the full kernel, seeds ≥10 `_broadcast_log` rows across ≥3 channels and ≥3 event names, hits `GET /api/mercure/channels` as admin → asserts grouped row counts; hits `GET /api/mercure/events?channels=admin` → asserts SSE frames with seeded events match filter; hits `GET /api/mercure/subscribers` after a simulated connect → asserts at least one row. Non-admin on all three → 403. (Dead-code-in-production guard — must FAIL if any of the three SP bindings is removed.) |
| FR-009 | Mandatory | `/mercure/monitor` admin page renders: filter bar, channel inspector panel, live event stream, subscriber list. Pause/Resume on the stream works. Composable covered by vitest with EventSource mock; Playwright smoke present (run deferred). One nav entry "Broadcast monitor" registered under a new "Broadcasting" group, mirroring the M5A "AI" group mechanism. |
| FR-010 | Mandatory | `docs/specs/broadcasting.md` + `docs/specs/admin-spa.md` stamped. `CHANGELOG.md` `[Unreleased]` updated. |
| NFR-001 | Mandatory | Cross-layer wiring mirrors the CodifiedContext three-tier pattern shipped by M5A: read contracts + DTOs in api; adapters in foundation; `bin/check-package-layers` stays green. No new runtime composer edges (foundation already required by api). |
| NFR-002 | Mandatory | Controller / router / composable shapes mirror M5A + M4B. camelCase JSON aligned to TS types. SSE frame shape exactly mirrors `BroadcastRouter`'s existing format. |
| NFR-003 | Mandatory | Subscriber file writes use atomic write-to-temp-then-rename. JSON encode/decode pair uses `JSON_THROW_ON_ERROR` per CLAUDE.md JSON symmetry rule. Malformed subscriber file → empty subscriber list, never 500. |
| NFR-004 | Mandatory | SSE event stream MUST emit `: keepalive\n\n` every 15s to keep proxies/load balancers from buffering, per the existing `BroadcastRouter` precedent. |
| C-001 | Constraint | Read-only. The monitor only reads `_broadcast_log` + subscribers.json. No new write/publish surface. |
| C-002 | Constraint | No upward import: api source must never `use` a `Waaseyaa\Foundation\Http\Inbound\*` adapter symbol (api may freely use foundation primitives via the L4→L0 downward edge, but admin adapters are an implementation detail behind the api interface). |
| C-003 | Constraint | No new entity types. No new database tables. The adapters read only `_broadcast_log` (existing) + a process-shared JSON file. |
| C-004 | Constraint | Subscriber identity exposes only `accountId` + optional `accountLabel`. NEVER session tokens, bearer secrets, IP addresses, or User-Agent strings. Reviewer MUST grep responses for the standard `Authorization` / `Cookie` / `User-Agent` substrings to confirm. |
| C-005 | Constraint | The monitor is single-host. Multi-host subscriber aggregation is a follow-up. Single-host limitation MUST be documented in `docs/specs/broadcasting.md`'s "Admin monitor surface" section. |

## Acceptance

- All FRs met.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Kernel-boot integration test (FR-008) demonstrably fails when any of the three foundation SP bindings is removed — verify by hand and report.
- Subscriber identity leak grep (C-004) returns zero matches for `Authorization`, `Cookie`, `User-Agent`, or any session-token-shaped 64-char hex in the responses.
- SSE stream keepalive observed by hand on a real connection (curl the endpoint with `-N`, watch for `: keepalive` lines every 15s).
- Commit footers `Refs #1415` (umbrella stays open until all four M5 sub-missions land).
- M5 progress comment posted on #1415 at wrap-up.

## Risks

- **Dead code in production (primary).** If any of the three monitor interfaces is wired via `resolveOptional` but no real `singleton` binds the api interface, the monitor silently returns empty in production while fake-backed tests pass. FR-008's kernel-boot test, which seeds real `_broadcast_log` rows + simulated subscribers and asserts non-empty responses, is the mandatory guard. Reviewer MUST grep the foundation SP for all three bindings.
- **Subscriber file race.** Multiple SSE workers may write subscribers.json concurrently. Atomic write-to-temp-then-rename (CLAUDE.md gotcha) + heartbeat-based stale cleanup (drop rows whose heartbeat > 30s) mitigates. Caught in `SubscriberObserverTest` with a concurrent-write fixture.
- **Event payload leak.** `_broadcast_log.data` blobs may carry application data the admin lacks per-record access to. M5D ships a basic admin gate (`_role: admin`) — finer-grained per-event redaction is a follow-up. Document in `docs/specs/broadcasting.md`.
- **Session-token leak.** Highest-severity sister risk to M5C's plaintext-token concern: subscriber rows MUST never include session tokens, IP addresses, or User-Agent. C-004 + reviewer grep guards this.
- **Layer violation.** Any `use Waaseyaa\Foundation\Http\Inbound\*` adapter import in `packages/api/src/**` fails C-002. The api interfaces are the only contract.
- **SSE proxy buffering.** Production proxies (nginx, ALB) may buffer SSE without explicit headers. The controller MUST set `X-Accel-Buffering: no` + `Cache-Control: no-cache` (`BroadcastRouter` precedent). Caught in the integration test via header assertions.
- **getQuery / unbound chains.** The adapters use `DatabaseInterface::select()` (query builder) — no new getquery-baseline entries expected.

## Decisions pre-resolved

- Two WPs, sequential (frontend depends on backend contract).
- Backend WP01 writes the kernel-boot integration test that fails if any SP binding is missing (M5A FR-007 pattern, expanded for three bindings).
- Authoritative surface: the JSON + SSE contract under `packages/api/src/MercureMonitor/`.
- Subscriber observation uses a process-shared JSON file (simplest cross-worker mechanism for single-host); multi-host is a documented follow-up.
- camelCase JSON across all monitor payloads (matches M5A as-shipped).
- SSE frame shape exactly mirrors `BroadcastRouter` (no new SSE format).
- Subscriber identity = `accountId` + optional `accountLabel`. NEVER tokens / IPs / UA.
- Implementer preference order: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Decisions deferred to implementer

- Exact CSS for the event-stream virtualised log (must respect existing AdminShell tokens; brand-teal palette).
- Whether the channel multi-select is a chip-list or a dropdown — pick whichever matches existing admin filter patterns.
- The exact heartbeat interval (proposed: 5s) and stale-cutoff (proposed: 30s) — implementer may tune within 1s..10s heartbeat / 15s..60s cutoff to balance file-write churn vs detection latency.

## Out-of-band

- No cross-mission dependencies — Mercure / broadcasting is L0 and has no per-record-AI-access prerequisite.
- Pattern reference (read every file ONCE before writing): `/home/fsd42/dev/waaseyaa/kitty-specs/ai-observability-dashboard-01KSE9BX/` (M5A — shipped CodifiedContext cross-layer pattern).
- Strategic context: `/home/fsd42/dev/waaseyaa/docs/specs/codified-context-integration.md` (three-tier inheritance model), `/home/fsd42/dev/waaseyaa/docs/specs/broadcasting.md` (the `_broadcast_log` schema + `BroadcastRouter` streaming pattern), `.kittify/charter/charter.md` DIR-004 (OCAP-by-architecture invariant) + DIR-006 (codified policy gates as trust substrate).
