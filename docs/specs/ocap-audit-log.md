# OCAP Audit Log Substrate

<!-- Spec reviewed 2026-08-29 - #2700: the existing `user.session-identity` / SessionBootstrap capability now grants `session_generation` alongside name, mail, and roles. Session issuance and middleware validation consume that exact audited value; generic reads remain forbidden. -->
<!-- Spec reviewed 2026-08-27 - #2544: the `user.credentials` capability issuer now grants `['status', 'pass', 'legacy_pass']`. `legacy_pass` is read under the SAME CredentialVerification reason as `pass` because it is a password equivalent until the first successful login upgrades it away - it must not be reachable through any weaker reason. No new issuer, reason, or actor semantics. -->


<!-- Spec reviewed 2026-08-24 - #1856: EntityLifecycleAuditListener keys PRE_SAVE isNew() on the entity object (WeakMap) and consumes that entry at the start of POST_SAVE, including when the writer throws. Mixed saveMany create/update batches keep per-entity is_new provenance. Event order, transactions, and deleteMany PRE_DELETE buffering are unchanged; canonical pairing contract lives in docs/specs/entity-system.md. -->
<!-- Spec reviewed 2026-08-20 - #2464: successful copy-forward rollback audit
attributes now retain source_revision_id separately from from_revision_id and
to_revision_id. The record remains metadata-only and best-effort; actor,
subject, allowed outcome, and operation semantics are unchanged. -->

<!-- Spec reviewed 2026-08-13 - AuditQuery adds an optional exact
`subjectUri` filter (appended for positional-constructor compatibility). The
API adapter carries the same field so authorized domain surfaces can project
resource-scoped history without broad audit access. Exact matching is covered
against prefix-neighbour subjects. -->

<!-- Spec reviewed 2026-08-08 - #2304: AuditServiceProvider binds an authenticated-self-only profile reader that issues exact `name` and `mail` authority, binds actor plus tenant/community claims, records the privileged read, and revokes the execution boundary afterward. Anonymous or mismatched actors fail before authority reservation. -->

<!-- Spec reviewed 2026-07-17 - #2064 WP1 adds the StrictPrivilegedReadLedgerInterface reservation/finalization contract and metadata-only descriptor/receipt/outcome values. It is separate from and does not alter the existing best-effort AuditWriterInterface; no implementation is wired in WP1. Canonical contract: entity-field-read-boundary.md. -->
<!-- Spec reviewed 2026-07-17 - #2064 WP2 adds durable strict privileged-read persistence as immutable reservation/finalization events, atomic single-finalization, explicit interrupted reservations, exact multi-field/bootstrap and dormant query readers, and complete classifications for every flat audit table column. This remains separate from best-effort AuditWriterInterface telemetry. -->

### Strict privileged-read durability under SQLite contention

`DatabaseStrictPrivilegedReadLedger` makes every single reservation, batch
reservation, and batch finalization durable inside an explicit database
transaction. A transient SQLite `BUSY` or `LOCKED` failure may be retried at
most twice, with bounded 10 ms then 20 ms backoff, only when no transaction was
opened or the failed transaction was successfully rolled back. Receipt
identities are allocated before the attempt loop and remain stable across a
retry. Logic/contract failures are never retried or wrapped; non-contention
storage failures and any failure whose rollback cannot be proven surface as
`PrivilegedReadLedgerException` immediately. This preserves fail-closed audit
authority without treating an ambiguous commit as safe to replay.

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
  AppendOnlyAuditDatabase      ← DatabaseInterface decorator; throws on UPDATE/DELETE of audit_event, on destructive raw SQL (DROP/ALTER/TRUNCATE…audit_event via query()), and — through AppendOnlySchema — on destructive schema DDL (dropTable/dropField/dropIndex) of audit_event
  AppendOnlySchema             ← SchemaInterface decorator returned by AppendOnlyAuditDatabase::schema(); refuses destructive DDL on append-only tables, passes additive DDL + non-append-only tables through

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

