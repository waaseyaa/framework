---
work_package_id: WP01
title: OCAP audit substrate — rename analytics→audit, AuditEvent entity, contracts, append-only guard, service provider, foundation route
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-008
- FR-012
- NFR-002
- NFR-003
- NFR-004
- C-001
- C-002
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-A
- T-B
- T-C
- T-D
- T-E
- T-F
history: []
authoritative_surface: packages/audit/src
execution_mode: code_change
owned_files:
- packages/audit/composer.json
- packages/audit/src/AuditServiceProvider.php
- packages/audit/src/Entity/AuditEvent.php
- packages/audit/src/Entity/AuditEventType.php
- packages/audit/src/Entity/AuditRetentionPolicy.php
- packages/audit/src/Enum/AuditEventKind.php
- packages/audit/src/Schema/AuditEventSchemaHandler.php
- packages/audit/src/Contract/AuditWriterInterface.php
- packages/audit/src/Contract/AuditQueryInterface.php
- packages/audit/src/Contract/AuditQuery.php
- packages/audit/src/Contract/AuditEventDescriptor.php
- packages/audit/src/Writer/AuditEventWriter.php
- packages/audit/src/Writer/NullAuditWriter.php
- packages/audit/src/Storage/AppendOnlyDriverGuard.php
- packages/audit/src/Query/AuditEventQuery.php
- packages/audit/migrations/2026_05_25_000001_create_audit_event_table.php
- packages/audit/migrations/2026_05_25_000002_create_audit_retention_policy_table.php
- packages/audit/tests/Contract/AuditWriterContractTest.php
- packages/audit/tests/Contract/AuditQueryContractTest.php
- packages/audit/tests/Unit/Writer/AuditEventWriterTest.php
- packages/audit/tests/Unit/Writer/AuditEventWriterBestEffortTest.php
- packages/audit/tests/Unit/Query/AuditEventQueryTest.php
- packages/audit/tests/Unit/Storage/AppendOnlyDriverGuardTest.php
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- packages/core/composer.json
- packages/cms/composer.json
- packages/full/composer.json
- composer.json
- CLAUDE.md
tags:
- substrate
- ocap
- audit
- layer-0
- rename
---

# WP01 — Substrate: rename `analytics` → `audit`, entity, contracts, append-only driver guard

