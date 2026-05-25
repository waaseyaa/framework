# Implementation Plan: Offline-First Sync Substrate

**Mission:** `offline-first-sync-substrate-01KSEFTM` — see `spec.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` + `classification-retention-engine-01KSEFTH` both merged.
**Pattern references (READ FIRST):** `/tmp/waaseyaa-design-offline-first.md` (the design document — read end-to-end before starting); `packages/admin/app/composables/useRealtime.ts` (existing Mercure SSE consumer); `packages/api/src/Controller/FieldAutoSaveController.php` (existing optimistic-write path — extend for if-match vid semantics); `packages/mercure/src/MercurePublisher.php` (existing entity SSE publisher — extend with sync.conflict event); `docs/specs/entity-storage-two-axis.md` (DIR-005 substrate the IndexedDB schema mirrors); CLAUDE.md §"Adding an API endpoint" / §Architecture-Gotchas (request attribute `_account`); M5A `AiObservabilityReadModelInterface` (CodifiedContext pattern reference for the server-side acknowledge controller).

**Four WPs, sequential.** WP01 ships the Dexie schema + auth offline guard. WP02 ships the Workbox service worker + sync FSM + ConflictResolver + Mercure consumer extension. WP03 ships the server-side controllers + Mercure sync.conflict event + classification-aware conflict resolution. WP04 ships the auth offline semantics + status badge + integration test + Playwright spec + docs.

## WP01 — Dexie schema + `OfflineDatabase` + `useOfflineSync` composable

### Dexie setup (T-A)
- Add `dexie@^4` (and `fake-indexeddb` to devDependencies for tests) to `packages/admin/package.json`. Run `npm install` in the lane.
- `packages/admin/app/offline/db/OfflineDatabase.ts`:
  ```ts
  import Dexie, { type Table } from 'dexie'
  export class OfflineDatabase extends Dexie {
    entities!: Table<EntityCacheRow, [string, string, string]>
    entity_revisions!: Table<EntityRevisionRow, [string, string, string, number]>
    pending_writes!: Table<PendingWriteRow, number>
    auth_session!: Table<AuthSessionRow, number>
    audit_pending!: Table<AuditPendingRow, number>
    constructor() {
      super('WaaseyaaOffline')
      this.version(1).stores({
        entities: '[entityType+entityId+langcode], entityType, classificationLabel',
        entity_revisions: '[entityType+entityId+langcode+vid]',
        pending_writes: '++id, entityType, [entityType+entityId+langcode]',
        auth_session: '++id, accountUid',
        audit_pending: '++id, eventKind, accountUid',
      })
    }
  }
  ```
- TS types (`EntityCacheRow`, `EntityRevisionRow`, `PendingWriteRow`, `AuthSessionRow`, `AuditPendingRow`) defined in `packages/admin/app/offline/db/types.ts` mirroring spec.md schema. `@public` JSDoc on each.
- Singleton: `packages/admin/app/offline/db/index.ts` exports `export const offlineDb = new OfflineDatabase()`. Per-user wipe on user-switch: `offlineDb.delete()` then re-`open()` if `auth_session.accountUid` changes.

### Initial composable shell (T-B)
- `packages/admin/app/composables/useOfflineSync.ts` — exposes `{state: Ref<SyncState>, pendingCount: Ref<number>, conflictCount: Ref<number>, retry: () => Promise<void>, resolveConflict: (id, choice) => Promise<void>}`. State is mocked at WP01; actual FSM wiring lands in WP02.
- `packages/admin/app/composables/useOfflineEntity.ts` — read-path composable: `read(entityType, entityId, langcode)` returns the cached `EntityCacheRow` if online-cache enabled, else null. Filters by `classificationLabel` against the cached `clearanceLevel` from `auth_session` (FR-009 read filter).

### Tests for WP01 (T-C)
- `packages/admin/tests/unit/offline/db/OfflineDatabaseTest.test.ts` — uses `fake-indexeddb`; assert schema migration is safe; insert + query each table; composite-key roundtrip.
- `packages/admin/tests/unit/offline/composables/useOfflineEntityTest.test.ts` — seed two cached entities (`public` + `confidential`); assert non-cleared account sees only `public`; cleared account sees both.

