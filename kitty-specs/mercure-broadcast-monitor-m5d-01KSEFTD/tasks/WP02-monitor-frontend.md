# WP02 — Frontend: monitor page + composable, components, nav, i18n, docs (M5D)

**Mission:** `mercure-broadcast-monitor-m5d-01KSEFTD` (#1415, audit C-L0-04)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on WP01** — `GET /api/mercure/channels`, `GET /api/mercure/events` (SSE), `GET /api/mercure/subscribers` and their camelCase + SSE-frame payloads must already be merged/approved.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/mercure-broadcast-monitor-m5d-01KSEFTD-lane-b
```
(Exact path is printed by `spec-kitty agent action implement WP02`.) Lane worktree has no `node_modules` — `cd packages/admin && npm install` first.

## Pattern to mirror (READ FIRST)

- `packages/admin/app/composables/useAiObservability.ts` (M5A's shipped composable)
- `packages/admin/app/pages/ai/observability.vue` (M5A's shipped page + nav-group registration)
- The M5A "AI" nav-group registration — mirror exactly to add a new "Broadcasting" group
- Any existing admin SPA EventSource usage (gate on `import.meta.client` per Nuxt SSR rules)

## Endpoint contract (from WP01 — AS SHIPPED; use these exact field names)

```
GET /api/mercure/channels
→ { data: { rows: [{ channel, eventCount24h, lastEventAt?, lastEventName? }] } }

GET /api/mercure/events?channels=admin,realtime&event=entity.saved&since=2026-05-25T00:00:00Z
→ SSE stream (Content-Type: text/event-stream, X-Accel-Buffering: no)
   event: <name>
   data: { "id": 42, "channel": "admin", "event": "entity.saved", "data": { ... }, "createdAt": 1748... }
   <blank line>
   ... ": keepalive" comments every 15s

GET /api/mercure/subscribers
→ { data: { rows: [{ accountId, accountLabel?, channels: string[], connectedSince }] } }
```

**Security note:** TS types MUST NOT include any session-token / IP / User-Agent fields.

## Subtasks

**T005 — composable + components + page**
- `app/composables/useMercureMonitor.ts` — combined composable. `useApi` for `channels` + `subscribers`. Native `EventSource` for `events` (constructed only inside `if (import.meta.client) { ... }`). State: `{channels: Ref<ChannelRow[]>, events: Ref<BroadcastEventRow[]>, subscribers: Ref<SubscriberRow[]>, filter: Ref<MonitorFilter>, paused: Ref<boolean>, loading, error}`. Event ring buffer capped at 500 (drop oldest when full). Methods: `refreshChannels()`, `refreshSubscribers()`, `setFilter(partial)` (triggers refresh + restart stream with new query), `startStream()`, `stopStream()`, `togglePause()`. On SSR pass, stream is not initialised — only fires on client mount.
- `app/components/mercure/ChannelInspectorPanel.vue` — props `{rows}`. Click on a row emits `select-channel` for filter quick-add.
- `app/components/mercure/EventStreamPanel.vue` — props `{events, paused}`. Virtualised log view (CSS `overflow-y: auto`; auto-scroll to bottom unless user scrolled up). Each row `[time] channel/event <data-preview>`; click toggles `<details>` for full JSON.
- `app/components/mercure/SubscriberListPanel.vue` — props `{rows}`. AnonymousUser (id=0) renders as `Anonymous (#0)`.
- `app/components/mercure/MercureFilterBar.vue` — channel multi-select (populated from the channels response), event-name input, since picker. `@update:filter` emits a partial.
- `app/pages/mercure/monitor.vue` — three-panel layout: filter bar (top), channels + subscribers (side), event stream (centre). Pause/Resume button on the stream panel.

**T006 — nav + i18n + tests**
- Register a new "Broadcasting" nav group with one entry: "Broadcast monitor" → `/mercure/monitor`. Mirror M5A's "AI" group exactly.
- `app/i18n/en.json` — all `mercure_monitor_*` keys listed in spec.
- `tests/unit/composables/useMercureMonitor.test.ts` — vitest with global `EventSource` mock; assert channels + subscribers fetch populates state; assert `startStream` constructs an EventSource and registers message handler; assert `setFilter` refreshes both JSON endpoints + restarts stream with new query params; assert paused state drops incoming events (ring buffer unchanged); assert event-buffer cap is enforced.
- `e2e/mercure-monitor.spec.ts` — Playwright smoke (run deferred): visit `/mercure/monitor`, assert all four panels render, assert Pause/Resume button toggles label.

**T007 — docs**
- `docs/specs/broadcasting.md` — add `<!-- Spec reviewed 2026-05-25 - mercure-broadcast-monitor-m5d-01KSEFTD: admin monitor surface (channels, events SSE, subscribers) + BroadcastRouter subscriber tracking via subscribers.json -->` near the top + an "Admin monitor surface" section describing the three endpoints, the subscribers.json mechanism, atomic-write discipline, and the **single-host limitation** (C-005).
- `docs/specs/admin-spa.md` — stamp + "Broadcast monitor" subsection describing the page.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: Mercure broadcast monitor — live SSE debugger, channel inspector, subscriber list. (#1415)`

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
2. `npm run build` (Nuxt SSR compile check — confirm the EventSource is properly gated; no `EventSource is not defined` server error).
3. `cd /home/jones/dev/waaseyaa/.worktrees/... && composer cs-check && composer phpstan` (docs touch).
4. Confirm by hand: visiting `/mercure/monitor` shows the new "Broadcasting" nav group + the page renders all four panels. Trigger a broadcast via `BroadcastStorage::push()` and see it arrive in the stream within ~1s.

## Commit + handoff

- Commits (footer `Refs #1415` on each):
  - `feat(admin): Mercure monitor page + composable with EventSource (#1415)`
  - `feat(admin): broadcast monitor panels (channels, events, subscribers, filter) (#1415)`
  - `feat(admin): Broadcasting nav group + i18n keys (#1415)`
  - `docs(broadcasting,admin-spa): stamp Mercure monitor surface + single-host caveat (#1415)`
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission mercure-broadcast-monitor-m5d-01KSEFTD
  spec-kitty agent tasks move-task WP02 --to for_review --mission mercure-broadcast-monitor-m5d-01KSEFTD --note "M5D frontend ready; vitest green; SSR build clean (EventSource gated); e2e deferred"
  ```

## Report back with

- vitest + typecheck + lint + npm run build output.
- Confirmation the page renders all four panels against WP01.
- Confirmation that a manually-triggered broadcast arrives in the live stream.

## Activity Log

(implementer appends here)
