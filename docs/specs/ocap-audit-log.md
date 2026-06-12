# OCAP Audit Log Substrate

**Package:** `waaseyaa/audit` (L1 — Core Data)
**Mission:** `ocap-audit-log-substrate-01KSEFTF`
**Requirement refs:** FR-001–FR-015, NFR-001–NFR-005, C-001–C-005, DIR-004

---

## Overview

The OCAP audit log substrate provides an **append-only**, **unified** event table
spanning every significant action in the system: entity lifecycle events, API
requests, AI agent tool executions, MCP dispatches, and broadcast publications.

It operationally embodies **DIR-004 (OCAP-by-architecture)** at the substrate
layer: before per-record AI access policies (M-A5) can be enforced, every
access must be logged. The audit log is the foundation that makes OCAP
verification possible.

---

## Why

- **Regulatory and operational traceability** — operators need to know who
  accessed what data, when, and with what outcome.
- **Security audit trail** — access-denied events and agent tool executions
  must be captured immutably.
- **Retention compliance** — different event kinds may need different retention
  windows; the `audit:prune` CLI and `audit_retention_policy` table support that.
- **Foundation for M-A5** — per-record AI access policies need an audit trail
  to verify OCAP invariants.

---

## Architecture: Cross-Layer L0↔L4 Pattern

`packages/audit` is **L1** (Core Data). `packages/api` is **L4**. The layer
rule says L4 may import from L1 (downward = allowed), but L1 must not import
from L4.

```
L1 packages/audit
  AuditQueryInterface          ← read-side contract (findBy, count)
  AuditWriterInterface         ← write-side contract (record)
  AuditEventDescriptor         ← write DTO
  AuditQuery                   ← read query value object
  AuditEvent                   ← typed read model over an audit_event row (NOT a registered entity)
  AuditRetentionPolicy         ← typed read model over a retention-policy row (NOT a registered entity)
  AuditEventQuery              ← DatabaseInterface-backed read impl
  AuditEventWriter             ← insert-only raw DatabaseInterface impl (via AppendOnlyAuditDatabase)
  AppendOnlyAuditDatabase      ← DatabaseInterface decorator; throws on UPDATE/DELETE of audit_event

L4 packages/api
  AuditQueryReadModelInterface ← api-local read-model interface (@api)
  AuditQueryDto                ← api-local query DTO
  AuditEventResource           ← api-local response DTO
  ApiAuditQueryAdapter         ← implements ReadModelInterface, imports AuditQueryInterface
  AuditQueryController         ← HTTP controller (null-safe read model)
  AuditApiRouter               ← DomainRouterInterface dispatcher
  ApiServiceProvider           ← binds ReadModelInterface → ApiAuditQueryAdapter
```

The **adapter** (`ApiAuditQueryAdapter`) is the only class that crosses the
layer boundary — it imports `AuditQueryInterface` from L1 and lives in L4.
The controller and router know only the L4 interface.

This is the same pattern used by the AI observability dashboard (M5A,
`AiObservabilityReadModelInterface`) and the Mercure broadcast monitor (M5D).

---

## Schema

### `audit_event` table

| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK AUTOINCREMENT | Entity id |
| `uuid` | VARCHAR(128) UNIQUE | RFC-4122 UUID |
| `event_kind` | VARCHAR(64) | `AuditEventKind` enum value |
| `account_uid` | INTEGER NOT NULL DEFAULT 0 | **Legacy compat column**: written as `actor ?? 0`. 0 still conflates anonymous and "no actor" here, by design — existing dashboards/filters keep working byte-for-byte |
| `actor_uid` | INTEGER NULL | **Authoritative actor** (mission `revision-audit-provenance-01KTWY5V`, #1645). Three-state: account id N / anonymous `0` / SQL NULL = no acting context. See "Actor semantics" below |
| `entity_type_id` | VARCHAR(128) | Optional: affected entity type |
| `entity_uuid` | VARCHAR(128) | Optional: affected entity UUID |
| `subject_uri` | VARCHAR(512) | Resource URI being acted upon |
| `outcome` | VARCHAR(16) | `allowed`, `denied`, or `error` |
| `severity` | VARCHAR(16) | `info`, `notice`, or `warning` |
| `attributes` | TEXT (JSON) | Freeform metadata per event kind |
| `created_at` | DATETIME | Immutable write timestamp |

Indices: `uuid` (UNIQUE), `account_uid`, `actor_uid`,
`(entity_type_id, entity_uuid)`, `(event_kind, created_at)`, `created_at`.

### Actor semantics (`actor_uid` vs `account_uid`)

`actor_uid` is the authoritative three-state actor:

| Value | Meaning |
|---|---|
| `N` (int ≥ 1) | A real authenticated account acted (also `PHP_INT_MAX` for the dev fallback) |
| `0` | The **anonymous** account acted — anonymous IS an actor |
| SQL NULL | **No acting context existed** (CLI, queue worker, system bootstrap) |

`0` and NULL are never interchangeable: no listener, writer, or schema default
may coerce a missing actor to `0`. `account_uid` stays physically
`NOT NULL DEFAULT 0` (it cannot be relaxed additively on SQLite) and is
derived at write time as `actor ?? 0` — its historical 0-sentinel semantics
are unchanged, so consumers that filter on it see exactly the pre-mission
behavior.

- **Write DTO:** `AuditEventDescriptor::$accountUid` is `?int` (null = no
  acting context). Existing int-passing constructions are unaffected. The
  writer maps `actor_uid = descriptor.accountUid` (null preserved) and
  `account_uid = descriptor.accountUid ?? 0`.
- **Read model:** `AuditEvent::getActorUid(): ?int` — missing column
  (pre-migration row), SQL NULL, and the `''` empty sentinel all read as
  null. `getAccountUid(): int` is unchanged (legacy accessor).
- **Migration behavior:** new installs get `actor_uid` in the
  `CREATE TABLE IF NOT EXISTS`; existing installs get an idempotent guarded
  `ALTER TABLE audit_event ADD COLUMN actor_uid INTEGER` (column-existence
  probe first; nullable no-default ADD COLUMN is metadata-only DDL on
  SQLite/MySQL 8/PostgreSQL). Additive only — no existing column or row is
  changed; rows written before the upgrade read `actorUid: null`
  ("actor not recorded").
- The actor source per listener is in the Listener Catalogue below.

### `audit_retention_policy` table

| Column | Type | Notes |
|--------|------|-------|
| `id` | INTEGER PK AUTOINCREMENT | Entity id |
| `uuid` | VARCHAR(128) UNIQUE | RFC-4122 UUID |
| `kind_pattern` | VARCHAR(64) | Glob: `*`, `entity.*`, or literal |
| `older_than_seconds` | INTEGER | Seconds; events older than this are eligible |
| `action` | VARCHAR(16) | Currently only `purge` |
| `created_at` | DATETIME | Policy creation timestamp |

---

## Event-Kind Taxonomy

`AuditEventKind` is a backed string enum with 19 cases (additive — cases are
never removed per the out-of-band downstream-amendment principle):

| Case | Value | Description |
|------|-------|-------------|
| `EntityRead` | `entity.read` | Entity viewed via API or service |
| `EntityWrite` | `entity.write` | Entity created or updated |
| `EntityDelete` | `entity.delete` | Entity deleted |
| `EntityExport` | `entity.export` | Entity exported (e.g. JSON export) |
| `AccessDenied` | `access.denied` | Access check returned Forbidden |
| `ClassificationChange` | `classification.change` | Entity classification changed |
| `RetentionPurge` | `retention.purge` | Data purged under retention policy |
| `RetentionRedact` | `retention.redact` | Data redacted under retention policy |
| `RetentionHold` | `retention.hold` | Data placed on retention hold |
| `AgentToolExecute` | `agent.tool_execute` | AI agent tool called |
| `McpDispatch` | `mcp.dispatch` | MCP tool dispatched |
| `BroadcastPublish` | `broadcast.publish` | SSE broadcast published |
| `ApiRequest` | `api.request` | HTTP API request received |
| `AuditRetentionPruned` | `audit.retention_pruned` | Self-audit: `audit:prune` executed |
| `MediaVersionCreated` | `media.version.created` | Added by `versioned-blob-media-abstraction` |
| `MediaVersionRead` | `media.version.read` | Added by `versioned-blob-media-abstraction` |
| `MediaVersionDedupHit` | `media.version.dedup_hit` | Added by `versioned-blob-media-abstraction` |
| `RevisionPublish` | `revision.publish` | Published-revision pointer moved (added by `revision-audit-provenance`) |
| `RevisionRevert` | `revision.revert` | Current-revision pointer moved back to a prior revision (added by `revision-audit-provenance`) |

Extension policy: new cases MUST be additive only. Removal requires a
deprecation period and a major-version bump.

---

## Listener Catalogue

All six listeners have **best-effort write semantics** — they wrap
`$writer->record(...)` in a try-catch to prevent audit failures from
crashing primary requests (NFR-001).

| Listener | Events Subscribed | Kind Emitted |
|----------|------------------|--------------|
| `EntityLifecycleAuditListener` | `EntityEvent::PRE_SAVE`, `EntityEvent::POST_SAVE`, `EntityEvent::POST_DELETE` | `entity.write`, `entity.delete` |
| `ApiRequestAuditListener` | `KernelEvents::REQUEST` | `api.request` |
| `AgentToolAuditListener` | `AgentRunEvents::TOOL_EXECUTE` | `agent.tool_execute` |
| `McpDispatchAuditListener` | `McpEvents::DISPATCH` | `mcp.dispatch` |
| `BroadcastAuditListener` | `BroadcastEvents::PUBLISH` | `broadcast.publish` |
| `PublishPointerAuditListener` | `RevisionPointerMovedEvent::class` (typed FQCN subscription — audit requires entity-storage, L1→L1) | `revision.publish`, `revision.revert` |

### Per-listener actor source

Since mission `revision-audit-provenance-01KTWY5V` (#1645), listeners no
longer derive actors locally — each resolves the **acting** account and
preserves null distinctly from 0:

| Surface | Listener | Actor source | Before the mission |
|---|---|---|---|
| Entity lifecycle (write/delete) | `EntityLifecycleAuditListener` | The acting account from `AccountContextInterface` (N / 0 / null). The saved entity's own `uid` field is **never** consulted — a user-A session saving a node owned by user B records actor A | The entity's `uid` field value, else 0 (misattribution) |
| API request | `ApiRequestAuditListener` | The `_account` request attribute (unchanged — this was already correct) | same |
| Agent tool execute | `AgentToolAuditListener` | The event's additive `?int $accountId` property (the run initiator, populated by `AgentExecutor` on both the success and threw dispatch paths; `0` is a real value — the anonymous initiator) → `AccountContextInterface` fallback → null. Duck-read: legacy event shapes lacking the property still record via the fallback | hardcoded `0` |
| Publish/revert pointer | `PublishPointerAuditListener` (**new**) | The event's `actorUid` (resolved by `EntityRepository` at dispatch time; preferred when non-null) → `AccountContextInterface` fallback → null | no row at all (pointer moves were audit-invisible) |
| MCP dispatch | `McpDispatchAuditListener` | The event's `accountUid` (?int, the bearer-auth account), preserved verbatim — a null or absent value stays null, never coerced to 0 | event never fired; listener cast absent → 0 |
| Broadcast publish | `BroadcastAuditListener` | unchanged | same |

`PublishPointerAuditListener` records `revision.publish` / `revision.revert`
from `Waaseyaa\EntityStorage\Event\RevisionPointerMovedEvent` (dispatched by
`EntityRepository::setPublishedRevision()` / `setCurrentRevision()` **after**
the pointer transaction commits — a rolled-back move produces no row;
`rollback()` emits no pointer event). Row shape: `subject_uri =
/entities/<type>/<id>`, outcome `allowed`, severity `notice`, attributes
`{entity_id, operation, from_revision_id, to_revision_id}`. See
`docs/specs/revision-system-unified.md` §4a for the event contract.

The `AccountContextInterface`-reading listeners receive the kernel's shared
acting-account holder via the kernel-services bus (`AuditServiceProvider`
resolves it optionally; bare-provider tests without a kernel get null and
record null actors — the correct degraded behavior). The context contract
itself is documented in `docs/specs/access-control.md` §"Acting-account
context".

---

## Query API

### Interface (`AuditQueryInterface`, L1)

```php
interface AuditQueryInterface {
    /** @return iterable<AuditEvent> */
    public function findBy(AuditQuery $query): iterable;
    public function count(AuditQuery $query): int;
}
```

`AuditQuery` fields (all nullable/optional):

| Field | Type | Description |
|-------|------|-------------|
| `accountUid` | `?int` | Filter by account UID |
| `entityType` | `?string` | Filter by entity type ID |
| `entityUuid` | `?string` | Filter by entity UUID |
| `kinds` | `?AuditEventKind[]` | Filter by kind list |
| `from` | `?\DateTimeImmutable` | Events after this time |
| `to` | `?\DateTimeImmutable` | Events before this time |
| `limit` | `int` | Page size (default 50) |
| `offset` | `int` | Page offset (default 0) |

### JSON:API Endpoint

**Route:** `GET /api/audit/events`
**Access:** `_role: admin` (route option, NFR-001 — controller does NOT re-check)
**Router:** `AuditApiRouter` (L4)
**Controller:** `AuditQueryController::index(Request $request): array`

Query parameters:

| Param | Type | Default | Notes |
|-------|------|---------|-------|
| `page[limit]` | int | 50 | Max 500 |
| `page[offset]` | int | 0 | |
| `filter[account]` | int | — | UID |
| `filter[entity]` | string | — | `type:uuid` |
| `filter[kind]` | string | — | Comma-separated kind values |
| `filter[from]` | ISO-8601 | — | |
| `filter[to]` | ISO-8601 | — | |

Response shape:

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "...",
      "eventKind": "entity.read",
      "accountUid": 1,
      "entityType": "node",
      "entityUuid": "...",
      "subjectUri": "/api/node/1",
      "outcome": "allowed",
      "severity": "info",
      "attributes": {},
      "createdAt": "2026-05-25T00:00:00+00:00"
    }
  ],
  "meta": {
    "total": 42,
    "limit": 50,
    "offset": 0
  }
}
```

Ordering is always `created_at DESC`.

---

## Retention

### `AuditRetentionPolicy` Entity

Each row describes a rule: events matching `kind_pattern` that are older than
`older_than_seconds` seconds are eligible for `action` (currently only `purge`).

### CLI Command

```
bin/waaseyaa audit:prune --older-than=<ISO-8601-duration> [--kind=<glob>] [--dry-run]
```

- `--older-than` (required): ISO-8601 duration string (e.g. `P30D`, `PT1H`, `P1Y`).
- `--kind` (optional, default `*`): Glob pattern against kind values. `*` = all;
  `entity.*` = all entity.* cases; literal = single exact kind.
- `--dry-run`: Print the count; do not delete.

**Algorithm:**

1. Validate `--older-than` via `new \DateInterval(...)`.
2. Compute `cutoff = now() - interval`.
3. Build `AuditQuery` with `to = $cutoff` and kind filter.
4. `$count = $query->count($auditQuery)`.
5. If `--dry-run`: print count and exit 0.
6. Write self-audit event via `$writer->record(AuditEventDescriptor{kind: AuditRetentionPruned, attributes: {kind_pattern, older_than, deleted_count: $count, cutoff}})`.
7. Execute `$db->delete('audit_event')->condition('created_at', $cutoff, '<')->...->execute()`.
8. Print confirmation and exit 0.

Self-audit semantics (FR-012): the `audit.retention_pruned` event is recorded
BEFORE the delete so its `deleted_count` reflects the pre-deletion count.

---

## Performance Budget (NFR-005)

`GET /api/audit/events?filter[account]=X&page[limit]=50` must return in
**< 100ms** on a database with 1,000+ events. The compound indices on
`(account_uid)`, `(event_kind, created_at)`, and `(created_at)` are designed
to satisfy this budget on SQLite and MySQL/PostgreSQL.

---

## Implementation Notes

- **`audit_event` and `audit_retention_policy` are NOT registered content
  entities.** They are flat OCAP log tables built by `AuditEventSchemaHandler`
  and accessed through raw `DatabaseInterface` writes/reads. The `AuditEvent` /
  `AuditRetentionPolicy` classes are typed read-model accessors over a row
  (each overrides `get()` to read the value bag directly, so reads never depend
  on entity-type registration). They were de-registered in alpha.202 because the
  registration produced 8 permanent `schema:check` false-positives (the lean log
  tables lack the content-entity column set) and falsely implied an entity
  CRUD/update path for an append-only log.
- `AuditEventWriter` appends rows via a raw, parameterized, **insert-only**
  `DatabaseInterface` INSERT — never `EntityRepository::save()`. It is best-effort:
  `record()` catches all exceptions and logs via `LoggerInterface`; it never
  throws (FR-005).
- `AuditEventQuery` uses `DatabaseInterface` directly for read performance — no
  entity hydration overhead for bulk queries.
- **`AppendOnlyAuditDatabase`** is the active append-only enforcer (C-001): a
  `DatabaseInterface` decorator that throws `\LogicException` on any `UPDATE` or
  `DELETE` of `audit_event`, passing inserts/reads/other-table access through.
  The writer (and only the writer) is wired with it, so the sole mutation it can
  express is an append. The one sanctioned deletion — the `audit:prune` retention
  purge — resolves the **raw** `DatabaseInterface`, deliberately bypassing the
  decorator, so retention works while every writer path stays immutable.
  (This replaces the former `AppendOnlyDriverGuard`, an entity-storage-driver
  decorator that was never instantiated and guarded a path that no longer exists
  now that audit_event is not an entity.) See
  `packages/audit/tests/Integration/AuditImmutabilityTest.php` for the proof.
- **Raw SQL through the decorator is guarded too** (FR-008 of mission
  `revision-audit-provenance-01KTWY5V`, #1648 — previously `query()` passed
  raw SQL through unguarded). `AppendOnlyAuditDatabase::query()` performs a
  token-level check, not a SQL parse: single-/double-quoted string literals
  and SQL comments (`--`, `/* */`) are stripped, then a word-boundary
  mutation verb (`UPDATE` / `DELETE` / `DROP` / `ALTER` / `TRUNCATE`)
  co-occurring with a word-boundary append-only table name (`audit_event`)
  throws the **same** `\LogicException` as the builder-level guard (shared
  message factory).
  - **What passes:** `SELECT`s over `audit_event` — including ones whose
    string literals merely *contain* mutation verbs, e.g.
    `WHERE attributes LIKE '%delete%'` (`attributes` is a JSON TEXT column,
    so payloads genuinely contain such words); `INSERT INTO audit_event …`;
    any mutation of non-audit tables.
  - **Fail-closed residuals (documented and accepted):** a CTE-wrapped
    mutation (`WITH x AS (...) DELETE FROM audit_event`) throws, and so does
    a pathological SELECT joining `audit_event` against an identifier
    literally named `delete` — the correct posture for an append-only
    guarantee.
  - **Zero false positives on the audit package's own operations (NFR-003),
    structurally:** the decorator is held only by `AuditEventWriter`, which
    is insert-only and never calls `query()`; `audit:prune` and
    `AuditEventQuery` resolve the raw `DatabaseInterface` and never pass
    through the decorator. The proof is the pre-existing audit suite
    (immutability, chaos, prune including the `audit.retention_pruned`
    self-audit) passing unchanged.
- The `ApiAuditQueryAdapter` silently skips unknown `kind` string values during
  enum resolution — future-compatible with new enum cases arriving via
  downstream amendment.

---

## Cross-References

- `docs/specs/api-layer.md` — JSON:API endpoint patterns, router shape.
- `docs/specs/codified-context-integration.md` §"OCAP Audit Log Read-Contract Pattern" — L0↔L4 adapter pattern.
- `docs/specs/access-control.md` — `_role: admin` route option, `AccessChecker`.
- `packages/audit/src/AuditServiceProvider.php` — container bindings.
- `packages/api/src/ApiServiceProvider.php` — L4 binding + `resolveOptional` wiring.

<!-- Spec written 2026-05-25 - mission ocap-audit-log-substrate-01KSEFTF WP03: JSON:API audit endpoint + audit:prune CLI + integration tests. Refs gap-matrix-A3, DIR-004. -->
<!-- Spec reviewed 2026-06-09 - alpha.202: audit_event/audit_retention_policy de-registered as content entities (now raw OCAP log tables + typed read models); append-only enforcement moved from the dormant AppendOnlyDriverGuard to the active AppendOnlyAuditDatabase DatabaseInterface decorator; writer migrated to insert-only raw INSERT. Refs #1625. -->
<!-- Spec reviewed 2026-06-12 - mission revision-audit-provenance-01KTWY5V WP05: actor_uid authoritative three-state column (+index, guarded ALTER migration; account_uid retained as legacy actor??0); AuditEventDescriptor::$accountUid widened to ?int; AuditEvent::getActorUid(); taxonomy 17→19 (revision.publish / revision.revert); listener catalogue gains PublishPointerAuditListener + per-listener actor-source table (entity lifecycle no longer reads the entity's uid field; agent tools no longer hardcode 0); AppendOnlyAuditDatabase::query() raw-SQL guard documented (literal/comment stripping, conjunctive verb+table match, fail-closed residuals, structural NFR-003 argument). Refs #1645, #1648. -->
