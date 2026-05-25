# Implementation Plan: OCAP Audit Log Substrate

**Mission:** `ocap-audit-log-substrate-01KSEFTF` — see `spec.md`.
**Pattern references (READ FIRST):** `docs/specs/codified-context-integration.md` (the cross-layer L0↔L4 read-contract pattern); `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `AiObservabilityController.php` + `ObservabilityServiceProvider.php` (M5A shipped; this mission mirrors the shape); `packages/api/src/Http/Router/WorkflowGuardsApiRouter.php` (router shape); `packages/foundation/src/Kernel/EventListenerRegistrar.php` (listener auto-discovery); `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (route registration); `.claude/rules/entity-storage-invariant.md` (canonical persistence pipeline).

**Three WPs.** WP01 ships the substrate (package rename, entity, schema, writer, query, retention policy entity, foundation route, `@api` markers). WP02 ships the five cross-cutting listeners. WP03 ships the JSON:API endpoint + CLI prune command + integration tests. WP02 and WP03 may proceed in parallel after WP01 lands and is approved.

## WP01 — Substrate: rename `analytics` → `audit`, entity + schema + writer + query + retention policy

### Package rename — atomic block (T-A)
- `git mv packages/analytics packages/audit`. Delete `packages/audit/src/UmamiClient.php` (Greenfield Removal Policy, charter directive 3 — no consumers, no value).
- Rewrite `packages/audit/composer.json`: `name: "waaseyaa/audit"`, namespace `Waaseyaa\\Audit\\`, `extra.waaseyaa.providers: ["Waaseyaa\\\\Audit\\\\AuditServiceProvider"]`. Pin internal `waaseyaa/*` constraints to the current tag literal per CLAUDE.md §Project-Structure rule CP-NEW (run `bin/sync-internal-versions` if the literal needs to advance).
- Update root `composer.json` workspace entry, every metapackage referring to `waaseyaa/analytics` (`packages/core`, `packages/cms`, `packages/full`), and `bin/check-package-layers` allowlist.
- Update CLAUDE.md: Layer-table Layer-0 row (replace `analytics` with `audit`); orchestration table (new `packages/audit/*` row pointing at `docs/specs/ocap-audit-log.md`); `packages/analytics/*` row removed.
- Run `composer dump-autoload` and `vendor/bin/phpunit --testsuite Unit` to baseline.
- Verify no consumers leak: `rg -n 'Waaseyaa\\\\Analytics' .` → empty; `rg -n '"waaseyaa/analytics"' .` → empty.

### Entity + schema (T-B)
- `packages/audit/src/Entity/AuditEvent.php` `extends ContentEntityBase`. `__construct(array $values = [])` hardcodes `entity_type_id = 'audit_event'` and `entity_keys = ['id' => 'id', 'uuid' => 'uuid']`. Class-level `@api`.
- `packages/audit/src/Entity/AuditEventType.php` — `EntityType` value. Properties: `event_kind` (string, enum-backed), `account_uid` (int, nullable), `entity_type_id` (string, nullable), `entity_uuid` (string, nullable), `subject_uri` (string, nullable), `outcome` (enum: `allowed`|`denied`|`error`), `severity` (enum: `info`|`notice`|`warning`), `attributes` (array, JSON-encoded), `created_at` (datetime).
- `packages/audit/src/Enum/AuditEventKind.php` — string-backed enum with the 14 cases enumerated in spec.md §In-scope. Class-level `@api`.
- `packages/audit/src/Schema/AuditEventSchemaHandler.php` — uses `SqlSchemaHandler` to build the `audit_event` table per spec.md columns + indices. Migration file `packages/audit/migrations/2026_05_25_000001_create_audit_event_table.php`.
- `packages/audit/src/Entity/AuditRetentionPolicy.php` — extends `ContentEntityBase`. Properties: `kind_pattern` (string), `older_than_seconds` (int), `action` (enum: `purge`), `created_at` (datetime). Table `audit_retention_policy`. Class-level `@api`. (Entity lifecycle of this entity is itself audit-logged via the listener — FR-012.)