## WP02 — Service worker + Workbox + FSM + ConflictResolver + SyncEngine + Mercure consumer extension

### Workbox + service worker (T-D)
- Add `workbox-window`, `@vite-pwa/nuxt` (or `@nuxtjs/pwa` — per spec.md §"Decisions deferred") to `packages/admin/package.json`.
- `packages/admin/sw.ts` (or whichever path the chosen PWA module expects):
  - `runtimeCaching`: `GET /api/*` → network-first with 24h cache (NFR-005); `GET /assets/*` → cache-first.
  - `backgroundSync`: queue `POST|PATCH|DELETE /api/entities/*` and `POST /api/sync/acknowledge` in a sync queue named `waaseyaa-offline-writes`; on `sync` event → drain via the SyncEngine.
  - Offline fallback: `precacheAndRoute([{url: '/offline.html', revision: '1'}])`.
- `packages/admin/nuxt.config.ts` — register the PWA module; PWA manifest: `name: 'Waaseyaa Admin'`, `short_name: 'Waaseyaa'`, `theme_color: '#0f766e'` (deep teal per CLAUDE.md), `background_color: '#0d4f4f'`, `display: 'standalone'`, icons (declare; placeholder PNGs OK at this WP — final art is a polish item).

### SyncStateMachine FSM (T-E)
- `packages/admin/app/offline/sync/SyncStateMachine.ts`:
  ```ts
  export type SyncState = 'online' | 'offline' | 'syncing' | 'conflict'
  export class SyncStateMachine {
    private state: SyncState = 'online'
    transition(event: SyncEvent): SyncState { ... }
    get current(): SyncState { return this.state }
  }
  ```
- Transitions per spec.md FR-003. Each transition logs an event via the framework's standard frontend logging hook (or `console.warn` in dev).
- Subscribes to `navigator.onLine` change events; subscribes to Mercure SSE; subscribes to fetch-failure threshold (3 consecutive `TypeError: Failed to fetch` → offline).

### ConflictResolver (T-F)
- `packages/admin/app/offline/sync/ConflictResolver.ts`:
  - `resolve(localPayload, serverPayload, entityMetadata): ConflictResolution` returns either `{strategy: 'multi-submission-merge', clientRevisionId, serverRevisionId}` (governed) OR `{strategy: 'lww-server-wins'}` OR `{strategy: 'lww-client-wins'}` based on:
    - `entityMetadata.conflictPolicy === 'last_write_wins'` AND `entityMetadata.classificationLabel === 'public'` OR null → LWW (server-wins on timestamp tie).
    - Else → multi-submission-merge. Both versions stored in `entity_revisions`; the conflict is surfaced via the FSM `conflict` state.
- Cite design-offline-first.md §"Libraries & Patterns" / §"Conflict policy" in JSDoc.

### SyncEngine (T-G)
- `packages/admin/app/offline/sync/SyncEngine.ts`:
  - Subscribes to FSM transitions; on `syncing` → drain `pending_writes`. For each row:
    - POST to the entity endpoint with `If-Match: vid="{baseVid}"` (or a custom `X-Waaseyaa-Base-Vid` header — implementer chooses; preference: custom header to avoid HTTP ETag semantics confusion).
    - 200 → remove row from queue; update `entities.tipVid`.
    - 409 → invoke `ConflictResolver`; persist conflict state; transition FSM → `conflict`.
    - Network error → increment `attemptCount`; backoff schedule `[1s, 5s, 30s, 2m, 10m]`. After 5 → push to `failed` lane.
  - On `syncing` complete (`pending_writes` empty + `audit_pending` empty) → transition → `online`.

### Mercure consumer extension (T-H)
- Either extend `packages/admin/app/composables/useRealtime.ts` OR add `packages/admin/app/composables/useOfflineRealtime.ts`. New consumer:
  - Subscribes to existing `entity.saved` / `entity.deleted` events; updates `OfflineDatabase.entities.tipVid` + appends to `entity_revisions`.
  - Subscribes to new `sync.conflict` event (published from server-side in WP03); transitions FSM → `conflict`.
  - Idempotency: drops events where `vid <= cached tipVid` (Risks mitigation).