**Single source of truth.** `Schema/AuditEventSchemaHandler::ensureSchema()` is the **one** authoritative definition of the audit schema (the `audit_event`, `audit_retention_policy`, and `audit_checkpoint` tables, their indices, and the genesis anchor). `AuditServiceProvider::boot()` calls it on every boot, and the standalone migration `migrations/2026_05_25_000001_create_audit_event_table.php` **delegates to the same handler** (`new AuditEventSchemaHandler(new DBALDatabase($schema->getConnection()))->ensureSchema()`) rather than hand-maintaining its own `CREATE TABLE`. This guarantees a standalone `migrate` produces byte-identical schema to the boot path; a hand-written migration previously drifted (it lacked `actor_uid`/`row_hash`/`prev_hash`), so a migrate-only install produced a schema the writer's INSERT failed against.

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

## Tamper-evidence (hash chain + signed checkpoints)

The append-only decorator *prevents* mutation through sanctioned paths, but a
party with raw DB access can still edit/delete history. Tamper-evidence makes
such mutation **detectable** (design: `craftsmanship/AUDIT-TAMPER-EVIDENCE-DESIGN.md`).
The scheme is **A+C hybrid, per-segment (checkpoint-time) chaining** — no
hot-path serialization on the writer.

**Data model (WP1):**
- `audit_event` gains `row_hash CHAR(64)` and `prev_hash CHAR(64)` (both
  `DEFAULT ''` = *unsealed*). They are populated **at checkpoint time** by the
  builder, not at insert — the writer's append path is unchanged.
