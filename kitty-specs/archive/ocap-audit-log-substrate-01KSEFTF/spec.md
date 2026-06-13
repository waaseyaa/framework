# OCAP Audit Log Substrate — Unified Append-Only Audit Table + Query API

**Mission:** `ocap-audit-log-substrate-01KSEFTF`
**Target branch:** `main`
**Tracks:** No GitHub issue (Wave 2 framework substrate). Closes gap-matrix row **A3**; alpha-to-beta-plan §1 substrate item **#2**. Charter directives: **DIR-004** (OCAP-by-architecture), **DIR-005** (two-axis storage preservation), **DIR-006** (codified gates).
**Pattern reference (CANONICAL):** `CodifiedContextController` + `CodifiedContextSessionStoreInterface` (the cross-layer L5/L6 → L4 read-contract pattern documented in `docs/specs/codified-context-integration.md`). M5A `AiObservabilityReadModelInterface` for the read-model shape (`packages/api/src/AiObservability/`). M4B `QueueController` / `QueueAdminApiRouter` for the JSON:API router shape.

## Why this mission exists

Today every Waaseyaa subsystem logs in its own dialect. `entity` lifecycle events (`EntityEvent` — production), `engagement` activity (alpha), `ai-agent` `AgentAuditLog` (append-only, beta), `telescope` HTTP/DB/cache/queue (alpha), `mercure` broadcast publication (alpha) — they each fire their own events, write their own tables, and offer no unified query surface. An OCAP audit posture (DIR-004) requires a **single, append-only, query-able** record spanning read / write / export / access-denied / classification-change / retention events. Nations procuring Waaseyaa-based distributions need one audit endpoint, one retention policy, one access-controlled viewer — not eight subsystem-specific exports stitched together at incident-review time.

This mission is the cross-cutting plumbing layer. The events already exist; what's missing is (a) a canonical schema, (b) listeners that translate subsystem events into canonical audit records best-effort, (c) a read-side query API filterable by account / entity / kind / date-range, and (d) a CLI prune command honouring a retention policy that is itself audit-logged. Three Wave 2+ missions (`classification-retention-engine-*`, `versioned-blob-media-abstraction-*`, `offline-first-sync-substrate-*`) compose on this substrate; the M-A5 flagship `per-record-ai-access-flagship-*` depends on it for tool-execution audit trails.

## The package decision

Per the inventory in `gap-matrix.md` row F3, `packages/analytics` is a skeleton (`1/0 src/tests` — only `UmamiClient.php`). It carries the `analytics` name but no implementation, no domain model, and no consumers. This mission **renames and repurposes `analytics` → `audit`** as one of WP01's tasks. The single existing file (`UmamiClient.php`) is deleted (Greenfield Removal Policy, charter directive 3); a follow-up note in the mission report flags that any future Umami-style web-analytics work is greenfield and unrelated to this substrate. The fate of `analytics` as a name is coordinated with the (separately-filed) `empty-package-decisions-*` mission — this mission is the authoritative event that consumes the slot.

## Scope

### In scope

