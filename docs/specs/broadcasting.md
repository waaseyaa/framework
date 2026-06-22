# Broadcasting (Server-Sent Events)

This spec documents Waaseyaa's production broadcasting subsystem: how domain events
fan out to long-lived SSE consumers (admin SPA, ops dashboards) without coupling
the request handler to subscriber bookkeeping.

The subsystem is intentionally narrow: in-process publishers push rows into a
durable SQL log; a single SSE endpoint polls the log on behalf of each
connected client. There is no in-memory pub/sub fan-out and no out-of-process
transport. Cross-process or cross-server delivery is out of scope.

## Pieces

| Component | Path | Responsibility |
|---|---|---|
| `BroadcastStorage` | `packages/api/src/Controller/BroadcastStorage.php` | Durable message log backed by SQLite/MySQL via DBAL. Owns the `_broadcast_log` table; exposes `push`, `poll`, `prune`. |
| `EventListenerRegistrar::registerBroadcastListeners` | `packages/foundation/src/Kernel/EventListenerRegistrar.php` | Wires Symfony EventDispatcher listeners for `waaseyaa.entity.post_save` and `waaseyaa.entity.post_delete` → `BroadcastStorage::push`. |
| `BroadcastRouter` | `packages/foundation/src/Http/Router/BroadcastRouter.php` | `DomainRouterInterface` implementation. Reads `BroadcastStorage` from `WaaseyaaContext`, emits SSE frames via `StreamedResponse` in a **bounded** loop — exits on client disconnect OR a per-connection time budget (`DEFAULT_MAX_DURATION_SEC`), so a worker is never pinned indefinitely. |
| `WaaseyaaContext::broadcastStorage` | `packages/foundation/src/Http/Router/WaaseyaaContext.php` | Per-request handle the kernel attaches to `$request->attributes['_broadcast_storage']`. |

## Data flow

```
Domain event (entity post_save / post_delete)
    ↓ Symfony EventDispatcher
EventListenerRegistrar listener
    ↓ BroadcastStorage::push(channel, event, data)
SQL table _broadcast_log (id, channel, event, data, created_at)
    ↑ BroadcastStorage::poll(cursor, channels[])
BroadcastRouter::handle (StreamedResponse loop)
    ↓ "event: <name>\ndata: <json>\n\n"
HTTP client (EventSource)
```

The router loop is **bounded**: it exits when `connection_aborted()` reports the
client left **or** when the per-connection time budget
(`BroadcastRouter::DEFAULT_MAX_DURATION_SEC`, 30s) elapses — whichever comes
first. Either exit returns the worker; the browser's `EventSource` reconnects
automatically and resumes from `Last-Event-ID`, so no events are missed. It
polls every 500ms (`DEFAULT_POLL_INTERVAL_US`) and emits `: keepalive\n\n` every
2s (`DEFAULT_KEEPALIVE_INTERVAL_SEC`); the short keepalive cadence is the
disconnect probe — a failed write is what flips `connection_aborted()`, so a
frequent write makes disconnect detection (and worker release) prompt.

This bound is the durable cure for SSE worker starvation: a never-ending loop
held one worker per open admin tab, and under FrankenPHP worker mode (which sets
`ignore_user_abort`) a disconnect could go undetected so the worker was pinned
indefinitely — repeated list↔edit navigation in the admin SPA then exhausted the
pool and hung subsequent requests. The hard time budget guarantees release even
if the SAPI never reports the disconnect. The continuation rule is the pure,
unit-tested `BroadcastRouter::streamShouldContinue()`.