### Write-side contract + append-only enforcement (T-C)
- `packages/audit/src/Contract/AuditWriterInterface.php` — `record(AuditEventDescriptor $descriptor): void`. Class-level `@api`.
- `packages/audit/src/Contract/AuditEventDescriptor.php` — readonly value DTO mirroring the schema columns; validates `event_kind` against `AuditEventKind::tryFrom()` in constructor, throws `\InvalidArgumentException` on unknown kind.
- `packages/audit/src/Writer/AuditEventWriter.php` `implements AuditWriterInterface`. Constructor takes `EntityRepositoryInterface $repo` (for `audit_event`) and `?LoggerInterface $logger = null` (default `NullLogger`). `record()` body: try → build `AuditEvent`, `$repo->save($entity)`; catch `\Throwable $e` → `$this->logger->warning('audit.write_failed', ['event_kind' => $descriptor->eventKind, 'error' => $e->getMessage()])`; return. NEVER re-throws (FR-005, NFR-001).
- `packages/audit/src/Writer/NullAuditWriter.php implements AuditWriterInterface` — silent no-op; class-level `@api`. Documented as the disable-audit-writes override for test environments.
- `packages/audit/src/Storage/AppendOnlyDriverGuard.php` — wraps the entity storage driver. `update()` and `delete()` throw `\LogicException('audit_event is append-only; use AuditWriterInterface::record() or audit:prune CLI for bulk delete.')`. `save()` delegates to the underlying driver only when `isNew()` is true.

### Read-side contract + query (T-D)
- `packages/audit/src/Contract/AuditQueryInterface.php` — `findBy(AuditQuery $query): iterable<AuditEvent>`, `count(AuditQuery $query): int`. Class-level `@api`.
- `packages/audit/src/Contract/AuditQuery.php` — readonly value: `accountUid?: int`, `entityType?: string`, `entityUuid?: string`, `kinds?: AuditEventKind[]`, `from?: \DateTimeImmutable`, `to?: \DateTimeImmutable`, `limit: int = 50`, `offset: int = 0`.
- `packages/audit/src/Query/AuditEventQuery.php implements AuditQueryInterface`. Uses `DatabaseInterface::select()` query builder per CLAUDE.md §Architecture-Gotchas (NEVER `getQuery()` — no new entries in `tools/getquery-bindings-baseline.txt`). Ordering: `created_at DESC, id DESC`.

