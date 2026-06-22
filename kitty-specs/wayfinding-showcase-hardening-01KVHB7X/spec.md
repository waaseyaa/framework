# Feature Specification: Wayfinding Showcase Hardening

**Mission:** `wayfinding-showcase-hardening-01KVHB7X` · **Type:** software-dev · **Target:** main · **Created:** 2026-06-20 · **Status:** Active — finish-and-ship; acceptance tests are the release gate for the next alpha.

**Parent / context:** Hardening pass over the flagship `wayfinding-01KVGH5X` (5 phases shipped through alpha.233; P0 reachability fixed by `wayfinding-stress-remediation-01KVGK4Q` in alpha.234). Source backlog: a **single-clean-take dry run of the live showcase** — an on-camera agent brings up the dev server, reads the published anchor catalog, reads the live session token, emits a guided beacon trail across the story admin over the framework's own SSE protocol, saves it as a reusable trail, and shows the public surface stays read-only. The dry run worked end-to-end but surfaced a cluster of robustness defects, one of them a demo-killer.

## Summary

The flagship is functionally complete and the sovereignty story holds. But under a **real, reconnecting browser**, the live half is fragile:

1. **Beacons are ephemeral and not replayed.** `/api/broadcast` is a fire-and-forget cursor stream that starts each connection at "now". During the admin SPA's hydration the SSE connection reconnects repeatedly (worker pressure from multiple per-consumer connections), and a beacon emitted in that window is delivered to a connection that no longer exists — it never renders. In the dry run the first emitted beacon silently vanished and only a re-emit in a settled window showed it. **This is a demo-killer.**
2. **The session token has no supported read path.** alpha.234 surfaced the token inside `useRealtime`/`useBeacons`, but reading it from outside the app still required intercepting the SSE wire and winning the hydration race — there is no supported handle a presenter can call.
3. **The list-level "Create new" control is unanchored**, so a presenter cannot beacon it on the list page (every other action affordance carries a `data-anchor`).
4. **MCP tool-resolution logs error spam** on boot and public `tools/list` for optional tools whose deps are absent (Bimaaji routing-introspection, vector search) — noise on camera, and the parent mission's held **P2-10 / CL-2**.

The throughline: **each mechanism is correct but not robust to reconnection or not reachable by the presenter.** Every fix hardens or exposes an existing mechanism; none redesigns it. No BC shims (C-002 of the parent holds — no deployed downstream apps).

## Actors

- **Guiding agent / presenter (authenticated, write tier)** — emits a live trail and needs (a) a beacon to survive the viewer's reconnects, and (b) a supported way to learn the viewer's session token.
- **End user (human viewer)** — opens the admin SPA on a real browser that hydrates and reconnects; must see a beacon emitted at any time, and a dismissed trail must stay dismissed across reloads.
- **App integrator / operator** — runs `composer run dev`; boot and `POST /mcp tools/list` logs must be clean (no tool-resolution error spam).

## Prioritization

| Pri | Item | One-line | Disposition |
|----|------|----------|-------------|
| **P0-1** | Beacon-reconnect race | Ephemeral beacons are not replayed on (re)connect; one emitted during hydration is lost | **FIX (this mission)** |
| **P0-2** | Session token unreachable by a presenter | Token lives only in Vue state; reading it needs SSE interception + a race | **FIX (this mission)** |
| **P1-3** | Create-new not beaconable | The list-level "Create new" control carries no `data-anchor` | **FIX (this mission)** |
| **Fold-in** | Noisy MCP tool-resolution | Bimaaji/vector tools log `error` when their deps are absent | **FIX (this mission)** — folds parent **P2-10 / CL-2** |

## Root-cause findings (read-only, verified first-hand in the dry run)

### P0-1 — Beacon-reconnect race (the demo-killer)

`BroadcastRouter` starts every new connection at the high-water mark (`resolveInitialCursor` → `maxId`), and `BroadcastStorage` is an append-only `_broadcast_log` polled by `id > cursor`. New connections deliberately receive **no history** (a defensive choice — `SchemaList` even guards against history-replay refetch floods). So a `wayfinding.beacon` pushed *before* a connection exists is never delivered to it.

Two things make this bite in practice:
- The admin client opened **one `EventSource` per consumer** — the persistent `WayfindingOverlay` (via `useBeacons`) **and** each `SchemaList` — and under FrankenPHP each SSE stream pins a worker for its lifetime, so concurrent connections thrashed (the observed ~per-second reconnect churn during hydration, plus a stuck second connection = worker starvation).
- The 30s per-connection lifetime cap reconnects often even at rest.

A beacon emitted during that churn lands between connections and evaporates.

### P0-2 — Session token has no supported read path

The server is correct: the `connected` SSE frame carries `{ channels, sessionToken }` with `sessionToken = substr(sha256(session_id), 0, 32)` (`SessionChannel::tokenForSessionId`). alpha.234 made `useRealtime`/`useBeacons` capture it into a ref. But that ref is internal Vue state; the dry run could only read the token by monkey-patching `EventSource` before hydration and parsing the `connected` frame off the wire — there is no endpoint and no DOM handle.

