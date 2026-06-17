---
work_package_id: WP03
title: 'Server-side: SyncAcknowledgeController + OfflineBatchAuditController + Mercure sync.conflict event + classification-policy meta hints'
dependencies:
- WP01
requirement_refs:
- FR-006
- FR-007
- FR-008
- FR-014
- NFR-002
- NFR-003
- C-001
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-J
- T-K
- T-L
- T-M
history: []
authoritative_surface: packages/api/src/Sync
execution_mode: code_change
mission_id: 01KSEFTMSAV1E8WNDG6XXHPHHP
owned_files:
- packages/api/src/Sync/SyncAcknowledgeController.php
- packages/api/src/Sync/OfflineBatchAuditController.php
- packages/api/src/Http/Router/SyncApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/composer.json
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- packages/mercure/src/MercurePublisher.php
- packages/api/tests/Unit/Sync/SyncAcknowledgeControllerTest.php
- packages/api/tests/Unit/Sync/OfflineBatchAuditControllerTest.php
tags:
- substrate
- offline
- server-side
- json-api
- mercure
- audit-integration
wp_code: WP03
---

# WP03 — Server-side sync endpoints + Mercure sync.conflict event

**Mission:** `offline-first-sync-substrate-01KSEFTM`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01 (for the wire contract). May proceed in parallel with WP02.

## Pattern references — READ FIRST

- `/tmp/waaseyaa-design-offline-first.md` §"Sync Protocol" — the request/response shapes for `/api/sync/acknowledge`.
- `packages/api/src/Controller/FieldAutoSaveController.php` — existing optimistic-write pattern; extend the vid-matching semantics.
- `packages/api/src/AiObservability/AiObservabilityReadModelInterface.php` + `AiObservabilityController.php` — M5A controller / router pattern reference.
- `packages/audit/src/Contract/AuditWriterInterface.php` + `AuditEventDescriptor.php` (from `ocap-audit-log-substrate-01KSEFTF`) — audit write contract. Note: api importing this is L4 → L0 downward = allowed; verify `packages/api/composer.json` already require-devs `waaseyaa/audit` (per audit substrate WP03).
- `packages/mercure/src/MercurePublisher.php` — existing entity SSE publisher; extend with `sync.conflict`.
- CLAUDE.md §"Adding an API endpoint" + §Architecture-Gotchas (request attribute `_account`, never `account`).

## Subtasks

### T-J — `SyncAcknowledgeController`

1. `packages/api/src/Sync/SyncAcknowledgeController.php`. Constructor `(EntityTypeManager $etm, ?MercurePublisher $mercure = null)`.
2. `index(Request $request): array`:
   - Parse JSON body `{entityType, entityId, langcode, clientVid, serverVid}`. Use `JSON_THROW_ON_ERROR` per CLAUDE.md gotcha.
   - Validate `entityType` is registered (`$etm->hasEntityType($entityType)`).
   - Resolve account via `$request->attributes->get('_account')` (never `'account'`).
   - Load the entity for `(entityType, entityId)` at `langcode` (composes on the two-axis substrate per DIR-005 / C-002 — read tip vid via the storage driver).
   - If entity's current vid === `clientVid`: return `['data' => ['synced' => true]]` with status 200.
   - Else: return `['errors' => [['status' => '409', 'code' => 'conflict', 'meta' => ['serverVid' => $currentVid, 'serverValue' => $serverPayload]]]]` with status 409. Also publish `sync.conflict` Mercure event (T-L) so other connected clients editing the entity are notified.

### T-K — `OfflineBatchAuditController`

1. `packages/api/src/Sync/OfflineBatchAuditController.php`. Constructor `(Waaseyaa\Audit\Contract\AuditWriterInterface $auditWriter, ?LoggerInterface $logger = null)` — api `use` of L0 audit type = downward = allowed.
2. `index(Request $request): array`:
   - Parse JSON body as `array<int, array>` (list of descriptor-shaped objects). `JSON_THROW_ON_ERROR`.
   - Reject non-array body with 400.
   - For each event in the batch (wrapped in try-catch per CLAUDE.md best-effort pattern):
     - Construct `AuditEventDescriptor` from the payload. Preserve the inbound `created_at` if the descriptor constructor accepts it; if not, extend the descriptor additively to accept an optional `?DateTimeImmutable $createdAt = null` (additive, backward-compatible).
     - Append a server-side attribute `server_received_at = now()` for forensic clarity.
     - `$this->auditWriter->record($descriptor)`.
     - Failure → log warning, skip, continue.
   - Return `['data' => ['accepted' => $accepted, 'skipped' => $skipped]]` with status 200.

### T-L — Mercure `sync.conflict` event