### Tests for WP02 (T-I)
- Vitest: `SyncStateMachineTest.test.ts` — exhaustive transition coverage.
- Vitest: `ConflictResolverTest.test.ts` — governed → multi-submission-merge; LWW-opted-in → server-wins. Cite design-offline-first.md §"Conflict policy" in test docstring.
- Vitest: `SyncEngineTest.test.ts` — fake fetch + fake `pending_writes`; assert 200 / 409 / network-error handling.

## WP03 — Server-side: sync endpoints + Mercure event + classification-aware conflict events

### `SyncAcknowledgeController` (T-J)
- `packages/api/src/Sync/SyncAcknowledgeController.php`. Constructor `(EntityTypeManager $etm)`.
- `index(Request $request): array`: parses body `{entityType, entityId, langcode, clientVid, serverVid}`. Validates entityType is registered. Loads tip vid for `(entityType, entityId, langcode)` via the entity repository (composes on the two-axis substrate per DIR-005). If `clientVid === currentVid` → `{synced: true}`. Else → `{conflict: true, serverVid: currentVid, serverValue: <full payload>}` with status 409.
- Route `api.sync.acknowledge` → `POST /api/sync/acknowledge`, `_authenticated`, string FQCN.
- Unit test: 200 match + 409 conflict shape.

### `OfflineBatchAuditController` (T-K)
- `packages/api/src/Sync/OfflineBatchAuditController.php`. Constructor `(AuditWriterInterface $auditWriter, ?LoggerInterface $logger = null)` (use the audit substrate's contract — api `use Waaseyaa\Audit\Contract\AuditWriterInterface` is downward L4 → L0 = allowed; api is already require-dev'ing `waaseyaa/audit` per the OCAP audit mission).
- `index(Request $request): array`: parses body as `array<AuditEventDescriptor>`. For each: try construct + `$auditWriter->record()` wrapped in try-catch (malformed → log warning, skip; continue with next event). Preserves `created_at` from the inbound payload (the offline-time stamp) by mapping it into the `AuditEventDescriptor` constructor. Server adds `server_received_at` to the `attributes` JSON for forensic clarity.
- Route `api.sync.audit_batch` → `POST /api/sync/audit-batch`, `_authenticated`.
- Unit test: happy-path batch of 5 events all stored; malformed event in middle is skipped, others proceed.

### Mercure `sync.conflict` event (T-L)
- Extend `packages/mercure/src/MercurePublisher.php` (or add a sibling publisher) with a `publishSyncConflict(EntityInterface $entity, int $clientVid, int $serverVid): void` method that publishes an SSE event of type `sync.conflict` with payload `{entityType, entityId, langcode, clientVid, serverVid, serverValue}` to the entity's topic.
- Wire publication into the entity-save path: when `SyncAcknowledgeController` returns 409, publish the sync.conflict event (so other connected clients editing the same entity get notified).