### P1-3 — "Create new" is unanchored

`SchemaList`/`SchemaView`/`SchemaForm` emit `data-anchor` on containers, fields, and the edit/delete/submit actions, and `AnchorRegistry` mirrors them. But the list-level "Create new" `NuxtLink` (`pages/[entityType]/index.vue`) carries none, and `AnchorRegistry` emits no `create` action — so a beacon cannot target the create affordance from the list view.

### Fold-in — Noisy MCP tool-resolution

`AttributeToolRegistry::hydrate()` logs at **error** for any tool whose container resolution throws. Two optional families throw when their deps are absent in a given kernel: the Bimaaji routing-introspection tools (their generator needs a `RouteCollection`/`WaaseyaaRouter` that isn't bound in a non-HTTP/MCP-only kernel) and `VectorSearchTool` (unbindable `Closure` constructor params with no embedding provider configured). The tools are correctly *skipped* (they stay out of the set), but the error spam clutters boot and public `tools/list` logs.

## The P0-1 design decision (retained messages, not history replay)

Two ways to make a beacon survive a reconnect:

- **(A) Replay the log on connect — REJECTED.** Re-delivering `_broadcast_log` history to new connections would resurrect superseded events, re-fire `entity.saved`/`entity.deleted` refetch storms (the exact thing `SchemaList` defends against), and has no expiry/supersede/dismiss semantics. It also flips the documented "no replay" contract for *all* events.
- **(B) Retained messages — CHOSEN.** Add an MQTT-style **retained** message: the still-active *state* for a `(channel, retain_key)` pair, last-write-wins, with a TTL, re-sent to every new subscriber on connect — orthogonal to the history log. Only messages explicitly pushed as retained replay; everything else keeps the fire-and-forget contract. Beacons are retained per anchor; `entity.*` events are not, so the refetch-flood defense is untouched.

`BroadcastStorage` gains `pushRetained` / `retainedFor` / `dropRetained` / `pruneRetained` over a new `_broadcast_retained` table. `BroadcastRouter` replays `retainedFor($channels)` right after the `connected` frame. Replay frames carry the original broadcast id **in the JSON envelope** (clients de-dupe by it) but emit **no SSE `id:` line** (they must not rewind `Last-Event-ID`).

To remove the churn that amplified the race, the admin client shares **one** `EventSource` per channel set (ref-counted teardown) and the per-connection lifetime cap is lengthened 30s → 300s. Replay makes any reconnect lossless, so a longer-lived connection is safe; the 2s keepalive write remains the prompt disconnect probe.

## Requirements

### Functional (FR)

| ID | Requirement | Pri |
|----|-------------|-----|
| FR-001 | A beacon emitted into a session MUST be re-delivered to that session on every subsequent (re)connect until it is superseded, dropped, or its TTL expires — so a beacon emitted during hydration, or before a reload, still renders. | P0 |
| FR-002 | Retained replay MUST NOT resurrect history for non-retained events (`entity.saved`/`entity.deleted` keep the fire-and-forget contract; no refetch-flood regression). | P0 |
| FR-003 | Replayed beacons MUST carry the original broadcast id so a client that already ingested the live push de-dupes the replay, and MUST NOT rewind the connection's `Last-Event-ID` cursor. | P0 |
| FR-004 | A viewer dismissing the live trail MUST clear its OWN session's retained beacons so a dismissed trail does not replay on the next reconnect/reload (own-session scoped; no presenter capability required). | P0 |
| FR-005 | The admin client MUST open at most ONE SSE connection per channel set regardless of how many consumers (overlay + each list) ask for it. | P0 |
| FR-006 | A fresh page MUST expose the caller's own non-secret session token via a supported path — `GET /api/wayfinding/session` (returns `{ sessionToken, channel }`) AND `data-wf-session` on the document root — with no SSE interception. The value MUST equal the `connected` frame's token. | P0 |
| FR-007 | The published anchor catalog MUST include `action:{entityType}:create`, and the list view's "Create new" control MUST carry the matching `data-anchor`; a beacon anchored to it MUST render. | P1 |
| FR-008 | Optional MCP tools whose dependencies are absent in the current kernel MUST be skipped quietly (debug), not logged at error; genuine resolution failures MUST still log at error. The resolved tool set MUST be unchanged. | P1 |

### Non-Functional / Security (NFR)

| ID | Requirement | Pri |
|----|-------------|-----|
| NFR-001 | Retained beacons stay session-scoped: `retainedFor`/replay only ever return the connection's own channel set; one session never replays another's beacons (LD-1). | P0 |
| NFR-002 | `GET /api/wayfinding/session` returns ONLY the caller's own token (derived from the caller's session); it never exposes another session's token, and never the raw session id. | P0 |
| NFR-003 | `DELETE /api/wayfinding/beacons` is own-session scoped and authenticated; a caller can only clear its own session's retained beacons. | P0 |