### Service provider + foundation route (T-E)
- `packages/audit/src/AuditServiceProvider.php` `extends ServiceProvider`. `register()` binds `AuditWriterInterface` → `AuditEventWriter`; binds `AuditQueryInterface` → `AuditEventQuery`; registers `AuditEvent` + `AuditRetentionPolicy` entity types via `EntityTypeManager`; binds string FQCN `'Waaseyaa\\Api\\Audit\\AuditQueryReadModelInterface'` → `ApiAuditQueryAdapter` (the adapter ships in T-G below, but the binding lives here per the CodifiedContext pattern). `boot()` registers schema with `SqlSchemaHandler`.
- `packages/audit/src/Adapter/ApiAuditQueryAdapter.php` — implements the **string FQCN** `'Waaseyaa\\Api\\Audit\\AuditQueryReadModelInterface'`. Uses string-class `implements` syntax NOT possible in PHP — implementer instead declares `implements \Waaseyaa\Api\Audit\AuditQueryReadModelInterface` (cross-layer, audit (L0) MAY import api (L4) types since the layer rule reads "lower may not import higher" — but wait: api is L4 and audit is L0, audit is LOWER. **Reconsider:** L0 importing from L4 violates the layer rule. Implementer's task: structure the adapter so it lives in a separate package or in `packages/api` itself, OR introduce an api-namespaced trait/interface that audit can wire by string. Decision deferred to implementer with preference: ship the adapter inside `packages/api/src/Audit/` (alongside the interface), constructed with an audit-side `AuditQueryInterface` dependency — this preserves the layer invariant. **Reviewer: confirm this is the chosen approach.**
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — add `api.audit.events.index` → `GET /api/audit/events`, `->requireRole('admin')`, `->methods('GET')`, controller string FQCN `'Waaseyaa\\Api\\Controller\\AuditQueryController::index'`. Placement: after the workflow-guards block (mirror M5A's positional convention).

### Tests for WP01 (T-F)
- `packages/audit/tests/Contract/AuditWriterContractTest.php` (`#[CoversNothing]`, abstract) — covered by `AuditEventWriterTest` (concrete, SQLite via `DBALDatabase::createSqlite()`).
- `packages/audit/tests/Contract/AuditQueryContractTest.php` (abstract) — covered by `AuditEventQueryTest` (concrete, SQLite seed).
- `packages/audit/tests/Unit/Writer/AuditEventWriterBestEffortTest.php` — inject a `EntityRepositoryInterface` that throws; assert `record()` returns void without throwing; assert one `warning` log line.
- `packages/audit/tests/Unit/Storage/AppendOnlyDriverGuardTest.php` — assert `update()` / `delete()` throw `\LogicException` with the canonical message.

## WP02 — Listeners: entity lifecycle, API request, agent-tool, MCP dispatch, broadcast

Each listener lives in `packages/audit/src/Listener/`, accepts `AuditWriterInterface` (+ `?LoggerInterface`) in constructor, wraps the entire body in try-catch. Auto-discovered via `EventListenerRegistrar` per CLAUDE.md orchestration table.

### EntityLifecycleAuditListener (T-G)
- Subscribes to canonical entity-lifecycle events (`entity.created`, `entity.saved`, `entity.deleted` — verify exact event-name constants in `packages/entity/src/Event/`). Records `entity.write` for created/saved; `entity.delete` for deleted. Resolves account via `EntityEvent::$context['_account']` or via the request context. `subject_uri` = `/entities/{type}/{uuid}`. Attributes contain `entity_type` + the changed-field list (best-effort — use `getDirty()` if available, else skip).
- Unit test fires each event with a fake dispatcher; asserts one canonical audit record written.

### ApiRequestAuditListener (T-H)
- Implemented as `HttpMiddlewareInterface` with `#[AsMiddleware(priority: -50)]` (runs **after** `AuthorizationMiddleware` so it sees the resolved `_account` and the response status). Reads `_account` from request attributes per CLAUDE.md gotcha (NEVER `account` — silent null). Records `entity.read` for `GET` on entity routes; `entity.export` for the api export endpoints (detect via route name pattern `api.*.export`); `access.denied` when response status is 403. Anonymous traffic uses `AnonymousUser` uid `0`.
- Unit test using `Symfony\Component\HttpFoundation\Request` + a fake handler.

### AgentToolAuditListener (T-I)
- Subscribes to `AgentToolExecuted` event (verify FQCN in `packages/ai-agent/src/Event/`). Records `agent.tool_execute` with attributes `{tool: $event->toolName, outcome: $event->outcome, durationMs: $event->durationMs}`. Coordinates with `per-record-ai-access-flagship-*` mission (which will dispatch its own canonical event for per-record AccessChecker decisions inside `AgentToolInterface::execute()`).

### McpDispatchAuditListener (T-J)
- Subscribes to MCP JSON-RPC dispatch event (verify FQCN in `packages/mcp/src/Event/`). Records `mcp.dispatch` with attributes `{method, params_hash}` — NEVER stores raw params (PII / classified data exposure).

### BroadcastAuditListener (T-K)
- Subscribes to broadcast publication event (verify FQCN in `packages/foundation/src/Http/Inbound/` or wherever the broadcaster fires from). Records `broadcast.publish` severity `notice` with attributes `{channel, message_id}` — NEVER the payload.

### NFR-001 chaos test (T-L)
- `packages/audit/tests/Integration/AuditChaosTest.php` (`#[CoversNothing]`) — boots full kernel; `ALTER TABLE audit_event RENAME TO audit_event_broken`; performs `GET /api/entity/...` request; asserts 200 response (audit writes fail silently); asserts a `warning` log line emitted.

## WP03 — API endpoint, CLI prune, dead-code guard

### API endpoint (T-M)
- `packages/api/src/Audit/AuditQueryReadModelInterface.php` — `findBy(AuditQuery $query): iterable<AuditEventResource>`, `count(AuditQuery $query): int`. Api-local; `@api`. No `use Waaseyaa\Audit\*` — DTOs are api-local.
- `packages/api/src/Audit/AuditEventResource.php` — readonly DTO mirroring the audit-event shape with JSON:API attributes camelCase. `@api`.
- `packages/api/src/Audit/AuditQueryDto.php` — query value object (api-local, separate from `Waaseyaa\Audit\Contract\AuditQuery` to keep cross-layer clean).
- `packages/api/src/Controller/AuditQueryController.php` — `__construct(private readonly ?AuditQueryReadModelInterface $readModel = null)`. `index(Request $request): array` parses `page[limit]`, `page[offset]`, `filter[account]`, `filter[entity]`, `filter[kind]` (comma-list), `filter[from]`, `filter[to]`; builds `AuditQueryDto`; returns `{data: [...AuditEventResource], meta: {total, limit, offset}}`. Nullable read-model → `{data: [], meta: {total: 0, limit: 50, offset: 0}}`. Does NOT re-check role.
- `packages/api/src/Http/Router/AuditApiRouter.php` — mirror `WorkflowGuardsApiRouter`: `supports()` matches `'AuditQueryController::'`; dispatch `index`; JSON:API error envelope.
- `packages/api/src/ApiServiceProvider.php` — `use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;` (api-local — fine). In `httpDomainRouters()`: `$rm = $this->resolveOptional(AuditQueryReadModelInterface::class); if ($rm instanceof AuditQueryReadModelInterface) { $routers[] = new AuditApiRouter(new AuditQueryController($rm)); }`.
- `packages/api/composer.json` — add `"waaseyaa/audit": "<exact tag>"` to **require-dev** + `"../audit"` path repo entry. Run `composer update --lock waaseyaa/audit` in the lane.

### CLI prune (T-N)
- `packages/cli/src/Command/Audit/PruneCommand.php extends Command`. Signature: `audit:prune --older-than=<duration> [--kind=<glob>] [--dry-run]`. Validates the `--older-than` ISO-8601 duration via `\DateInterval`. Computes the cutoff `\DateTimeImmutable::now()->sub($interval)`. For `--dry-run`: queries `count` via `AuditQueryInterface` with the relevant filter; prints; exits 0. For execute: first writes the `audit.retention_pruned` self-audit event with attributes `{kind_pattern, older_than, deleted_count: <computed-before-delete>}`; then issues a single bulk `DatabaseInterface::delete('audit_event')` filtered by `created_at < cutoff` + kind-pattern (SQL `LIKE` with `%` for `*`). Returns the deleted count.

### Integration tests (T-O)
- `tests/Integration/PhaseOcapAudit/OcapAuditEndpointTest.php` (`#[CoversNothing]`) — boots full kernel; uses `EntityRepository` for `audit_event` to seed ≥6 events across ≥4 kinds and ≥2 accounts; `GET /api/audit/events?filter[kind]=entity.read&page[limit]=10` as admin → asserts response shape + filter correctness; as non-admin → 403. **This is the FR-013 dead-code guard.** Implementer MUST verify the test fails when the `AuditServiceProvider` binding for `AuditQueryReadModelInterface` is removed; report the failing assertion in the WP wrap-up.
- `tests/Integration/PhaseOcapAudit/AuditRetentionPruneTest.php` — uses `CommandTester`; seeds 10 events with mixed `created_at`; runs `audit:prune --older-than=PT1H`; asserts older rows deleted; asserts one `audit.retention_pruned` record exists with `attributes.deleted_count` matching.

### Docs (T-P)
- `docs/specs/ocap-audit-log.md` — schema, kind taxonomy, listener catalogue, query API, retention semantics, CLI usage, cross-references.
- `CLAUDE.md` orchestration table — add `packages/audit/*` row → `docs/specs/ocap-audit-log.md`. (Layer table updated in WP01.)
- `docs/specs/codified-context-integration.md` — append cross-reference note that audit follows the same L0↔L4 read-contract pattern.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `OCAP audit log substrate (packages/audit, renamed from analytics): append-only unified event table, JSON:API query endpoint, retention CLI, lifecycle listeners. Closes gap-matrix A3. (ocap-audit-log-substrate-01KSEFTF)`.

## Verification gate (each WP, in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/audit/tests/ packages/api/tests/Unit/Audit/ tests/Integration/PhaseOcapAudit/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy && bin/check-no-secrets`.
5. Confirm: `rg -n 'Waaseyaa\\\\Analytics' .` returns nothing; `rg -n 'Waaseyaa\\\\Audit' packages/api/src/` returns nothing; `rg -nE 'use Waaseyaa\\\\Audit' packages/api/src/` returns nothing.
6. NFR-001 chaos: WP02 ships `AuditChaosTest`; reviewer reruns to confirm `GET /api/entity/...` is still 200 with the audit table renamed.
7. Dead-code guard: reviewer comments out the `AuditQueryReadModelInterface` binding in `AuditServiceProvider`, re-runs `OcapAuditEndpointTest`, confirms it fails, then restores the binding.

## Reviewer focus

- (a) **DIR-004 / C-002 / NFR-002:** zero `use Waaseyaa\Audit\*` in `packages/api/src/**`; `audit` is api **require-dev** only; `bin/check-package-layers` green.
- (b) **DIR-005 honoured:** `audit_event` is single-table, NOT two-axis. The two-axis substrate is for entity data; audit events are immutable historical facts.
- (c) **FR-005 / NFR-001 best-effort:** chaos test demonstrably proves a failing audit write does NOT 500 the primary request. Every listener body wraps try-catch; reviewer greps for missing wrapping.
- (d) **Append-only enforcement:** `AppendOnlyDriverGuard` throws on `update()` / `delete()`; only `audit:prune` mass-deletes (via `DatabaseInterface::delete()`, not the entity repository).
- (e) **Self-audit on retention pruning** (FR-011): one `audit.retention_pruned` event written BEFORE the bulk delete, deleted-count matches.
- (f) **Dead-code guard** (FR-013): test fails when `ApiAuditQueryAdapter` binding is removed.
- (g) **Package rename completeness:** no `Waaseyaa\Analytics` strings anywhere; metapackages updated; layer-table and orchestration-table updated; downstream missions in the cluster reference `packages/audit` not `packages/analytics`.
- (h) **No new getQuery() callsites without binding** (CLAUDE.md gotcha): `bin/check-getquery-bindings` green; if a new callsite is added it has `setAccount()` or an inline justification + baseline entry.