- `audit_checkpoint` seals contiguous segments: `segment_start_id`/`segment_end_id`,
  `row_count`, `segment_hash` (digest over the segment's `row_hash`es),
  `prev_checkpoint_hash` (chains checkpoints), `checkpoint_hash`
  (`AuditCheckpointHasher::checkpointHash()` over the segment fields +
  `prev_checkpoint_hash`), `signature` (deferred), `hash_version`, `is_genesis`.
  It is itself **append-only** (`AppendOnlyAuditDatabase::APPEND_ONLY_TABLES`).
- **Canonicalization** is pinned + versioned in `AuditEventCanonicalizer`
  (`HASH_VERSION = 'v1'`): fixed column order, explicit NULL sentinel,
  length-prefixed so no field value can forge a delimiter. `row_hash =
  sha256(canonical(content) || prev_hash)`.
- **Genesis anchor:** on first `ensureSchema()`, one `is_genesis=1` checkpoint is
  written over the current `MAX(id)` with `prev_checkpoint_hash = segment_hash =
  GENESIS_HASH` (64 zeros). Pre-migration rows are **not** back-chained — they
  are attested as "predates chaining", never given false assurance. Idempotent
  (guarded on a zero-rows check).

**Checkpoint builder (WP2):** `AuditCheckpointBuilder` seals all currently-unsealed
rows (`id > MAX(segment_end_id)`) into one new checkpoint via the **raw**
`DatabaseInterface` (it writes `row_hash`/`prev_hash` and inserts the checkpoint —
both forbidden through the decorator, exactly like `audit:prune`). It builds a
*continuous* row chain (the first unsealed row's `prev_hash` = the prior segment's
`segment_hash`, anchored at `GENESIS_HASH` for the first real segment), sets
`segment_hash` = the last `row_hash` (chain head), and chains checkpoints via
`prev_checkpoint_hash`. A partial seal interrupted by a crash is **idempotently
re-derived** next run (the high-water mark comes from the last *checkpoint*, not
from row state). It runs on a schedule (`AuditCheckpointScheduleEntries`, default
`*/15 * * * *`) and on demand via `bin/waaseyaa audit:checkpoint`. Each sealed
checkpoint is exported through a pluggable **`CheckpointSink`** — the
**load-bearing anchor**. The exported record includes the detached checkpoint
signature so an external sink retains the authentication material alongside the
hash-chain fields. The default `FileCheckpointSink` appends NDJSON locally
(+ optional stdout), but this is only as trustworthy as the host: **real
tamper-evidence requires configuring an off-box / WORM / external append-only
sink** (a host able to edit `audit_event` can also edit a local file). Optional
HMAC over `checkpoint_hash` is mandatory in kernel-wired operation. Its raw
32-byte key is HKDF-SHA-256-derived from `WAASEYAA_APP_SECRET` with purpose
`waaseyaa.audit.checkpoint-hmac.v1`; the stored form is
`hmac-sha256.hkdf-v1:<64 lowercase hex>`. When a derived key is configured, the
verifier requires and constant-time verifies that envelope on **every** checkpoint,
including genesis; empty/bare legacy values never count as authenticated history.
Before the first keyed segment is sealed, the builder authenticates only the
deterministic, empty pristine genesis anchor by compare-and-swap. It refuses a
genesis signature produced by another or malformed key rather than creating a
mixed chain. Existing non-genesis history is never migrated implicitly.
Existing chains are upgraded only by the explicit, transactional
`audit:migrate-checkpoint-signatures --confirm` command after the operator has
established trust in a backup. It refuses malformed, mixed, or hash-chain-broken
history and strict-verifies the result before commit. Ordinary verification never
performs migration writes. Asymmetric/KMS signing is deferred.

**Verification — `audit:verify` (WP3):** `AuditChainVerifier::verify()` walks every
sealed segment and returns an `AuditVerificationResult` (`ok`, `firstBrokenId`,
`failureKind`, counts). Per checkpoint it checks: the checkpoint chains to its
predecessor (`prev_checkpoint_hash`), the present row count equals `row_count`,
each row's `prev_hash` links to the previous row's `row_hash` (first row → prior
`segment_hash`), each row's content recomputes to its stored `row_hash`
(`AuditEventCanonicalizer`), the last `row_hash` equals the checkpoint's
`segment_hash`, and the checkpoint's `checkpoint_hash` recomputes. It STOPS at the
first break with a machine-readable `failureKind` ∈ {`genesis`, `checkpoint_signature`, `prune_authorization`,
`checkpoint_chain`, `row_count`, `chain_link`, `row_content`, `segment_hash`, `checkpoint_hash`}. Rows
`id ≤ genesis.segment_end_id` (predates chaining) and rows past the last checkpoint
(unsealed/pending) are not failures. `bin/waaseyaa audit:verify [--json]` exits 0
when intact and non-zero on tamper, and emits an `audit.verify` self-audit event
(outcome `allowed`/`denied`). This detects content edits, row deletions, gaps, and
forged checkpoints — what the append-only decorator cannot *prevent* against a
party with raw DB access.

**Prune reconciliation — checkpoint-aware `audit:prune` (WP4):** retention pruning
must not look like tampering. `audit_checkpoint` has a `pruned` flag and a
detached `prune_authorization`. `audit:prune`
deletes in two disjoint populations: **sealed** rows (covered by a non-genesis
checkpoint) are pruned only at WHOLE checkpoint-segment boundaries — it computes a
`horizon` = the highest `segment_end_id` whose entire segment is older than the
cutoff, marks those checkpoints `pruned=1`, attaches a domain-separated HMAC
over each checkpoint hash when keyed custody is configured, and deletes
`audit_event WHERE id <= horizon` in the same transaction (`--kind` does NOT
apply to sealed rows; whole-segment deletion is
required for chain integrity). Before it records intent or authorizes deletion,
the command verifies the complete current sealed chain and refuses to bless a
pre-existing forged or broken pruned state. **Unsealed-tail** rows
(`id > MAX(segment_end_id)`)
keep the legacy `created_at`(+`--kind`) deletion (no chain yet). The
`audit.retention_pruned` self-audit records `sealed_pruned_through_id`,
`pruned_checkpoint_hash`, and `unsealed_deleted_count`. Its `deleted_count`
equals the real sealed-plus-unsealed total (what the two delete paths above
are about to remove: the record is written before the deletes execute, so it
reflects intent, not observed outcome), not a kind-filtered match count:
since sealed rows are
pruned whole regardless of `--kind`, a kind-filtered count can undercount the
real deletion when `--kind` is set (audit A7, F10). The confirmation prompt
(refusal without `--confirm`) reports that same real total. The superseded
kind-filtered number is kept separately under `kind_filtered_match_count` for
anyone inspecting the self-audit trail. `audit:verify` treats a
`pruned=1` checkpoint as a valid anchor only after it verifies the checkpoint's
chain link, recomputes its `checkpoint_hash`, and, in keyed mode, validates the
detached prune authorization. The authorization is bound to that exact
checkpoint hash and cannot be replayed from another checkpoint or replaced by
the ordinary checkpoint signature. The original checkpoint signature is never
rewritten. The verifier then skips the row-level checks (the rows are
legitimately gone) and
advances the chain from the retained `segment_hash`, so the surviving chain still
validates across the prune boundary. A row deleted from a sealed segment **without**
the `pruned` flag still fails verification (`row_count`/`chain_link`), while a
forged `pruned` flag in keyed mode fails `prune_authorization`.

**v1 complete.** Remaining hardening is tracked separately: the external-sink
cross-check at verify time.

**Fail-open write-path hardening (WP4, #1792, design §10.4):** implemented. On
any audit INSERT failure `AuditEventWriter` now:
1. Calls the `AuditWriteFailureObserver` L1 seam
   (`Waaseyaa\Audit\Contract\AuditWriteFailureObserver`) — a loud metric/alert
   hook intended for higher-layer wiring (Prometheus/Telescope at L6; the default
   is `NullAuditWriteFailureObserver`, a no-op). Operators bind a real
   implementation in their service provider.
2. Attempts one best-effort marker INSERT of kind `AuditEventKind::AuditWriteDegraded`
   (`audit.write_degraded`) whose attributes carry the `dropped_kind`, `error_class`,
   and `error_message`. This turns a silently dropped event into an attested
   degraded window visible to `audit:verify` and operator dashboards.
3. If the marker ALSO fails, just logs — no recursion, no re-entry into `record()`,
   no exception escaping to the caller (FR-005/NFR-001 best-effort contract is
   preserved). The approach is marker + metric, NOT hard fail-closed.

---

## Event-Kind Taxonomy

`AuditEventKind` is a backed string enum with 23 cases (additive — cases are
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
| `AuditVerified` | `audit.verify` | `audit:verify` ran and checked the hash chain + checkpoints (added WP3) |
| `AuditWriteDegraded` | `audit.write_degraded` | Sentinel written when a primary audit INSERT fails; attributes carry `dropped_kind`, `error_class`, `error_message` (added WP4 #1792, design §10.4) |
| `WorkflowTransition` | `workflow.transition` | Workflow transition fired (allowed) or attempted and refused (denied) via `TransitionService`/the save-path guard (added CW-v1 WP-1, #1920, `docs/specs/content-workflow.md`) |
| `RevisionRollback` | `revision.rollback` | `EntityRepository::rollback()` recorded by `RollbackAuditListener` — distinct from `RevisionRevert` (a revert moves the pointer to an EXISTING revision; a rollback creates a brand-new one) (added CW-v1 WP-2 task 2.5, #1920, `docs/specs/content-workflow.md`) |

Extension policy: new cases MUST be additive only. Removal requires a
deprecation period and a major-version bump.

---

## Listener Catalogue

All seven listeners have **best-effort write semantics** — they wrap
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
| `RollbackAuditListener` | `BeforeRevisionPointerMoveEvent::class` (arms on `operation === 'rollback'`) + `EntityEvents::REVISION_REVERTED->value` (consumes the armed slot) | `revision.rollback` |

`entity.write` distinguishes create from update via `isNew()` captured at `PRE_SAVE`. That flag is keyed on the entity object (`WeakMap`) and consumed at the start of `POST_SAVE` (before the writer runs, including when the writer throws). `saveMany()` still dispatches `pre1, pre2, …, post1, post2, …`, so a listener-wide boolean would attribute every row from the last PRE event (#1856). Canonical pairing contract: `docs/specs/entity-system.md`.

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
| Rollback | `RollbackAuditListener` (**new**, CW-v1 WP-2 task 2.5) | The pre-event's `actorUid` (armed at `rollback()`'s dispatch time; preferred when non-null) → `AccountContextInterface` fallback → null — same three-state contract as `PublishPointerAuditListener` | no row at all (`rollback()` was invisible to both audit listeners: it never dispatches `RevisionPointerMovedEvent`) |
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

`RollbackAuditListener` closes the gap `PublishPointerAuditListener` leaves for
`EntityRepository::rollback()` (it copies a prior revision forward as a
brand-new one — unlike a revert, it never dispatches
`RevisionPointerMovedEvent`, which is reserved for pointer moves WITHOUT a new
revision). It ARMS on `Waaseyaa\EntityStorage\Event\BeforeRevisionPointerMoveEvent`
with `operation === 'rollback'` — dispatched pre-write by exactly one code
path, `rollback()` itself, so the arm signal is unambiguous by construction —
and consumes the armed slot on the following `EntityEvents::REVISION_REVERTED`
for the same entity. Every pointer operation's pre-event re-arms or clears the
single-slot state (armed only for `rollback`, cleared otherwise), so an
aborted rollback's stale arm cannot survive to correlate with a later
unrelated publish/revert, and an ordinary save followed by a legitimate
`setPublishedRevision()` of that same revision (the forward-draft publish
flow) records nothing here. Row shape: `subject_uri = /entities/<type>/<id>`,
outcome `allowed`, severity `notice`, attributes `{entity_id, operation:
'rollback', from_revision_id, to_revision_id}` (the last known only post-write
— rollback's pre-event `toRevisionId` is always null, the id is
writer-assigned). See `docs/specs/content-workflow.md` "Pointer-move guard"
for the full arm/consume design and the rejected identity-pairing alternative.

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


## Strict reserve/finalize ledger (`strict_audit_ledger`, #2177 F4)

A second, deliberately **non**-best-effort write path, alongside `AuditWriterInterface`.

`AuditWriterInterface` is contractually best-effort — `record()` MUST swallow every exception and MUST NOT throw (FR-005 / NFR-001). That is correct for an observability log, and unusable for a surface that must refuse to act when it cannot be audited. `Waaseyaa\Foundation\Audit\StrictAuditLedgerInterface` is its opposite number: `reserve()` and `finalize()` **throw** `StrictAuditLedgerException` when a record cannot be made durable, so the caller can decline to proceed.

It is the sibling of `StrictPrivilegedReadLedgerInterface` (privileged reads) and follows the same reserve → act → finalize shape and the same append-only storage discipline. `strict_audit_ledger` is registered in `AppendOnlyAuditDatabase::APPEND_ONLY_TABLES`, so a reservation can be appended but never updated or deleted through the audit database — evidence of a mutation cannot be rewritten after the fact.

**Why the port lives in `waaseyaa/foundation`, not here.** Its first consumer is the MCP write tier, and `waaseyaa/mcp` must not require `waaseyaa/audit` at runtime (see `McpDispatchEvent`, contract clause 18). Foundation is the one package both the consumer and this implementation already depend on. The contracts and value objects are in `Waaseyaa\Foundation\Audit`; the database implementation is `Waaseyaa\Audit\Writer\DatabaseStrictAuditLedger`.

| Column | Meaning |
|---|---|
| `receipt_id` | joins a `reserved` row to its `finalized` row |
| `correlation_id` | joins every record for one request, including `audit_event` rows |
| `event_type` | `reserved` \| `finalized` \| `recorded` (single-shot terminal stage) |
| `surface` | e.g. `mcp.write` — one ledger can serve several entry points |
| `operation` | what was attempted (for MCP, the tool name) |
| `stage` / `outcome` | `AuditStage` value and its derived outcome |
| `actor_uid` | three-state actor: `null` (no principal) / `0` (anonymous) / N |
| `descriptor` | redacted `safe_arguments` + safe metadata |

`UNIQUE(receipt_id, event_type)` makes a double-finalize impossible at the storage layer, independent of the application-level guard.

**The guarantee is pre-durability, not atomicity.** See `docs/specs/mcp-endpoint.md` for the full statement, the four reasons atomic coupling is not reachable, and the dangling-reservation query for the crash window.

## Operation approval event log (`mcp_approval_event`, #2177 F1)

Durable human approvals for destructive MCP write-tier calls, stored as append-only events by `Waaseyaa\Audit\Writer\DatabaseOperationApprovalStore` (the implementation of `Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface` — the port lives in foundation for the same no-runtime-audit-dependency reason as the strict ledger above; see `docs/specs/infrastructure.md` §"Operation approval port"). `mcp_approval_event` is registered in `AppendOnlyAuditDatabase::APPEND_ONLY_TABLES`, so an approval can be appended but never forged by update, revoked after use, or made reusable by delete. Schema: `Waaseyaa\Audit\Storage\ApprovalEventSchema` (additive, idempotent; ensured **lazily** by `AuditServiceProvider`'s `OperationApprovalStoreInterface` binding on first resolution — slice B — so a deployment that never uses the write tier pays nothing at boot). The binding reads the expiry window from `mcp.write_tier.approval.ttl_seconds` (strict positive integer, integer-shaped strings accepted, default 900; malformed values throw `ConfigException` naming the key and value type only). The consumer is the `McpEndpoint` write-tier approval gate — see `docs/specs/mcp-endpoint.md` §"Human-approval gate". The admin decision **routes** landed in slice C1b (`GET /api/mcp/approvals`, `POST /api/mcp/approvals/{id}/decision` — see `docs/specs/mcp-endpoint.md` §"Admin decision surface"); a successful `decide()` through them additionally projects a best-effort `mcp.approval_decision` audit event (`McpApprovalDecisionAuditListener`, safe join fields only — request id, decision, optional normalized reason, correlation id, operator uid as actor — never raw arguments; a projection failure is logged and swallowed, never unwinding the already-durable `decided` row). The admin-SPA UI is **not yet present**.

One row per event; status is always derived, never stored:

| Column | Meaning |
|---|---|
| `request_id` | opaque `apr_` + 32 hex (16 random bytes); joins a request's events |
| `event_type` | `requested` \| `decided` \| `consumed` |
| `request_key` | deterministic tuple identity (SHA-256 over a length-unambiguous component encoding); reuse lookup for retried identical calls |
| `principal_key` / `surface` / `operation` / `arguments_fingerprint` | the exact `ApprovalTuple`, repeated on every event so each row is self-describing |
| `correlation_id` | original request's correlation on `requested`/`decided`; the consuming retry's correlation on `consumed` |
| `safe_arguments` | the tool's redacted arguments (`requested` only) — never raw params; the raw arguments exist only as the fingerprint |
| `expires_at` | fixed expiry stamped at open time (`requested` only); UTC `Y-m-d H:i:s.u` |
| `decision` / `operator_uid` | `approved` \| `denied` and the server-derived deciding operator (`decided` only) |
| `decision_reason` | optional operator-supplied human reason (`decided` only) — durable incident evidence; normalized via `ApprovalRequest::normalizeDecisionReason()` (trimmed, blank → null, ≤ 500 Unicode characters, single-line: any ASCII control character rejected before the append). The decided row carries only the decision, operator uid and reason — never request payload or raw arguments |
| `receipt_id` | the strict-ledger receipt of the consuming execution (`consumed` only), joining the approval to its `strict_audit_ledger` evidence |

`UNIQUE(request_id, event_type)` makes both a second decision and a second consumption impossible at the storage layer, independent of the application-level guards. `consume()` runs transactionally (state check + `consumed` append commit together) and re-checks expiry at the consume boundary with the single inclusive instant comparison (`ApprovalRequest::isExpiredAt()`), so there is no sub-second window in which an expired approval still consumes; a concurrent-consumer loss surfaces as `false`, never as a duplicate execution. Duplicate pending rows under a create race are accepted and harmless — later retries converge on the oldest pending request, and each approval still consumes once. Rows are covered by `AuditReadModelDefinitionRegistry` (`id` Public, every other column Internal).

**Pending queue (`listPending()`, C1a).** The operator-facing read side: one bounded `ApprovalRequestPage` of live `Pending` requests in stable ascending requested order (append order of `requested` rows), omitting expired, approved, denied and consumed requests. Limit is 1..100 (default 50); an out-of-range limit or a malformed/tampered cursor throws `\InvalidArgumentException` before any query runs. The cursor is opaque and versioned (`apv1`-tagged, base64url, canonical-form-only): it encodes exactly the immutable row id of the last scanned `requested` event — an append-only position, so it reveals no mutable state and can grant nothing. The scan is one bounded chunk query per page in the common case, never an unbounded `SELECT`: a single `LIMIT`-bounded query over `requested` rows strictly after the cursor position excludes decided/consumed requests via `NOT EXISTS` on the `(request_id, event_type)` unique index and definitely-expired requests via the sortable fixed-width UTC `expires_at` text (which agrees exactly with the inclusive `ApprovalRequest::isExpiredAt()` boundary; the in-PHP derivation keeps the final say per hydrated row). If in-PHP filtering ever leaves a page short with rows still ahead, the store continues from the advanced scan position with further bounded chunks. Traversal is live, not a snapshot: walking `nextCursor` to null visits every continuously-pending request exactly once (skipped non-pending rows can never become pending again — decisions are once-only and expiry is fixed at open time), requests opened between pages appear on a later page, and requests decided or expired between pages stop appearing. Pages carry `safeArguments` only — raw arguments never leave the fingerprint.

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

**Algorithm** (see "Prune reconciliation" above for the sealed/unsealed split):

1. Validate `--older-than` via `new \DateInterval(...)`.
2. Compute `cutoff = now() - interval`.
3. Compute the sealed horizon, the sealed count (`countSealedRowsUpTo`, 0 when
   horizon is 0), and the kind-filtered unsealed-tail count. `real_total =
   sealed_count + unsealed_count`: this is what the two delete paths below
   are about to remove. A legacy kind-filtered `AuditQuery` count is also
   computed and kept as `kind_filtered_match_count`, but it is not used as
   `deleted_count` (audit A7, F10: sealed segments are pruned whole regardless
   of `--kind`, so a kind-filtered count can undercount what Path A deletes).
4. If `--dry-run`: print the sealed/unsealed breakdown and exit 0 (no delete,
   no self-audit event).
5. Without `--confirm`: print `real_total` (with the sealed/unsealed
   breakdown) and refuse; exit 0 (no delete, no self-audit event).
6. Write self-audit event via `$writer->record(AuditEventDescriptor{kind:
   AuditRetentionPruned, attributes: {kind_pattern, older_than, deleted_count:
   real_total, kind_filtered_match_count, cutoff, sealed_pruned_through_id,
   pruned_checkpoint_hash, unsealed_deleted_count}})`.
7. Execute Path A (`audit_event WHERE id <= horizon`, kind-agnostic) when
   `horizon > 0`, marking covered checkpoints `pruned=1`; then execute Path B
   (`created_at < cutoff` plus optional `--kind` filter, applied to the
   unsealed tail: `id > MAX(segment_end_id)` over ALL checkpoints, genesis
   included).
8. Print confirmation (`real_total`) and exit 0.

Self-audit semantics (FR-012): the `audit.retention_pruned` event is recorded
BEFORE the delete so its `deleted_count` reflects the pre-deletion count
(intent, not observed outcome: a mid-delete failure leaves the record
overstating what was removed). Since the audit A7, F10 fix, `deleted_count`
equals the real sealed-plus-unsealed total that the two delete paths are
about to remove, not the kind-filtered match count alone.

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
  token-level check, not a SQL parse: **single-quoted** string literals and
  SQL comments (`--`, `/* */`) are removed, and **identifier quotes are
  UNQUOTED** — the delimiters of `"…"`, `` `…` `` and `[…]` (the three forms
  SQLite accepts) are dropped while the inner name is kept — then a
  word-boundary mutation verb (`UPDATE` / `DELETE` / `DROP` / `ALTER` /
  `TRUNCATE`) co-occurring with a word-boundary append-only table name
  (`audit_event`) throws the **same** `\LogicException` as the builder-level
  guard (shared message factory).
  - **Identifier-quoting bypass closed (#1648, 2026-06-21):** the earlier
    implementation stripped **double-quoted** spans as if they were string
    literals, so `DELETE FROM "audit_event"` (and the `` `audit_event` `` /
    `[audit_event]` / `main."audit_event"` forms) deleted the table name from
    the guard's view and the raw mutation reached the inner database. Unquoting
    rather than stripping identifier delimiters keeps the table name visible to
    the check. Acceptance: `AppendOnlyAuditDatabaseTest::blockedRawSql` now
    includes every quoting form, proven to reach the inner DB before the fix.
  - **Why a decorator and not a database trigger:** the sole sanctioned
    deletion — `audit:prune` — runs through the **raw** `DatabaseInterface`
    builder, so a blanket `BEFORE DELETE` trigger would block retention too.
    Caller discrimination (writer ⇒ decorator ⇒ blocked; prune ⇒ raw ⇒
    allowed) can only live at the decorator layer.
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
- `docs/specs/access-control.md` — `_role: admin` route option, `AccessChecker`.
- `packages/audit/src/AuditServiceProvider.php` — container bindings.
- `packages/api/src/ApiServiceProvider.php` — L4 binding + `resolveOptional` wiring.

<!-- Spec written 2026-05-25 - mission ocap-audit-log-substrate-01KSEFTF WP03: JSON:API audit endpoint + audit:prune CLI + integration tests. Refs gap-matrix-A3, DIR-004. -->
<!-- Spec reviewed 2026-06-09 - alpha.202: audit_event/audit_retention_policy de-registered as content entities (now raw OCAP log tables + typed read models); append-only enforcement moved from the dormant AppendOnlyDriverGuard to the active AppendOnlyAuditDatabase DatabaseInterface decorator; writer migrated to insert-only raw INSERT. Refs #1625. -->
<!-- Spec reviewed 2026-06-12 - mission revision-audit-provenance-01KTWY5V WP05: actor_uid authoritative three-state column (+index, guarded ALTER migration; account_uid retained as legacy actor??0); AuditEventDescriptor::$accountUid widened to ?int; AuditEvent::getActorUid(); taxonomy 17→19 (revision.publish / revision.revert); listener catalogue gains PublishPointerAuditListener + per-listener actor-source table (entity lifecycle no longer reads the entity's uid field; agent tools no longer hardcode 0); AppendOnlyAuditDatabase::query() raw-SQL guard documented (literal/comment stripping, conjunctive verb+table match, fail-closed residuals, structural NFR-003 argument). Refs #1645, #1648. -->
<!-- Spec reviewed 2026-06-21 - #1648 identifier-quoting bypass closed: AppendOnlyAuditDatabase::query() previously stripped DOUBLE-quoted spans as string literals, so `DELETE FROM "audit_event"` (and the backtick/bracket/schema-qualified forms SQLite accepts) erased the table name from the guard's view and the raw mutation reached the inner DB (proven: those statements raise TableNotFoundException from the engine, not LogicException, under the old code). Fixed by `normalizeSqlForGuard()` — single-quoted literals + comments are removed, but identifier quotes (`"…"`, backtick, `[…]`) are UNQUOTED (delimiters dropped, inner name kept) so a quoted append-only table name is still matched. The decorator remains the enforcement layer by design (a blanket DB trigger would also block the raw-connection `audit:prune` retention path). Acceptance: AppendOnlyAuditDatabaseTest (every quoting form in blockedRawSql; a quoted-name SELECT stays in allowedRawSql to prove no false positive). -->