**Layer 0 — `packages/audit` (renamed from `packages/analytics`):**
- `AuditEvent` entity (`extends ContentEntityBase`) — append-only semantics enforced by storage driver (no UPDATE / DELETE paths exposed). Entity keys: `id` (autoinc), `uuid`, `created_at`.
- `AuditEventType` registered via `EntityTypeManager` in `AuditServiceProvider::register()`.
- Schema (single table — NOT two-axis; audit events are never revised or translated): `id`, `uuid`, `event_kind`, `account_uid`, `entity_type_id`, `entity_uuid`, `subject_uri`, `outcome` (`allowed` | `denied` | `error`), `severity` (`info` | `notice` | `warning`), `attributes` (JSON), `created_at`. Indices: `(account_uid)`, `(entity_type_id, entity_uuid)`, `(event_kind, created_at)`, `(created_at)`.
- `AuditEventKind` enum (string-backed): `entity.read`, `entity.write`, `entity.delete`, `entity.export`, `access.denied`, `classification.change`, `retention.purge`, `retention.redact`, `retention.hold`, `agent.tool_execute`, `mcp.dispatch`, `broadcast.publish`, `api.request`, `audit.retention_pruned` (self-referential — audit-log prunes are themselves logged).
- `AuditQueryInterface` (read-side contract, `@api`): `findBy(AuditQuery $query): iterable<AuditEvent>`, `count(AuditQuery $query): int`. `AuditQuery` value object: `accountUid?`, `entityType?`, `entityUuid?`, `kinds?: AuditEventKind[]`, `from?`, `to?`, `limit`, `offset`.
- `AuditWriterInterface` (write-side contract, `@api`): `record(AuditEventDescriptor $descriptor): void`. **Best-effort**: implementations MUST swallow exceptions and log via `LoggerInterface` (per CLAUDE.md §Logging "best-effort side effects" gotcha) so a failing audit write never crashes the primary request.
- `AuditRetentionPolicy` entity — minimal at this layer: `id`, `kind_pattern` (glob, e.g. `entity.read`, `entity.*`, `*`), `older_than_seconds`, `action` (always `purge` at this layer; redact/hold are owned by the classification-retention mission), `created_at`. Classification-driven retention composes on top — see Decisions deferred.
- `AuditServiceProvider` binds `AuditWriterInterface` → `AuditEventWriter`, `AuditQueryInterface` → `AuditEventQuery`. Provider FQCN declared in `composer.json` `extra.waaseyaa.providers`.

**Layer 4 — `packages/api`:**
- `Api\Audit\AuditQueryReadModelInterface` (`packages/api/src/Audit/`) — api-local interface that the controller depends on. Cross-layer pattern: `audit` (L0) is **lower** than `api` (L4), so api MAY import `audit` types directly via `require` — but per the CodifiedContext pattern (and to keep optional-adapter symmetry with M5A), the controller depends on its **own** namespaced interface and `audit` registers an adapter. This keeps `bin/check-package-layers` green even if a future distribution chooses to provide its own audit backend.
- `Api\Audit\AuditEventResource` — JSON:API resource DTO.
- `Controller\AuditQueryController::index(): array` — `GET /api/audit/events?account=&entity=&kind=&from=&to=&limit=&offset=` returns paginated JSON:API. `_role: admin` at route level. Nullable read-model → empty-shape payload when audit is absent or disabled.
- `Http/Router/AuditApiRouter` — mirror `WorkflowGuardsApiRouter`. JSON:API error envelope.
- `ApiServiceProvider::httpDomainRouters()` adds the resolveOptional + wire block.
- `packages/api/composer.json` adds `"waaseyaa/audit": "<exact-tag>"` to **require-dev** + path repo for `../audit`.

**Layer 0 — `packages/foundation`:**
- `BuiltinRouteRegistrar` — `api.audit.events.index` → `GET /api/audit/events`, `_role: admin`, string FQCN.

**Cross-cutting event listeners (`packages/audit/src/Listener/`):**
- `EntityLifecycleAuditListener` — subscribes to existing `EntityEvent` (`EntityCreated`, `EntityUpdated`, `EntityDeleted` if defined; otherwise the canonical `entity.created` / `entity.saved` / `entity.deleted` events). Records `entity.write` / `entity.delete`. Best-effort.
- `ApiRequestAuditListener` — middleware-tagged listener (priority below `AuthorizationMiddleware`) recording `entity.read` for `GET` requests on entity routes and `entity.export` for the export endpoints the api package exposes. Records `access.denied` when `AuthorizationMiddleware` returns 403 (uses request attribute `_account` per CLAUDE.md gotcha — never `account`).
- `AgentToolAuditListener` — subscribes to `AgentToolExecuted` (from `packages/ai-agent`) → `agent.tool_execute` with tool name + outcome in attributes. Coordinates with the M-A5 flagship mission — listener exists here so M-A5 only needs to dispatch its own canonical event.
- `McpDispatchAuditListener` — subscribes to MCP JSON-RPC dispatch event (from `packages/mcp`) → `mcp.dispatch`.
- `BroadcastAuditListener` — subscribes to broadcast publication (from `packages/foundation/Http/Inbound`) → `broadcast.publish` (severity `notice`, attributes contain channel + message id; NOT the payload).
- All listeners are auto-discovered via `EventListenerRegistrar` (per CLAUDE.md orchestration table). Each listener wraps its body in try-catch and logs to `LoggerInterface` on failure (NullLogger default per CLAUDE.md gotcha).