**Mission:** `ocap-audit-log-substrate-01KSEFTF` (gap-matrix A3; alpha-to-beta-plan §1 item #2)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)

## CRITICAL — work in the mission worktree

```
cd $(spec-kitty agent action implement WP01 --print-worktree)
```

(or the path printed by `spec-kitty next`). Lane worktree has no `vendor/` — run `composer install` first.

## Pattern references — READ FIRST (do not skip)

- `docs/specs/codified-context-integration.md` — the L0↔L4 cross-layer read-contract pattern; specifically the inheritance model + "MCP Federation" sections describing how lower-layer packages adapt upward via api-namespaced interfaces.
- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `AiObservabilityController.php` + the `ObservabilityServiceProvider::register()` binding block — M5A's shipped exemplar. Audit mirrors the same shape but at L0↔L4 (M5A was L5↔L4).
- `packages/api/src/Http/Router/WorkflowGuardsApiRouter.php` — router shape.
- `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — how `_role: admin` routes register with string FQCN.
- `.claude/rules/entity-storage-invariant.md` — the canonical Entity → EntityType → SqlStorageDriver → EntityRepository → DatabaseInterface pipeline; this WP must follow it.
- `CLAUDE.md` §"Adding an entity type" checklist + §"Adding a service provider" checklist.
- `packages/entity-storage/src/Schema/SqlSchemaHandler.php` for the schema pattern.

## Subtasks

### T-A — Package rename: `analytics` → `audit`

1. `git mv packages/analytics packages/audit`.
2. `rm packages/audit/src/UmamiClient.php` (Greenfield Removal Policy per charter DIR-003; zero consumers; no value retained). Also delete `packages/audit/assets/` and `packages/audit/templates/` if they exist and contain only Umami-related scaffolding (verify before deleting).
3. Rewrite `packages/audit/composer.json`: `name: "waaseyaa/audit"`, `autoload.psr-4: { "Waaseyaa\\Audit\\": "src/" }`, `autoload-dev.psr-4: { "Waaseyaa\\Audit\\Tests\\": "tests/" }`, `extra.waaseyaa.providers: ["Waaseyaa\\\\Audit\\\\AuditServiceProvider"]`, `config.sort-packages: true` (CP001), `license: "GPL-2.0-or-later"` (DIR-008). Internal `waaseyaa/*` constraints pinned to the current tag literal per CLAUDE.md CP-NEW (run `bin/sync-internal-versions` if needed).
4. Update root `composer.json` workspace `path` repositories: replace `"packages/analytics"` with `"packages/audit"`.
5. Update metapackages `packages/core/composer.json`, `packages/cms/composer.json`, `packages/full/composer.json` — replace `"waaseyaa/analytics"` with `"waaseyaa/audit"` in `require`.
6. Update `CLAUDE.md`:
   - Layer table — Layer-0 row: replace `analytics` with `audit`.
   - Orchestration table — remove `packages/analytics/*` row (if present); add `packages/audit/*` → `docs/specs/ocap-audit-log.md` (this file will be created in WP03).
7. Run `composer dump-autoload`.
8. Verify: `rg -n 'Waaseyaa\\\\Analytics' .` → empty; `rg -n '"waaseyaa/analytics"' .` → empty; `rg -n '"packages/analytics"' .` → empty.

### T-B — `AuditEvent` + `AuditRetentionPolicy` entity types + schema + migrations

Per CLAUDE.md §"Adding an entity type" + `.claude/rules/entity-storage-invariant.md`.

1. `packages/audit/src/Enum/AuditEventKind.php` — string-backed enum, 14 cases per spec.md §In-scope. Class-level `@api` PHPDoc.
2. `packages/audit/src/Entity/AuditEvent.php` `extends ContentEntityBase`. Constructor `__construct(array $values = [])` hardcodes `entity_type_id = 'audit_event'`, `entity_keys = ['id' => 'id', 'uuid' => 'uuid']`. Class-level `@api`.
3. `packages/audit/src/Entity/AuditEventType.php` — returns the `EntityType` value object (or a static factory) — examine how `packages/note/src/Note.php` + sibling type registration handles this and mirror.
4. `packages/audit/src/Entity/AuditRetentionPolicy.php` `extends ContentEntityBase`. Properties: `kind_pattern`, `older_than_seconds`, `action` (`purge`-only at this layer), `created_at`. Class-level `@api`. Table `audit_retention_policy`.
5. `packages/audit/src/Schema/AuditEventSchemaHandler.php` — uses `SqlSchemaHandler` to construct the `audit_event` table per spec.md columns. Indices on `(account_uid)`, `(entity_type_id, entity_uuid)`, `(event_kind, created_at)`, `(created_at)`. Class-level `@api`.
6. Migrations:
   - `packages/audit/migrations/2026_05_25_000001_create_audit_event_table.php` — creates `audit_event` per the schema; `audit_event_data` blob column for the `_data` JSON per CLAUDE.md entity-system gotcha.
   - `packages/audit/migrations/2026_05_25_000002_create_audit_retention_policy_table.php` — creates `audit_retention_policy` + `audit_retention_policy_data`.

### T-C — Write-side contracts + append-only enforcement

1. `packages/audit/src/Contract/AuditEventDescriptor.php` — readonly value DTO. Properties mirror schema columns (camelCase in PHP). Constructor validates `eventKind` against `AuditEventKind::tryFrom()`; throws `\InvalidArgumentException` on unknown kind. `@api`.
2. `packages/audit/src/Contract/AuditWriterInterface.php` — `record(AuditEventDescriptor $descriptor): void`. `@api`.
3. `packages/audit/src/Writer/AuditEventWriter.php implements AuditWriterInterface`. Constructor: `(EntityRepositoryInterface $repo, ?Waaseyaa\Foundation\Log\LoggerInterface $logger = null)` (default `new NullLogger()`). `record()` body wraps the whole save in `try { ... } catch (\Throwable $e) { $this->logger->warning('audit.write_failed', ['event_kind' => $descriptor->eventKind, 'error' => $e->getMessage()]); return; }`. NEVER re-throws (FR-005, NFR-001).
4. `packages/audit/src/Writer/NullAuditWriter.php implements AuditWriterInterface` — silent no-op. `@api`. Document as the test-environment / chaos override.
5. `packages/audit/src/Storage/AppendOnlyDriverGuard.php` — class wrapping the storage driver. `update()` and `delete()` throw `\LogicException('audit_event is append-only; use AuditWriterInterface::record() or audit:prune CLI for bulk delete.')`. `save()` delegates to inner driver only when `$entity->isNew()`. `load()`, `loadMultiple()`, `findBy()` pass through.

### T-D — Read-side contract + query implementation

1. `packages/audit/src/Contract/AuditQuery.php` — readonly value. Properties: `?int $accountUid`, `?string $entityType`, `?string $entityUuid`, `?array $kinds` (of `AuditEventKind`), `?\DateTimeImmutable $from`, `?\DateTimeImmutable $to`, `int $limit = 50`, `int $offset = 0`. `@api`.
2. `packages/audit/src/Contract/AuditQueryInterface.php` — `findBy(AuditQuery $query): iterable<AuditEvent>`, `count(AuditQuery $query): int`. `@api`.
3. `packages/audit/src/Query/AuditEventQuery.php implements AuditQueryInterface`. Uses `DatabaseInterface::select('audit_event')` query builder. Ordering `created_at DESC, id DESC` (stable). NEVER `getQuery()` — keep `bin/check-getquery-bindings` baseline at zero new entries. Hydrates `AuditEvent` instances via the `audit_event` repository (NOT raw arrays — per `entity-storage-invariant.md`).

### T-E — Service provider + foundation route

1. `packages/audit/src/AuditServiceProvider.php extends ServiceProvider`. `register()`:
   - Binds `AuditWriterInterface` → `AuditEventWriter` factory (resolves the `audit_event` repo + logger).
   - Binds `AuditQueryInterface` → `AuditEventQuery` factory (resolves `DatabaseInterface` + the `audit_event` repo for hydration).
   - Registers `AuditEvent` + `AuditRetentionPolicy` entity types via `EntityTypeManager::addEntityType()` per CLAUDE.md §"Adding an entity type".
   - **DEFERRED to WP03:** the binding for `Waaseyaa\Api\Audit\AuditQueryReadModelInterface` lives here once that interface exists. Add a `// TODO(WP03): bind ApiAuditQueryAdapter` comment as a placeholder.
2. `boot()` — registers schema handlers via `SqlSchemaHandler` if the framework requires explicit registration (mirror sibling packages).
3. `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` — add the route `api.audit.events.index` → `GET /api/audit/events`, `->requireRole('admin')`, `->methods('GET')`, controller string FQCN `'Waaseyaa\\Api\\Controller\\AuditQueryController::index'`. Position: after the workflow-guards block (the AI-observability block from M5A is canonical). The controller class won't exist until WP03 — that's fine; foundation registers routes by string and resolves them at boot only when the class is wired by api.

### T-F — Tests

1. `packages/audit/tests/Contract/AuditWriterContractTest.php` `extends \PHPUnit\Framework\TestCase` `#[CoversNothing]` — abstract contract test class declaring `abstract protected function createWriter(): AuditWriterInterface;`. Tests: `record() writes one row`, `record() rejects unknown event_kind via descriptor validation`, `record() is best-effort (failing repo does not throw)`.
2. `packages/audit/tests/Unit/Writer/AuditEventWriterTest.php extends AuditWriterContractTest` — concrete impl using `DBALDatabase::createSqlite()` + the migration runner.
3. `packages/audit/tests/Unit/Writer/AuditEventWriterBestEffortTest.php` — injects an `EntityRepositoryInterface` whose `save()` throws `\RuntimeException`; asserts `AuditEventWriter::record()` returns void without re-throwing; asserts one `warning` log entry with the canonical message.
4. `packages/audit/tests/Contract/AuditQueryContractTest.php` (abstract) + `packages/audit/tests/Unit/Query/AuditEventQueryTest.php` (concrete SQLite). Test seed: 6 events across 4 kinds + 2 accounts; assertions cover account/entity/kind/date-range filters + pagination + ordering.
5. `packages/audit/tests/Unit/Storage/AppendOnlyDriverGuardTest.php` — assert `update()` + `delete()` throw `\LogicException` with the canonical message; `save()` on new entity passes through; `save()` on non-new throws.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/audit/tests/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy && bin/check-no-secrets`.
5. `rg -n 'Waaseyaa\\\\Analytics' .` → empty.
6. `rg -n '"waaseyaa/analytics"' .` → empty.
7. `rg -nE 'use Waaseyaa\\\\Audit' packages/api/src/` → empty (still nothing there — api wiring lands in WP03).
8. `bin/waaseyaa optimize:manifest` to refresh attribute discovery; restart any dev server.

## Commit + handoff

Suggested commits (DIR-004 / DIR-005 / DIR-006 trace footers via `Refs: charter-amendment-anokii-track-01KSEFE0` if/when applicable; otherwise no PR number — see `docs/specs/workflow.md`):

- `refactor(audit): rename packages/analytics → packages/audit; remove UmamiClient`
- `feat(audit): AuditEvent + AuditRetentionPolicy entities + schema + migrations`
- `feat(audit): AuditWriterInterface + best-effort AuditEventWriter + NullAuditWriter + AppendOnlyDriverGuard`
- `feat(audit): AuditQueryInterface + AuditEventQuery (DatabaseInterface query builder)`
- `feat(audit): AuditServiceProvider bindings + entity registrations`
- `feat(foundation): /api/audit/events route (admin) — controller wired in WP03`
- `test(audit): contract + unit tests for writer, query, append-only guard`

Then:

```
spec-kitty agent tasks mark-status T-A T-B T-C T-D T-E T-F --status done --mission ocap-audit-log-substrate-01KSEFTF
spec-kitty agent tasks move-task WP01 --to for_review --mission ocap-audit-log-substrate-01KSEFTF --note "Substrate ready: analytics renamed, AuditEvent entity + append-only guard + writer + query in place; route reserved in BuiltinRouteRegistrar; api wiring + endpoint in WP03"
```

## Report back with

1. Commit SHAs.
2. Output of `rg -c 'Waaseyaa\\Analytics' .` (must be 0).
3. Output of `bin/check-package-layers` (clean).
4. Output of `vendor/bin/phpunit packages/audit/tests/` (all green).
5. Confirmation that `AppendOnlyDriverGuard::update()` and `::delete()` throw `\LogicException` (paste the test output).
6. Confirmation that `AuditEventWriter::record()` swallows a thrown repo exception (paste the `AuditEventWriterBestEffortTest` green output).

## Activity Log
- 2026-05-25T05:20:26Z – unknown – Moved to for_review
- 2026-05-25T05:30:34Z – unknown – Opus review: analytics→audit rename clean; AuditEvent append-only entity + contracts shipped; gates pass
- 2026-05-26T18:47:38Z – unknown – Done override: Sprint merge to main