### Constraints (C)

| ID | Constraint | Status |
|----|------------|--------|
| C-001 | The public read-only `/mcp` surface and the alpha.221 trio MUST NOT change; the four wayfinding write tools stay absent from public `tools/list`. | Accepted |
| C-002 | No BC shims / deprecation layers (no deployed downstream apps) — inherited from the parent. | Accepted |
| C-003 | History replay for non-retained events is explicitly out of scope (see "The P0-1 design decision"); the fix is the orthogonal retained-message mechanism. | Accepted |

## Acceptance (release gate)

- **AC-1 (P0-1 / FR-001..FR-003, NFR-001):** `BroadcastStorageTest` — `pushRetained` delivers live AND is replayable; replay carries the original id; last-write-wins per key; `retainedFor` is channel-scoped + emission-ordered; `dropRetained` clears one key or the whole channel; expired (TTL) retained is not replayed and is pruned.
- **AC-2 (P0-1 / FR-004, FR-005):** admin Vitest — `useRealtime` shares one `EventSource` across consumers on the same channel set and keeps it alive until the last consumer releases; `useBeacons` `dismiss()` issues `DELETE /api/wayfinding/beacons`.
- **AC-3 (P0-2 / FR-006, NFR-002):** `SessionTokenControllerTest` — `GET /api/wayfinding/session` returns the caller's own token, equal to `SessionChannel::tokenForSessionId(sessionId)`, and the matching `session:<token>` channel; null when there is no session.
- **AC-4 (P1-3 / FR-007):** `AnchorRegistryTest` — the catalog includes `action:{entityType}:create` and `isValid` accepts it.
- **AC-5 (Fold-in / FR-008):** `AutowiringToolContainerTest` raises `ToolDependencyUnavailableException` for an unresolvable dependency (unbindable param AND a bound-service factory that throws); `AttributeToolRegistryTest` skips a dependency-unavailable tool at **debug** while a genuine failure stays at **error**, with the tool absent from the set either way.
- **AC-6 (live):** verified in **my-app** under `composer run dev` — a beacon emitted immediately after page load renders without a re-emit and survives a forced reconnect; `GET /api/wayfinding/session` matches the `connected` frame; `action:story:create` is in the catalog and a beacon on it renders; boot + `POST /mcp tools/list` show no `AttributeToolRegistry` error spam.

## Key Entities / touch-points

- **P0-1:** `packages/api/src/Controller/BroadcastStorage.php` (`pushRetained`/`retainedFor`/`dropRetained`/`pruneRetained` + `_broadcast_retained`); `packages/foundation/src/Http/Router/BroadcastRouter.php` (replay after `connected`; `DEFAULT_MAX_DURATION_SEC` 30→300); `packages/wayfinding/src/Http/EmitBeaconController.php` (`pushRetained` + `clear`); `packages/ai-agent/src/Tool/Wayfinding/EmitBeaconTool.php` (`pushRetained`); `packages/admin/app/composables/useRealtime.ts` (shared connection), `useBeacons.ts` (clear-on-dismiss).
- **P0-2:** `packages/wayfinding/src/Http/SessionTokenController.php`; `packages/wayfinding/src/WayfindingServiceProvider.php` (routes); `packages/admin/app/plugins/wayfindingSession.client.ts` (`data-wf-session`).
- **P1-3:** `packages/wayfinding/src/Anchor/AnchorRegistry.php` (`create` action); `packages/admin/app/pages/[entityType]/index.vue` (button `data-anchor`).
- **Fold-in:** `packages/ai-tools/src/ToolDependencyUnavailableException.php`; `packages/ai-tools/src/Catalogue/AutowiringToolContainer.php` (throws typed); `packages/ai-tools/src/Catalogue/AttributeToolRegistry.php` (debug skip).

## Coupled spec updates (drift)

- `docs/specs/broadcasting.md` — new "Retained messages (replay on connect)" section; the "no replay" constraint amended to note the retained exception; the lifetime-cap bump.
- `docs/specs/admin-spa.md` — `useRealtime` shared-connection contract + corrected `BroadcastMessage` shape; `action:{entityType}:create` row in the element-anchor table; supported session-token read path (`GET /api/wayfinding/session` + `data-wf-session`).
- `docs/specs/wayfinding.md` — `create` in the anchor scheme; emit path uses `pushRetained`; new "Phase 6 — Showcase hardening" section.

## Scope

**In:** the four fixes above, their release-gate acceptance tests, the coupled spec/CHANGELOG updates, and live verification in my-app under `composer run dev`.

**Out:** history replay for non-retained events (C-003); presenter-pairing handshake UI (the token read path is in scope, the pairing UX is not); cross-process broadcast transport (unchanged); the parent's other held P1/P2 items.