**Layer 6 — `packages/cli`:**
- `Command\Audit\PruneCommand` — `bin/waaseyaa audit:prune --older-than=<duration> [--kind=<glob>] [--dry-run]`. Reads `AuditRetentionPolicy` entities + CLI flags, deletes matching `AuditEvent` rows. Every prune batch writes one `audit.retention_pruned` self-audit record before the DELETE so the audit-log's own retention is itself audited.

**Tests:**
- Contract tests in `packages/audit/tests/Contract/` for `AuditQueryInterface` + `AuditWriterInterface` (`#[CoversNothing]`, abstract base + DBAL-SQLite concrete).
- Unit tests for each listener: fire the subscribed event with a fake dispatcher; assert one canonical audit record written; assert listener swallows exceptions (failed writer → no exception bubbles up; warning logged).
- Integration test `tests/Integration/PhaseOcapAudit/OcapAuditEndpointTest.php` (`#[CoversNothing]`) — boot full kernel, seed events across 4+ kinds and 2+ accounts, `GET /api/audit/events` as admin → assert pagination + filter correctness; as non-admin → 403.
- Integration test `tests/Integration/PhaseOcapAudit/AuditRetentionPruneTest.php` — seeds events; runs `audit:prune --older-than=PT1H`; asserts older rows deleted; asserts one `audit.retention_pruned` record created.

**Docs:**
- `docs/specs/ocap-audit-log.md` — new spec file documenting the schema, event-kind taxonomy, listener catalogue, query API, retention semantics. Cross-referenced from CLAUDE.md orchestration table (`packages/audit/*` → this spec).
- `docs/specs/codified-context-integration.md` — append cross-reference note: audit is a cross-layer surface following the L0→L4 read-contract symmetry.
- `CLAUDE.md` orchestration table — add `packages/audit/*` row pointing at the new spec. Layer table — add `audit` to Layer 0 row.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

### Out of scope (→ separate missions)