### Classification-aware conflict policy hint (T-M)
- Extend the API response (the entity's JSON:API resource) to include `meta.conflictPolicy: 'last_write_wins' | 'multi_submission_merge'` derived from the bundle template + classification label. The frontend `ConflictResolver` reads this to drive its decision.
- If `classification-retention-engine-01KSEFTH` has merged, the `classificationLabel` is also in `meta`; if not, default to `null` and the frontend treats absent label as `public` (LWW default).

## WP04 — Auth offline guard + status badge + integration test + Playwright + docs

### `OfflineAuthGuard` (T-N)
- `packages/admin/app/offline/auth/OfflineAuthGuard.ts`: composable wrapping `useAuth`.
- On app boot: read `auth_session` row; if `expiresAt > Date.now() / 1000` AND `accountUid` matches the cached user → restore session in offline mode (set the in-memory account state).
- Subscribe to FSM `online` transition: trigger `useAuth().refresh()`; on failure → clear `auth_session` + redirect to login. On success → POST `/api/sync/audit-batch` with all `audit_pending` rows; on response → clear `audit_pending`.
- Token cipher (per spec.md §"Decisions deferred"): use `crypto.subtle.deriveKey` + `encrypt` if available; else plain-text + log a `feature_unavailable` event. WP report documents which mode was chosen at implementation time.

### OfflineStatusBadge (T-O)
- `packages/admin/app/components/offline/OfflineStatusBadge.vue` per spec.md FR-010.
- Mount in the admin shell header (verify location in `packages/admin/app/layouts/` or `app/components/AppShell.vue`).
- i18n keys: `offline_status_online`, `offline_status_offline`, `offline_status_syncing`, `offline_status_conflict`, `offline_status_pending_count`.

### FR-012 integration test (T-P)
- `packages/admin/tests/integration/offline/OfflineSyncIntegrationTest.test.ts` (vitest with `fake-indexeddb` + `msw` for fetch mocking). Full scenario per spec.md FR-012.

### FR-013 Playwright e2e (T-Q)
- `packages/admin/e2e/offline-sync.spec.ts` — Playwright spec per spec.md FR-013. Run deferred.

### Docs (T-R)
- `docs/specs/offline-first-sync.md` — new spec (~400-600 lines). Sections: Overview, Why, Architecture (Dexie + Workbox + FSM + ConflictResolver + auth-guard), Schema (the 5 IndexedDB tables), Sync protocol (mirror design-offline-first.md §"Sync Protocol"), Conflict semantics (multi-submission-merge default + LWW opt-in), Auth offline (token cipher + partial-trust + reconnect flow), Status surface, Server-side endpoints, Cross-mission integrations (audit substrate + classification mission), Risks (cite design-offline-first.md §9 anchors), Performance budget, Cross-references to design-offline-first.md sections by anchor.
- `CLAUDE.md` orchestration table — add row `packages/admin/app/offline/*` → `docs/specs/offline-first-sync.md`. Add row `packages/api/src/Sync/*` → same spec.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

## Verification gate (each WP, in lane worktree)

1. `composer install`; `cd packages/admin && npm install`.
2. WP01-WP02: `cd packages/admin && npm test && npm run typecheck`. WP03: `vendor/bin/phpunit packages/api/tests/Unit/Sync/`. WP04: `npm test && npm run build`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. DIR-005 sanity: `git diff main -- packages/entity-storage/` empty (no two-axis substrate changes).
6. FR-012 vitest integration test green.
7. Reviewer: confirm `bin/check-getquery-bindings` baseline at zero new entries; confirm controllers use `_account` request attribute (not `account`); confirm cross-mission `use Waaseyaa\Audit\Contract\AuditWriterInterface` import works (audit substrate merged).

## Reviewer focus

- (a) **DIR-005 substrate preservation** (C-002): IndexedDB schema uses composite key `[entityType, entityId, langcode]` and `entity_revisions` uses `[entityType, entityId, langcode, vid]` — directly mirrors `docs/specs/entity-storage-two-axis.md`. No client-side custom revision model.
- (b) **DIR-004 audit integration** (NFR-002): offline writes append to `audit_pending`; reconnect drains via `POST /api/sync/audit-batch`; server preserves `created_at` from offline-time stamp.
- (c) **C-003 multi-submission-merge default for governed data:** verify ConflictResolver returns multi-submission-merge for `classificationLabel === 'confidential'` even without an explicit `conflictPolicy` opt-in.
- (d) **C-004 service worker does NOT enforce access policy:** SW caches reads + queues writes; no policy-evaluation code in SW. Server-side AccessChecker remains authoritative.
- (e) **NFR-004 sync engine resilience:** failing single write doesn't crash the engine; exponential backoff; max attempts; `failed` lane visible to user.
- (f) **Cross-layer cleanliness:** `packages/api/src/Sync/*` imports `Waaseyaa\Audit\Contract\AuditWriterInterface` (L4 → L0 downward = allowed). No imports from admin (L6) into api (L4).
- (g) **Mercure `sync.conflict` event:** new SSE event type added without breaking existing `entity.saved` / `entity.deleted` consumers.
- (h) **CLAUDE.md gotchas:** `_account` request attribute (never `account`); `JSON_THROW_ON_ERROR` symmetry on encode/decode of `audit_pending` payloads; `psr/log` NOT imported (use `Waaseyaa\Foundation\Log\LoggerInterface`); atomic file writes if any SW state persisted to disk.