1. Extend `packages/mercure/src/MercurePublisher.php` with a new method `publishSyncConflict(string $topic, array $payload): void`. Payload: `{entityType, entityId, langcode, clientVid, serverVid, serverValue}`. SSE event type: `sync.conflict`.
2. Wire publication into `SyncAcknowledgeController` (call when returning 409). Best-effort wrap.
3. Verify the event-type addition doesn't break existing consumers (existing consumers should ignore unknown event types).

### T-M — Classification-policy meta hints

1. Extend the JSON:API resource shape for entities (in the existing `ResourceSerializer` per CLAUDE.md `packages/api/*` orchestration row): add `meta.conflictPolicy: 'last_write_wins' | 'multi_submission_merge'` derived from bundle template + classification label.
   - If classification mission's `classificationLabel` is present and `!== 'public' && !== null` → `'multi_submission_merge'`.
   - Else if bundle template declares LWW → `'last_write_wins'`.
   - Else → `'multi_submission_merge'` (default per C-003).
2. Add `meta.classificationLabel: string | null` if the classification mission is merged.
3. Document the new meta shape in `docs/specs/jsonapi.md` (per CLAUDE.md orchestration row for `packages/api/*`).

### Router + service-provider wiring + foundation routes

1. `packages/api/src/Http/Router/SyncApiRouter.php` — mirror `AiObservabilityApiRouter`. Supports `'SyncAcknowledgeController::'` + `'OfflineBatchAuditController::'`; dispatch `index`.
2. `packages/api/src/ApiServiceProvider.php` — add the resolveOptional wiring block in `httpDomainRouters()` for `SyncApiRouter`. Construct the two controllers from container.
3. `packages/api/composer.json` — verify `waaseyaa/audit` is already require-dev'd (from the OCAP audit mission's WP03); if not, add it.
4. `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:
   - `api.sync.acknowledge` → `POST /api/sync/acknowledge`, `_authenticated`, string FQCN `'Waaseyaa\\Api\\Sync\\SyncAcknowledgeController::index'`.
   - `api.sync.audit_batch` → `POST /api/sync/audit-batch`, `_authenticated`, string FQCN `'Waaseyaa\\Api\\Sync\\OfflineBatchAuditController::index'`.

### Unit tests

1. `packages/api/tests/Unit/Sync/SyncAcknowledgeControllerTest.php`:
   - 200 match: build request with `clientVid` matching seeded entity tip; assert response `{data: {synced: true}}`.
   - 409 conflict: `clientVid < tip`; assert 409 + JSON:API error shape with `meta.serverVid` + `meta.serverValue`.
2. `packages/api/tests/Unit/Sync/OfflineBatchAuditControllerTest.php`:
   - Happy path: batch of 3 events all stored via the fake `AuditWriterInterface`; response `{accepted: 3, skipped: 0}`.
   - Malformed-event resilience: batch of 3 where index 1 is missing required `eventKind`; response `{accepted: 2, skipped: 1}`; warning logged.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/api/tests/Unit/Sync/`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. `rg -nE 'use Waaseyaa\\\\Audit\\\\Contract\\\\AuditWriterInterface' packages/api/src/Sync/` → 1 match (OfflineBatchAuditController). This is L4 → L0 downward = allowed.
6. Verify `$request->attributes->get('_account')` is used in both controllers (NEVER `'account'`).
7. JSON encode/decode symmetry: both controllers use `JSON_THROW_ON_ERROR`.

## Commit + handoff

- `feat(api): SyncAcknowledgeController — POST /api/sync/acknowledge with 200/409 vid-match contract`
- `feat(api): OfflineBatchAuditController — POST /api/sync/audit-batch (best-effort, preserves offline created_at)`
- `feat(api): SyncApiRouter + ApiServiceProvider wiring`
- `feat(foundation): /api/sync/{acknowledge,audit-batch} routes`
- `feat(mercure): publishSyncConflict — new sync.conflict SSE event for connected clients`
- `feat(api): JSON:API resource meta.conflictPolicy + meta.classificationLabel hints`
- `test(api): SyncAcknowledgeController + OfflineBatchAuditController unit tests`

```
spec-kitty agent tasks mark-status T-J T-K T-L T-M --status done --mission offline-first-sync-substrate-01KSEFTM
spec-kitty agent tasks move-task WP03 --to for_review --mission offline-first-sync-substrate-01KSEFTM --note "Server-side sync surface ready; audit substrate integration verified via best-effort batch endpoint"
```

## Report back with

1. Commit SHAs.
2. Sample 200 + 409 response shapes (paste).
3. The audit-batch resilience test output (3 events, 1 malformed → 2 accepted + 1 skipped).
4. The new Mercure `sync.conflict` event payload shape (paste).
5. The JSON:API resource `meta` block sample showing `conflictPolicy` + `classificationLabel`.
6. Confirmation that `packages/api/composer.json` has `waaseyaa/audit` in `require-dev` (paste the relevant block).
7. `bin/check-package-layers` green.

## Activity Log
