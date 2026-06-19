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

## Constraints

- Single-process: there is no Redis, NATS, or other cross-process transport.
  Multi-worker PHP-FPM deployments share the SQL store but each worker holds
  its own SSE connection.
- Polling: the router polls every 500ms; latency is bounded above by that
  interval, not by event arrival.
- No replay: clients receive only messages with `id > cursor`. There is no
  durable cursor per client — reconnects start fresh from "now."
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