Two further releases make the bound bite promptly rather than only at the 30s
cap (issue #1707):

- **Prompt disconnect detection.** The stream clears `ignore_user_abort(false)`
  for its lifetime, undoing the FrankenPHP/php-fpm bootstrap default that would
  otherwise suppress `connection_aborted()`, and re-probes the abort signal
  immediately after each keepalive and message-batch flush. An abandoned stream
  (reload, route change, closed tab) therefore releases its worker within one
  keepalive (~2s) instead of riding out the full time budget.
- **Session-lock release.** The stream calls `session_write_close()` at the top
  of the closure, after `handle()` has captured `$channels`/`$sessionToken`.
  `SessionMiddleware` opens the native PHP session and PHP holds the `PHPSESSID`
  file lock for the whole script — for a `StreamedResponse` that is the entire
  stream lifetime, so without this every concurrent same-session request (the
  SPA's own document reloads, `/api/*` fetches, a second admin tab) blocked in
  `session_start()` behind the live SSE (the 15-25s admin "blank"). The stream
  never writes the session and the cookie was already sent, so closing it early
  is safe. Measured under `composer run dev`: same-session `/admin/*` while an
  SSE is active dropped from ~28s to <1s.

## `_broadcast_log` schema

Created idempotently by `BroadcastStorage::ensureTable()`:

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PRIMARY KEY AUTOINCREMENT | SSE cursor |
| `channel` | TEXT NOT NULL | Listener-chosen routing key (e.g. `'admin'`) |
| `event` | TEXT NOT NULL | Event name (e.g. `'entity.saved'`) |
| `data` | TEXT NOT NULL DEFAULT '{}' | JSON payload |
| `created_at` | REAL NOT NULL | `microtime(true)` |

This is a non-entity table (a message queue), so it lives outside the entity
storage pipeline per `.claude/rules/entity-storage-invariant.md`. Pruning is
automatic via `BroadcastStorageScheduleEntries` (see "Scheduled Pruning" below).

## Scheduled Pruning

`_broadcast_log` is pruned automatically by `BroadcastStorageScheduleEntries`, which is
auto-discovered at kernel boot via `ScheduleEntriesInterface`.

### Default schedule

| Property | Value |
|---|---|
| Cron | `0 2 * * *` (02:00 UTC nightly) |
| Retention window | 7 days (rows older than 7 days are deleted) |
| Config key | `schedule.broadcast_log_retention_days` (integer) |
| Task identity | `broadcast_log_prune` |

### Customizing retention

Set `schedule.broadcast_log_retention_days` in your configuration:

```yaml
schedule:
  broadcast_log_retention_days: 14  # keep 14 days of broadcast log history
```

### Disabling the prune task

Add the class FQCN to `schedule.disabled_entries`:

```yaml
schedule:
  disabled_entries:
    - Waaseyaa\Api\Schedule\BroadcastStorageScheduleEntries
```

When disabled, the entry appears as `[disabled]` in `bin/waaseyaa schedule:list` output
and `prune()` is never called — the table grows without bound. Disable only if you manage
pruning externally (e.g. via a custom database maintenance job).

### Background

Issue #1536 documented 243 rows accumulating in Minoo's local DB from 2026-03 testing.
The fix (`BroadcastStorageScheduleEntries`, scheduler-entry-auto-discovery mission) adds
auto-discovered pruning so consumers never need to wire the prune task manually.

<!-- Spec reviewed 2026-05-20 - updated for BroadcastStorageScheduleEntries (WP03) -->

## Endpoint

`GET /broadcast` — matched when `_controller == 'broadcast'` in the route
attributes. Query parameter `channels` is a comma-separated list. If absent or
empty, the router defaults to the `admin` channel.

Response headers:

```
Content-Type: text/event-stream
Cache-Control: no-cache
Connection: keep-alive
X-Accel-Buffering: no
```

The opening frame is a `connected` event with the resolved channel list. After
that, each `BroadcastStorage::poll` row is emitted as
`event: <event>\ndata: <full row json>\n\n`. Polling errors log via the
foundation logger and emit an `error` SSE event before pausing 5 seconds and
retrying.

## Per-session private channels (session isolation)

The reserved `session:` namespace (`Waaseyaa\Foundation\Http\Router\SessionChannel`)
carries **per-session** messages. A client may **not** subscribe to a private
channel by name: `BroadcastRouter::resolveSubscriberChannels()` strips any
client-supplied `session:*` from the requested set and instead auto-subscribes the
connection to its OWN channel `session:<token>`, derived server-side from the
connection's PHP session id (`token = substr(sha256(session_id), 0, 32)`). So a
connection only ever receives its own session's private messages, regardless of
the `?channels=` it sends. The `connected` frame exposes the non-secret
`sessionToken` so an authorized publisher can address that session
(`SessionChannel::forToken($token)`) without learning the raw session id. Public
channels (e.g. `admin`) are unaffected. This is the substrate for Wayfinding's
session-scoped beacon delivery (NFR-001).

## Built-in publishers

Two listeners ship in `EventListenerRegistrar::registerBroadcastListeners`:

| Event | Channel | Event name | Data |
|---|---|---|---|
| `waaseyaa.entity.post_save` | `admin` | `entity.saved` | `{entityType, id}` |
| `waaseyaa.entity.post_delete` | `admin` | `entity.deleted` | `{entityType, id}` |

Both wrap the push in try/catch and log on failure — broadcasting is best-effort
and must never break a write.

## Adding a publisher

To broadcast a new event:

1. Resolve `BroadcastStorage` from the request (`WaaseyaaContext::broadcastStorage`)
   in HTTP code, or obtain it through DI in long-running code (the kernel constructs
   one per HTTP request; non-HTTP contexts do not currently have a singleton).
2. Call `$broadcastStorage->push($channel, $event, $data)` from a Symfony
   `EventDispatcher` listener, controller, or service. Wrap in try/catch.
3. Choose a channel name that's stable for the subscriber side. The admin SPA
   subscribes to `admin` by default; new dashboards should pick their own.

Out-of-process publishers (queue workers, CLI commands) can write rows
directly via DBAL — the schema is owned by `BroadcastStorage::ensureTable` but
the table itself is a normal SQL table once created.

## Adding a subscriber

Open an `EventSource` to `/broadcast?channels=<csv>` from any authenticated
HTTP client. Each row arrives as a JSON-encoded `MessageEvent` whose `event`
field is the row's event name. The connection survives until either side
closes; the server-side loop terminates when `connection_aborted()` returns 1.

## Retained messages (replay on connect)

The plain log is fire-and-forget: a message pushed before a connection exists is
never seen by it. That dropped live Wayfinding beacons — a beacon emitted during
the admin SPA's hydration reconnect window vanished before it could render
(`wayfinding-showcase-hardening`). **Retained messages** are the durable
counterpart, modelled on MQTT retained messages: the still-active *state* for a
`(channel, retain_key)` pair, last-write-wins, re-delivered to every NEW
subscriber on connect.

`BroadcastStorage` owns a second table, `_broadcast_retained`:

| Method | Purpose |
|--------|---------|
| `pushRetained($channel, $event, $data, $retainKey, ?$ttlSeconds)` | Push live (into `_broadcast_log`) **and** retain as the current value for `$retainKey`. Returns the broadcast-log id, which is stored on the retained row. |
| `retainedFor($channels)` | Non-expired retained messages for the channels, oldest first; prunes expired rows. Same envelope shape as `poll()`. |
| `dropRetained($channel, ?$retainKey)` | Drop one key, or the channel's whole retained set. |
| `pruneRetained()` | Delete expired (TTL-elapsed) rows. |

`BroadcastRouter` replays `retainedFor($channels)` immediately after the
`connected` frame. Replay frames carry the original broadcast id **inside the
JSON envelope** (so a client de-dupes against a live push it already saw) but
emit **no SSE `id:` line** — they must not rewind the connection's
`Last-Event-ID` cursor; only genuinely-new live messages advance it.

The per-connection lifetime cap (`BroadcastRouter::DEFAULT_MAX_DURATION_SEC`) is
kept at 30s. Lengthening it is counter-productive for a browser client: a
long-lived SSE pins one slot of the browser's ~6-per-origin HTTP/1.1 connection
pool for its whole lifetime, and a longer cap let a single stream starve the
admin SPA's own API fetches under FrankenPHP classic `php-server`. Reconnect
churn is addressed by sharing ONE connection per channel set on the client (not
by a longer cap), and retained replay makes each 30s recycle a single, lossless
reconnect; the 2s keepalive write remains the prompt disconnect probe.

The Wayfinding emit paths use this: `EmitBeaconController` /
`EmitBeaconTool` call `pushRetained(... , retainKey: anchorId, ttl: 3600)`, so a
beacon survives reconnects and reloads; a viewer dismissing the trail clears its
own session's retained beacons (`dropRetained($sessionChannel)`).

## Constraints

- Single-process **(known beta limitation, #1704)**: there is no Redis, NATS, or
  other cross-process transport. Multi-worker PHP-FPM / FrankenPHP deployments
  share the SQL store but each worker holds its own SSE connection, and every
  in-PHP SSE stream pins one worker for its lifetime. Under a rapid-reload
  reconnect storm, EventSource reconnects can arrive faster than 30s-capped
  streams release and saturate the worker pool. Two bounds contain this for
  beta: the per-connection 30s lifetime cap (above) and the **per-account
  concurrent-stream cap** (below). The durable fix — moving off in-PHP SSE to a
  real broker (Mercure) — is tracked post-beta as #1624.

### Per-account concurrent-stream cap

`BroadcastRouter` refuses a new `/api/broadcast` connection with **`503` +
`Retry-After`** once the requesting account already holds
`DEFAULT_MAX_CONCURRENT_STREAMS` (6) active streams. Active streams are counted
from the process-shared `subscribers.json` (so the count spans the whole worker
pool), via the pure static
`BroadcastRouter::countActiveStreamsForAccount(rows, accountId, now, staleAfterSec)`;
rows whose last heartbeat is older than the max stream lifetime are treated as
dead and excluded, so a worker that died before its shutdown cleanup cannot
permanently wedge an account out. The admin SPA shares ONE connection per
channel set, so a healthy client holds 1 stream — the headroom absorbs reload
overlap (an old stream not yet released + the reconnect) while the cap rejects
the runaway accumulation. It is a coarse safety valve: the count→admit window is
not locked, so the effective ceiling may transiently exceed the cap by a small
amount under concurrent connects. Tunable via the `maxConcurrentStreams` /
`retryAfterSec` constructor parameters (0 disables the cap). Enforcement requires
the subscriber-tracking path to be wired (it is, in `HttpKernel`, whenever
`broadcasting.monitor.enabled` is not false).
- Polling: the router polls every 500ms; latency is bounded above by that
  interval, not by event arrival.
- No history replay: clients receive only log messages with `id > cursor`.
  There is no durable cursor per client — reconnects start fresh from "now."
  The one exception is **retained messages** (below), which are state, not
  history, and ARE re-sent on every connect.
- No per-channel ACLs: routing is by string name, not capability. Any
  authenticated session can subscribe to any channel.
- Authentication: the `/broadcast` route inherits the kernel's session
  middleware; an anonymous request returns the same redirect / 401 as any
  other authenticated route.

## Removed in 2026-05-18

The orphaned in-memory scaffold — `BroadcasterInterface`, `BroadcastMessage`,
`SseBroadcaster`, and `BroadcastController` — was deleted in favor of the
production `BroadcastStorage` path. The two paths had diverged: the in-memory
broadcaster supported a closure-based subscriber registry but was never wired
into the kernel and emitted nothing. `BroadcastRouter` had always used
`BroadcastStorage`. The deletion dropped five `SseBroadcaster` entries from the
PHPStan dead-code baseline. See PR closing #1497.