- **Classification-driven retention** (label-aware purge, redaction, hold flags) → `classification-retention-engine-01KSEFTH` (this cluster's next mission). This mission ships a kind-pattern + age-based retention policy entity; classification-pattern + label-driven actions are appended in the retention mission.
- **Admin SPA audit explorer page** → deferred to the M-A5 flagship `per-record-ai-access-flagship-*` mission (the explorer is its primary surface). This mission ships the JSON:API endpoint only.
- **Audit-log viewer mode in telescope** → telescope hardening (alpha-to-beta-plan §1) — separate mission.
- **OIDC-issued externally-signed audit records** → deferred. Internal-only at this layer.
- Removal of subsystem-specific logs (`AgentAuditLog`, `engagement` activity, etc.). They keep firing their own events; the new listeners observe them in parallel. Consolidation is a later mission.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | `packages/analytics` is renamed/repurposed to `packages/audit` with PSR-4 namespace `Waaseyaa\Audit\`. The existing `UmamiClient.php` file is deleted (Greenfield Removal Policy). `composer.json`, namespace, autoload, layer table entry (Layer 0), and orchestration-table row all reference `audit`. |
| FR-002 | functional | `AuditEvent` entity is registered via `AuditServiceProvider::register()`. Schema columns and indices match the In-Scope §`packages/audit` block exactly. Storage driver is the framework's `SqlStorageDriver` over the canonical `DatabaseInterface` per `.claude/rules/entity-storage-invariant.md`. |
| FR-003 | functional | `AuditEvent` storage is append-only: the storage driver MUST reject `update()` and `delete()` calls with `\LogicException`. The only legal mutation path is `record()` (insert) via `AuditWriterInterface`, plus the bulk-delete invoked by `audit:prune` which goes through `DatabaseInterface::delete()` (NOT the entity repository). |
| FR-004 | functional | `AuditEventKind` enum declares exactly the 14 canonical kinds enumerated in In-Scope. Adding a new kind requires editing this enum (new kinds outside the enum MUST be rejected by `AuditEventDescriptor` validation). |
| FR-005 | functional | `AuditWriterInterface::record()` is best-effort: implementations MUST NOT throw. On internal failure they log via injected `LoggerInterface` (default `NullLogger`) and return. Verified by unit tests injecting a writer whose underlying repository throws — the surrounding listener body MUST complete normally. |
| FR-006 | functional | `EntityLifecycleAuditListener`, `ApiRequestAuditListener`, `AgentToolAuditListener`, `McpDispatchAuditListener`, `BroadcastAuditListener` are auto-discovered via `EventListenerRegistrar` (per CLAUDE.md orchestration table — `packages/foundation/src/Kernel/EventListenerRegistrar.php`). Each listener's body is wrapped in try-catch; on failure it logs and returns without re-throwing. |
| FR-007 | functional | `ApiRequestAuditListener` reads the authenticated account via request attribute `_account` (per CLAUDE.md gotcha "Request attribute is `_account` not `account`"). Anonymous traffic resolves to `AnonymousUser` (uid `0`) — listener records the read with `account_uid = 0`, not null. |
| FR-008 | functional | `AuditQueryInterface::findBy()` supports filtering by account, entity, kind, date-range, limit, offset. Returns events ordered by `created_at DESC, id DESC` (stable). `count()` returns the unpaginated total. |
| FR-009 | functional | `GET /api/audit/events` route is registered in `BuiltinRouteRegistrar` with `_role: admin`, string FQCN `'Waaseyaa\\Api\\Controller\\AuditQueryController::index'`. JSON:API media type. Controller does NOT re-check role. Pagination via JSON:API `page[limit]` / `page[offset]` query params; default limit 50, max limit 500. |
| FR-010 | functional | `AuditQueryReadModelInterface` lives in `packages/api/src/Audit/`. `Waaseyaa\Audit\Adapter\ApiAuditQueryAdapter` (in `packages/audit`) implements it. Binding happens in `AuditServiceProvider`; `ApiServiceProvider::httpDomainRouters()` uses `resolveOptional` + wires the router only when bound. Null read-model → controller returns `{data: [], meta: {total: 0}}`. |
| FR-011 | functional | `bin/waaseyaa audit:prune --older-than=<duration>` accepts an ISO-8601 duration (`PT24H`, `P30D`, `P1Y`). Optional `--kind=<glob>` (default `*`) restricts by event kind pattern. Optional `--dry-run` prints would-be count without deleting. Every executed prune (non-dry-run) writes one `audit.retention_pruned` self-audit event before the DELETE statement, with attributes `{kind_pattern, older_than, deleted_count}`. |
| FR-012 | functional | `AuditRetentionPolicy` entity is registered and is itself audit-logged on create/update/delete (its lifecycle fires through `EntityLifecycleAuditListener`, so changes to retention policy land in the audit log automatically). |
| FR-013 | functional | A **kernel-boot integration test** (`OcapAuditEndpointTest`) boots the full kernel, seeds ≥6 events across ≥4 kinds and ≥2 accounts, hits `GET /api/audit/events?kind=entity.read` as admin → asserts only matching events returned with correct ordering and pagination metadata; hits as a non-admin → 403. This is the FR-013 dead-code guard — it MUST fail if `AuditServiceProvider` does not bind `AuditQueryReadModelInterface`. |
| FR-014 | functional | A **prune integration test** (`AuditRetentionPruneTest`) seeds 10 events with mixed `created_at` timestamps, runs `audit:prune --older-than=PT1H` via `CommandTester`, asserts older rows deleted, asserts one `audit.retention_pruned` record exists with `attributes.deleted_count` matching the actual deletion count. |
| FR-015 | functional | `docs/specs/ocap-audit-log.md` is created and cross-referenced from CLAUDE.md orchestration table (`packages/audit/*` row) and the layer table (Layer 0). `CHANGELOG.md` `[Unreleased]` → **Added** records the new substrate. |
| NFR-001 | non-functional | Audit writes MUST NOT raise on failure (FR-005) — the audit log must never be the cause of a 500. Verified by unit tests + a kernel-boot smoke that intentionally breaks the audit table (`ALTER TABLE audit_event RENAME TO audit_event_broken`) and confirms a normal `GET /api/entity/...` request still returns 200. |
| NFR-002 | non-functional | Cross-layer wiring honours DIR-004 + DIR-005: api (L4) MUST NOT `use Waaseyaa\Audit\*` symbols in `src/` (only in `tests/` and `composer.json` require-dev). The adapter pattern (FR-010) is the only legal cross-layer wiring. `bin/check-package-layers` stays green. |
| NFR-003 | non-functional | Storage uses `DatabaseInterface::select/insert/delete` query builder (NOT `getQuery()` chains) per CLAUDE.md §Architecture-Gotchas. Any new `getQuery()` call site must include `setAccount($account)` or `accessCheck(false)` with an inline justification comment, or the `bin/check-getquery-bindings` CI gate will fail. |
| NFR-004 | non-functional | `AuditEvent` entity, `AuditEventKind` enum, `AuditQueryInterface`, `AuditWriterInterface`, and all five listener classes carry class-level `@api` PHPDoc (per CLAUDE.md §Dead-code "Marking intentional scaffolding" — these are the public extension points). |
| NFR-005 | non-functional | Performance: the canonical 6-event seed for FR-013 + a 1000-event synthetic dataset must return `GET /api/audit/events?account=2&limit=50` in < 100ms on the test SQLite database. Indices declared in FR-002 are the mechanism. (Not a blocker for merge — documented as a perf-budget note in the spec; production perf is a later observability concern.) |
| C-001 | constraint | Single-table append-only schema. No two-axis storage on audit events (DIR-005 governs entity data; audit events are immutable historical facts, never revised or translated). |
| C-002 | constraint | Cross-layer wiring MUST follow the CodifiedContext pattern (api-local interface + adapter in the lower layer). No direct `use Waaseyaa\Audit\*` in `packages/api/src/`. |
| C-003 | constraint | All listener bodies are best-effort try-catch wrapped. Failing audit write never crashes the primary request. |
| C-004 | constraint | The `packages/analytics` rename is a single mission task (WP01). No parallel work touching `packages/analytics` may be in flight; coordinate with `empty-package-decisions-*` mission (which lists `analytics` as one of its decision rows) by landing this mission first and updating that mission's row to "consumed by ocap-audit-log-substrate-01KSEFTF". |
| C-005 | constraint | The retention policy in this mission is age + kind-pattern only. Classification-driven retention (label-aware purge / redaction / hold) is explicitly deferred to `classification-retention-engine-01KSEFTH` and composes on top by reading the same `AuditRetentionPolicy` entity and extending its behaviour, NOT by replacing the policy entity. |

## Acceptance

- All 15 FRs and 5 NFRs met; all 5 constraints honoured.
- Gates green: `vendor/bin/phpunit`, `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-getquery-bindings`, `bin/check-composer-policy`.
- `rg -n 'Waaseyaa\\\\Analytics' .` returns no matches (rename is total).
- `rg -n 'Waaseyaa\\\\Audit' packages/api/src/` returns no matches (NFR-002 / C-002).
- Integration tests `OcapAuditEndpointTest` + `AuditRetentionPruneTest` pass.
- NFR-001 smoke: a `GET /api/entity/...` request completes 200 with the audit table renamed (audit writes fail-silently, logger emits a warning).
- `bin/waaseyaa audit:prune --older-than=PT1H --dry-run` runs and prints the count.
- Reviewer (Opus) confirms the dead-code guard (FR-013) fails when the `ApiAuditQueryAdapter` binding is removed.

## Risks

- **Audit-log becomes the bottleneck.** Every entity read + every API request now writes a row. Mitigation: indices declared at WP01 (FR-002); NFR-005 documents a 1000-event smoke budget; production batching/async queueing is a documented follow-up (NOT in scope). Best-effort listeners (FR-005) ensure a slow audit write never blocks the request.
- **Schema lock-in.** Renaming `analytics` → `audit` is irreversible; reviewers should grep for any external consumer of `Waaseyaa\Analytics` before merge. Mitigation: the inventory check in WP01-T01 is a hard gate.
- **Listener event-name drift.** If `EntityEvent` event names change in `packages/entity`, the lifecycle listener silently stops recording. Mitigation: contract test in `packages/audit/tests/Contract/EntityLifecycleAuditContractTest.php` asserts the canonical events; the contract test is the single source of truth that the audit substrate breaks loudly when entity-system events drift.
- **OCAP scope creep.** A reviewer may want classification-aware retention here. Mitigation: C-005 declares that as `classification-retention-engine-*` scope — escalate to Russell if pushback materialises rather than expanding scope.

## Decisions pre-resolved

- **Package rename: `analytics` → `audit`.** Documented in "The package decision" above. Avoids the alternative of carrying a third name (`waaseyaa/audit-log`) into a Layer 0 package while leaving `analytics` as a zombie.
- **Append-only enforced at the storage driver, not the database.** SQLite + MySQL + PostgreSQL don't share an append-only DDL; enforcement in PHP (LogicException on update/delete) is portable and testable.
- **Read-model + adapter pattern even though `audit` is Layer 0.** Symmetry with M5A and CodifiedContext. Keeps `bin/check-package-layers` posture identical across L0, L5, L6 cross-layer surfaces. The cost is one extra interface; the benefit is that a Nation can swap in their own audit backend without touching `packages/api`.
- **Listeners ship in `packages/audit`, not in each subsystem.** Subsystems keep their own events; audit observes them. Avoids forcing `packages/entity` / `packages/ai-agent` / `packages/mcp` / `packages/foundation` to take a dependency on `packages/audit`. The DI for the dispatcher already supports adding listeners from any package.
- **Retention policy is an entity, not a config blob.** Entities get the full OCAP treatment (their lifecycle is audit-logged — FR-012), which is what we want for a policy that governs the audit log itself.

## Decisions deferred to implementer

- **Adapter binding strategy when `observability.enabled = false` analogue is needed.** This mission does NOT propose an `audit.enabled` gate; the audit log is constitutional (DIR-004). If a deployer needs to disable it (test environments, ephemeral debugging), they can override the `AuditWriterInterface` binding with a `NullAuditWriter` in their app's service provider — implementer should ship a `NullAuditWriter` class in `packages/audit/src/` for this purpose, marked `@api`.
- **JSON:API filter syntax for `kind` filter.** JSON:API spec is loose. Implementer chooses between `?filter[kind]=entity.read,entity.write` (comma-list) and `?kind=entity.read&kind=entity.write` (repeated param). Prefer comma-list (less verbose). Document the chosen form in `docs/specs/ocap-audit-log.md`.
- **Whether to ship a default retention policy seeded on first boot.** Defer; ship the substrate, let the classification-retention mission seed the canonical defaults when it lands.

Decision preference order per DIR-006 governance: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- This mission MUST merge before `classification-retention-engine-01KSEFTH` enters implementation (that mission depends on `AuditWriterInterface` for `retention.purge` / `retention.redact` / `retention.hold` records).
- This mission SHOULD merge before `versioned-blob-media-abstraction-01KSEFTJ` enters implementation (that mission appends `media.version.created` and `media.version.read` event kinds — the enum must be extensible by amendment, which is easier on a freshly-merged substrate).
- This mission MUST merge before `offline-first-sync-substrate-01KSEFTM` enters WP04 (offline-write audit hooks).
- No follow-up issue required. If production performance shows audit writes becoming a hot path, file a "batched / async audit writer" follow-up at that time; the best-effort listener wrapper means it's a swap-the-writer-binding job, not a substrate redesign.
