# Offline-First Sync Substrate — Dexie IndexedDB, Workbox Service Worker, FSM Sync Engine, OCAP-Aware Conflict Resolution

**Mission:** `offline-first-sync-substrate-01KSEFTM`
**Target branch:** `main`
**Tracks:** No GitHub issue (Wave 2 framework substrate — FRAMEWORK, not Anokii-specific; Russell listed it under framework work per the cluster context block). Closes gap-matrix row **A7** + alpha-to-beta-plan §1 substrate item **#6**. Charter directives: **DIR-004** (OCAP-by-architecture — offline reads filtered by classification, offline ops audit-logged on reconnect), **DIR-005** (two-axis storage preservation — IndexedDB schema maps onto the framework's `(entity_id, langcode, vid)` tuple), **DIR-006** (codified gates).
**Pattern reference (CANONICAL):** `/tmp/waaseyaa-design-offline-first.md` — full design document for this substrate; specifically §3 Proposed Substrate, §4 v0.1 Surfaces (Drive / Docs / Forms), §7 OCAP Integration, §9 Risk & Mitigation, §10 Strongest Existing Asset & Recommendation; `docs/specs/entity-storage-two-axis.md` + `entity-storage-translatable-revisions.md` (the framework's `(entity_id, langcode, vid)` substrate that the IndexedDB schema mirrors); `packages/admin/app/composables/useRealtime.ts` (existing Mercure SSE consumer); `packages/api/src/Controller/FieldAutoSaveController.php` (existing optimistic-write surface); `packages/mercure/src/MercurePublisher.php` (existing entity.saved/entity.deleted SSE publisher); CodifiedContext pattern.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` (WP04 writes audit events on reconnect — `entity.write`, `access.denied` for offline ops); `classification-retention-engine-01KSEFTH` (WP03 classification-aware conflict resolution).

## Why this mission exists

Per design-offline-first.md §1-2: today the admin SPA has ZERO offline infrastructure (no IndexedDB store, no service worker, no Workbox / Dexie / Yjs, no outgoing-mutation queue, no ack/conflict protocol on Mercure subscriptions, no network-aware UI affordances). `useRealtime` consumes Mercure SSE but only buffers 100 messages in-memory; `FieldAutoSaveController` accepts optimistic writes but doesn't queue when offline. Gap-matrix A7 notes the constraint that justifies this work upfront: **Robinson-Huron Treaty Nations geography (FN datacenter thesis Part 11) makes intermittent backhaul the norm**. A workspace that breaks on connectivity drop is non-viable for these adopters.

This mission ships the substrate: (1) Dexie-backed IndexedDB schema mirroring the framework's `(entity_id, langcode, vid)` tuple (design-offline-first.md §"Two-Axis Integration"); (2) Workbox service worker for cached read paths + offline page fallback + background-sync queue; (3) FSM-based sync engine with states `online | offline | syncing | conflict` and OCAP-aware conflict resolution (design-offline-first.md §"Libraries & Patterns" / §"Sync Protocol"); (4) auth offline semantics with token caching, re-auth on reconnect, partial-trust offline reads, and audit-log capture of offline operations syncing on reconnect (design-offline-first.md §"OCAP Integration / Offline Writes" / §"Reconnect Flow").

This is the framework primitive that v0.1 productivity surfaces (Drive WP01, Forms WP03, Docs WP03 — alpha-to-beta-plan §1 item #6 + §2 v0.1 productivity surfaces) compose on. Per design-offline-first.md §10: starting with Dexie + Workbox substrate only (no Yjs at v1) unblocks Robinson-Huron read-heavy workflows within the substrate's effort budget; per-surface integration ships in subsequent missions.

## The framework-substrate scoping decision

This mission is in `packages/admin/` because the offline substrate is client-side TypeScript code that ships with the framework's reference admin SPA. Even though the user-visible benefit accrues to distribution-level features (Drive, Docs, Forms), the substrate IS framework code: any distribution built on Waaseyaa that uses the admin SPA inherits it. Per the cluster context block: "FRAMEWORK substrate (not Anokii-specific) because Russell explicitly listed it under framework work." This honours DIR-007 (standalone Nuxt SPA as the workspace bet) and DIR-001 (framework vs distribution distinction — substrate primitives ship in the framework; distribution-specific surfaces consume them).

## Scope

### In scope

**Layer 6 — `packages/admin/app/offline/` (NEW directory — the offline substrate root):**
- `packages/admin/app/offline/db/OfflineDatabase.ts` — Dexie v4 schema definition. Tables (per design-offline-first.md §"Two-Axis Integration"):
  - `entities`: composite key `[entityType, entityId, langcode]`; columns: `entityType`, `entityId`, `langcode`, `tipVid` (server's current vid for this entity), `tipPayload` (JSON-serialised entity), `classificationLabel`, `hydratedAt`. Index on `entityType`, `classificationLabel`.
  - `entity_revisions`: composite key `[entityType, entityId, langcode, vid]`; columns: `entityType`, `entityId`, `langcode`, `vid`, `payload`, `createdAt`, `createdBy`. Used as a bounded local revision cache (per design-offline-first.md §"Risk & Mitigation" — prune older than 90d; keep latest 50 per entity on client).
  - `pending_writes`: auto-increment key; columns: `op` (`save`|`delete`), `entityType`, `entityId`, `langcode`, `baseVid` (the vid the client started editing from), `payload`, `createdAt`, `attemptCount`, `lastError?`. The outgoing-mutation queue.
  - `auth_session`: singleton row; columns: `accountUid`, `accessTokenCipher`, `refreshTokenCipher`, `expiresAt`, `cachedAt`, `roleSet`, `clearanceLevel`. (Tokens encrypted with a session-scoped key — see Risks.)
  - `audit_pending`: outgoing-audit queue; columns mirror `AuditEventDescriptor` shape; ships audit events captured during offline operations to the server on reconnect.

**Layer 6 — sync engine (FSM):**
- `packages/admin/app/offline/sync/SyncStateMachine.ts` — TypeScript FSM (states: `online | offline | syncing | conflict`). Transitions on `navigator.onLine` changes, on write attempts, on Mercure SSE events, on conflict-detected responses. Class-level `@api` JSDoc (we use a JSDoc tag equivalent for TS — verify whether the admin uses `@public` / `@internal`; mirror existing convention).
- `packages/admin/app/offline/sync/ConflictResolver.ts` — classification-aware conflict resolution:
  - Default for governed data (any entity with `classificationLabel !== 'public'` AND label !== `null`): **multi-submission-merge** — both client + server versions are preserved as separate revisions; the conflict is surfaced to the user via a `conflict` event on the FSM. The user manually picks (or merges if Docs-style three-way merge is available — Docs-specific code in a downstream mission).
  - Opt-in for admin forms (entities whose bundle metadata declares `conflict_policy: 'last_write_wins'` — implementer adds this to the existing `#[BundleTemplate]` attribute additively, or via a config key): **LWW** — server applies the latest write unconditionally.
  - Reference: design-offline-first.md §"Libraries & Patterns" / §"Sync Protocol".
- `packages/admin/app/offline/sync/SyncEngine.ts` — orchestrates the FSM: reads `pending_writes`, attempts to POST each to the server, handles conflict responses, retries with exponential backoff per design-offline-first.md §8.

**Layer 6 — service worker:**
- `packages/admin/sw.ts` (or wherever Nuxt expects the SW registration — verify Nuxt 3 + `@nuxtjs/pwa` conventions). Workbox routes:
  - `runtimeCaching`: API `GET` calls → network-first with offline-cache fallback; static assets → cache-first.
  - `backgroundSync`: queue for `POST` / `PATCH` / `DELETE` API calls on `/api/entities/*` and `/api/sync/acknowledge`; replay on reconnect via the SyncEngine.
  - Offline page fallback at `/offline.html`.
- `packages/admin/nuxt.config.ts` — register `@nuxtjs/pwa` (or `@vite-pwa/nuxt`) module; declare PWA manifest (name, theme color = the deep-teal brand `#0f766e` per CLAUDE.md, icons).

**Layer 6 — Mercure consumer extension:**
- Extend `packages/admin/app/composables/useRealtime.ts` (or add a sibling `useOfflineRealtime.ts`) to feed incoming `entity.saved` / `entity.deleted` SSE events into the `OfflineDatabase.entities` + `entity_revisions` tables. On `sync.conflict` events (new SSE event type added below) → transition FSM to `conflict`.

**Layer 4 — `packages/api` server-side:**
- `Api\Sync\SyncAcknowledgeController::index()` → `POST /api/sync/acknowledge`. Body: `{entityType, entityId, langcode, clientVid, serverVid}` (per design-offline-first.md §"Sync Protocol"). Server compares `clientVid` to current server vid; returns `200 {synced: true}` if matched OR `409 {conflict: true, serverVid, serverValue}` if server has advanced.
- New SSE event type `sync.conflict` published via Mercure (extend `MercurePublisher` to publish on entity-save when a write arrives with a stale `baseVid` from the offline queue).
- `Api\Sync\OfflineBatchAuditController::index()` → `POST /api/sync/audit-batch`. Body: `[{...AuditEventDescriptor}, ...]`. Replays offline-captured audit events server-side via `AuditWriterInterface` from the OCAP audit substrate. Each event's `created_at` is preserved (so the audit-trail reflects the offline-operation time, not the reconnect time). Best-effort: a malformed event in the batch is logged + skipped; others proceed.

**Layer 6 — Auth offline semantics:**
- `packages/admin/app/offline/auth/OfflineAuthGuard.ts` — composable wrapping `useAuth`. On app boot: if `auth_session` row exists AND `expiresAt > now()` → restore session offline (UI works in partial-trust mode). On reconnect: trigger silent token-refresh; if refresh fails → kick user to login; if succeeds → audit-log the offline session's operations + clear the local token cache.
- Partial trust offline: the user can read their own classified data (subject to the cached `classificationLabel` filter in `OfflineDatabase.entities`); CANNOT read OTHER Nations' cached data even if the IndexedDB row is present (per design-offline-first.md §"Offline Reads" — classification labels filter offline reads). Writes are queued. Each offline op writes one `audit_pending` row to be flushed on reconnect.
- Token cipher: tokens are encrypted with a per-install symmetric key derived from `crypto.subtle.deriveKey()` over the user's salted password OR a service-worker-stored key. Defaults to plain-text-in-IndexedDB IF a key-derivation source isn't available (documented gap; not a blocker for the substrate landing).

**Layer 6 — Status surface:**
- `packages/admin/app/components/offline/OfflineStatusBadge.vue` — small UI badge showing current FSM state (offline / syncing / synced / conflict count). Rendered in the admin shell header.
- `packages/admin/app/composables/useOfflineSync.ts` — exposes `{state, pendingCount, conflictCount, retry, resolveConflict}` to UI components.
- i18n keys in `packages/admin/app/i18n/en.json`.

**Tests:**
- Vitest unit tests for: Dexie schema migration safety, `SyncStateMachine` transitions, `ConflictResolver` (governed → multi-submission-merge; LWW-opted-in → server-wins), `OfflineAuthGuard` (expired token → kicked to login; valid → restored).
- Vitest integration test using `fake-indexeddb` (npm package) for: enqueue write → simulate offline → reconnect → assert write replayed + entity-revisions hydrated + audit events flushed.
- Playwright e2e (deferred run per lane-worktree limitation): network throttling drop → assert UI remains functional + queue grows + reconnect drains queue + status badge updates.
- PHP-side unit tests for `SyncAcknowledgeController` + `OfflineBatchAuditController` per CLAUDE.md §"Adding an API endpoint" patterns.

**Docs:**
- `docs/specs/offline-first-sync.md` — new spec. Cites `/tmp/waaseyaa-design-offline-first.md` as the design source; codifies what's implemented vs deferred.
- `CLAUDE.md` orchestration table — add row for `packages/admin/app/offline/*` → `docs/specs/offline-first-sync.md`.
- `CHANGELOG.md` `[Unreleased]` → **Added**.

### Out of scope (→ separate missions / future)

- **Per-surface offline integration** (Drive metadata sync, Forms LWW queue-then-submit, Docs offline-read + three-way merge) — these compose on this substrate and ship as part of the per-surface missions per alpha-to-beta-plan §2 (Drive WP01, Forms WP03, Docs WP03). Per design-offline-first.md §6 substrate is 3-4 sprints + per-surface 2-3 weeks each.
- **Yjs CRDT integration for multi-user real-time collab** (design-offline-first.md §5 Sheets). v1.0 substrate extension.
- **Per-Nation data partitioning enforcement at the IndexedDB level beyond classification filter.** The current substrate filters reads by classification label; a stricter "tenant id" partition mechanism (where cross-Nation data is structurally invisible, not just access-denied) is deferred to a multi-tenant mission (alpha-to-beta-plan §1 item #26 / gap-matrix F2).
- **Encrypted offline data-at-rest** (full IndexedDB row-level encryption). The token cipher is shipped; full row-level encryption is a follow-up if procurement demands it.
- **Service-worker-side AccessChecker enforcement.** The SW is read-cache + write-queue only. It does NOT re-evaluate access policies — it trusts the cached entity's classification label. A future mission could add WASM-port server-side AccessChecker if needed.
- **Mobile-platform-specific install (PWA install banner, A2HS prompts)**. Substrate-only at this mission; UX polish later.

## Requirements

| ID | Type | Requirement |
|---|---|---|
| FR-001 | functional | Dexie v4 is added as a dependency to `packages/admin/package.json`; `packages/admin/app/offline/db/OfflineDatabase.ts` defines the five tables (entities, entity_revisions, pending_writes, auth_session, audit_pending) per spec.md §In-scope. Composite-key schema for `entities` is `[entityType, entityId, langcode]` mirroring the framework's `(entity_id, langcode, vid)` substrate per DIR-005 (cite design-offline-first.md §"Two-Axis Integration"). |
| FR-002 | functional | Workbox + `@nuxtjs/pwa` (or `@vite-pwa/nuxt` — implementer chooses) is registered in `nuxt.config.ts`. Service worker `sw.ts` ships with `runtimeCaching` for API + assets, `backgroundSync` for outgoing mutations, and a `/offline.html` fallback. PWA manifest declares the deep-teal brand colour per CLAUDE.md. |
| FR-003 | functional | `SyncStateMachine.ts` is a TypeScript FSM with states `online | offline | syncing | conflict`. Transitions: (a) `online → offline` on `navigator.onLine === false` or fetch-failure threshold; (b) `offline → syncing` on `navigator.onLine === true`; (c) `syncing → online` on empty `pending_writes` + drained `audit_pending`; (d) any → `conflict` on a `sync.conflict` SSE event or a 409 response from `/api/sync/acknowledge`; (e) `conflict → syncing` on user resolution. Transitions are unit-tested. |
| FR-004 | functional | `ConflictResolver.ts` consults the entity's `classificationLabel`. For governed data (label !== `public` and label !== null): default policy is **multi-submission-merge** — preserves both client + server revisions, surfaces conflict to the user. For entities whose bundle metadata declares `conflict_policy: 'last_write_wins'`: applies LWW. Cite design-offline-first.md §"Libraries & Patterns" + §"Conflict policy". |
| FR-005 | functional | `SyncEngine.ts` drains `pending_writes` on transition to `syncing`. For each row: POST the payload to its entity endpoint with `If-Match: vid="{baseVid}"` (or equivalent vid header — implementer chooses). 200 → remove from queue. 409 → invoke ConflictResolver, surface conflict to FSM. Network error → exponential backoff (max 5 attempts, then push to a `failed` lane for user retry). |
| FR-006 | functional | `POST /api/sync/acknowledge` (`SyncAcknowledgeController`) accepts `{entityType, entityId, langcode, clientVid, serverVid}`. Returns `200 {synced: true}` when client vid matches server tip OR `409 {conflict: true, serverVid, serverValue}` otherwise. `_authenticated` at route level (per request attribute `_account` — never `account` per CLAUDE.md gotcha). |
| FR-007 | functional | `POST /api/sync/audit-batch` (`OfflineBatchAuditController`) accepts an array of `AuditEventDescriptor` payloads captured during offline operation. Replays each via `AuditWriterInterface` from `ocap-audit-log-substrate-01KSEFTF`, preserving `created_at` from the original offline-time stamp. Malformed event in batch → log + skip; other events proceed. `_authenticated` at route level. |
| FR-008 | functional | The Mercure consumer extension feeds incoming `entity.saved` / `entity.deleted` events into `OfflineDatabase.entities` + `entity_revisions`. A new SSE event type `sync.conflict` is published by `MercurePublisher` when a server-side save detects a stale `baseVid`; the consumer transitions FSM → `conflict`. |
| FR-009 | functional | `OfflineAuthGuard.ts` honours auth offline semantics: cached token restores session if `expiresAt > now()`; reconnect triggers token-refresh; refresh-failure kicks to login; success flushes `audit_pending` rows and clears the cache. The user can read their OWN cached classified data offline; CANNOT read OTHER Nations' data even if a stray row is present (classification-label filter applied to all reads, per design-offline-first.md §"Offline Reads"). |
| FR-010 | functional | `OfflineStatusBadge.vue` is rendered in the admin shell header. Shows the FSM state (online → no badge; offline → yellow "Offline"; syncing → blue spinner "Syncing N"; conflict → red "N conflicts"). `useOfflineSync` composable exposes the state. i18n keys present. |
| FR-011 | functional | Vitest unit tests cover: (a) Dexie schema migration is safe (downgrade not attempted; up-migration from no-data is idempotent); (b) `SyncStateMachine` transitions exhaustively; (c) `ConflictResolver` returns multi-submission-merge for `confidential` label and LWW for an entity with `conflict_policy: 'last_write_wins'`; (d) `OfflineAuthGuard` expired-token path kicks to login; (e) `OfflineDatabase.entities` query filtered by `classificationLabel` excludes higher-clearance rows for a non-cleared account. |
| FR-012 | functional | A **vitest integration test** using `fake-indexeddb` seeds: 2 cached entities with different classification labels + 1 pending_write + 1 audit_pending row. Simulates online transition. Asserts: pending_write replayed via mock fetch; entity-revisions hydrated from a mock SSE stream; audit_pending flushed via mock `/api/sync/audit-batch`. The whole sequence: offline → write → reconnect → drain → online state achieved. Includes a `conflict` scenario (server returns 409) → FSM transitions to `conflict` → ConflictResolver invoked → user-resolution simulation → FSM returns to `syncing` → drained → `online`. |
| FR-013 | functional | A **Playwright e2e spec** (deferred run): boot the admin SPA with network throttling that drops connection partway through a save; assert UI remains functional (no broken-state); assert `pending_writes` count visible in the badge; assert reconnect drains the queue + badge clears. Spec is committed but the run is deferred per lane-worktree limitation. |
| FR-014 | functional | PHP-side unit tests cover `SyncAcknowledgeController` (200 match + 409 conflict shape) and `OfflineBatchAuditController` (happy path + malformed-event resilience). |
| FR-015 | functional | `docs/specs/offline-first-sync.md` is created. Cites `/tmp/waaseyaa-design-offline-first.md` as the source design document; codifies the substrate's implemented surface and explicitly enumerates per-design-doc-anchor what's shipped vs deferred (Drive / Docs / Forms surface integration deferred; Yjs deferred; full row-level encryption deferred). Cross-referenced from CLAUDE.md orchestration table. `CHANGELOG.md` `[Unreleased]` → **Added**. |
| NFR-001 | non-functional | DIR-005 honoured: the IndexedDB schema for `entities` and `entity_revisions` uses composite keys mirroring the framework's `(entity_id, langcode, vid)` tuple (design-offline-first.md §"Two-Axis Integration"). The substrate does NOT alter the server-side two-axis storage. |
| NFR-002 | non-functional | DIR-004 honoured: offline reads are filtered by classification label (FR-009); offline writes produce `audit_pending` rows that are flushed on reconnect via FR-007 (offline operations are eventually audited with their original timestamp). `OfflineBatchAuditController` integrates with `AuditWriterInterface` from `ocap-audit-log-substrate-01KSEFTF`. |
| NFR-003 | non-functional | DIR-006 honoured: codified gates green. Specifically `bin/check-dead-code` (every public surface carries `@api`-equivalent JSDoc), `bin/check-package-layers` (admin SPA is L6; no inappropriate upward dependencies), `bin/check-composer-policy` (no PHP-side internal-constraint drift). |
| NFR-004 | non-functional | The sync engine is best-effort: a failing single write does NOT crash the engine — it backs off + retries; after max attempts, moves to a `failed` lane visible to the user. The FSM never enters a non-recoverable state. |
| NFR-005 | non-functional | Workbox cache invalidation: stale-while-revalidate on API reads; cached entries are time-bounded (24h default, configurable). Prevents stale-data confusion on long-disconnected accounts coming back online. |
| C-001 | constraint | This mission depends on `ocap-audit-log-substrate-01KSEFTF` (audit substrate must be merged; offline ops audit via `AuditWriterInterface`) AND `classification-retention-engine-01KSEFTH` (classification labels must exist; offline reads filter by them). |
| C-002 | constraint | The IndexedDB schema MUST mirror the framework's `(entity_id, langcode, vid)` substrate per DIR-005. NO custom revision-tracking model on the client; the server's vid is the canonical identifier. |
| C-003 | constraint | Default conflict resolution for governed data (any non-public classification label) is **multi-submission-merge**, NOT last-write-wins. LWW is opt-in per-bundle (FR-004). Cite design-offline-first.md §"Conflict policy". |
| C-004 | constraint | The service worker does NOT enforce access policies — it caches reads + queues writes. Server-side AccessChecker remains the authoritative gate. The classification-label client-side filter is a defence-in-depth read-time UX filter, not a security boundary. |
| C-005 | constraint | This mission ships the SUBSTRATE only. Per-surface integration (Drive metadata sync, Forms queue-then-submit, Docs offline-read + three-way merge) is OUT OF SCOPE and ships in subsequent per-surface missions per alpha-to-beta-plan §2. The substrate's API is `@api`-stable so per-surface missions can compose on it without expecting breakage. |

## Acceptance

- All 15 FRs / 5 NFRs / 5 constraints honoured.
- Gates green: `vendor/bin/phpunit` (PHP-side new controllers), `composer cs-check`, `composer phpstan`, `bin/check-package-layers`, `bin/check-dead-code`, `bin/check-composer-policy`, `bin/check-getquery-bindings`. Admin: `cd packages/admin && npm test && npm run typecheck && npm run lint`.
- Vitest integration test (FR-012) drains the full offline → reconnect → conflict → resolve → online cycle.
- `cd packages/admin && npm run build` succeeds (TypeScript + Vite compile).
- Reviewer (Opus) confirms: (a) DIR-005 IndexedDB schema mirrors `(entity_id, langcode, vid)`; (b) multi-submission-merge is the default for governed data (C-003); (c) offline writes are eventually audit-logged with original timestamp preserved (NFR-002); (d) service worker does NOT enforce access policy (C-004 — read-cache + write-queue only).

## Risks

- **Token cipher key-derivation source.** If `crypto.subtle.deriveKey()` is not available (very old browser; service worker context limitations), tokens fall back to plain-text in IndexedDB. Mitigation: documented gap in spec.md; mitigations would require either prompting user for an unlock PIN (poor UX) or trusting browser-keyring (out of scope at v1). Acceptable risk because IndexedDB is per-origin-per-browser-profile.
- **Dexie schema migration in production.** Schema versioning is hard once users have data. Mitigation: design-offline-first.md §9 lists "Data migration scripts in Workbox on-install; atomic schema swap"; Dexie supports `versionchange` events. WP01 documents the migration policy: additive-only changes are safe; column drops / renames require a versioned migration with a one-time hydration. No drops/renames in this substrate's v1 schema.
- **Sync-engine queue runaway.** A persistent server-error could cause `pending_writes` to grow unbounded. Mitigation: per-row `attemptCount`; after 5 failures push to `failed` lane (visible in badge as "N failed"); user can manually retry or discard. NFR-004 enforces this.
- **Stale Mercure SSE consumption mid-reconnect.** If Mercure replays old events on reconnect, the SyncEngine might re-apply already-applied writes. Mitigation: the `OfflineDatabase.entities.tipVid` is the idempotency key; an SSE event with `vid <= tipVid` is dropped silently.
- **Classification label drift offline.** If an entity's classification label changes server-side while the user is offline, the user might still see the old label until next sync. Mitigation: design-offline-first.md §9 documents this; classification changes fire `entity.saved` SSE on reconnect, which updates the cached label. The risk is bounded to the disconnected window.
- **Per-Nation data leakage via shared browser profile.** Two users on the same browser profile would share the IndexedDB store. Mitigation: keyed by `accountUid` in the `auth_session` row; on user-switch, the database is wiped and rehydrated for the new account. Documented in WP04.

## Decisions pre-resolved

- **Dexie v4 over alternatives (idb, RxDB).** Per design-offline-first.md §3: schema versioning + typed tables + integrates cleanly with Vue 3 + small bundle. RxDB is heavier; raw `idb` requires hand-rolled schema management.
- **Workbox over hand-rolled service worker.** Battle-tested cache strategies + background-sync; Nuxt has first-class PWA module support.
- **No Yjs at v1 of the substrate.** Per design-offline-first.md §10 explicit recommendation: "Start with Dexie + Workbox substrate only (no Yjs yet)." Yjs is v1.0+ for Sheets.
- **Multi-submission-merge as default for governed data, NOT last-write-wins.** Per cluster context block + design-offline-first.md §"Conflict policy". OCAP doctrine: a Nation's edit being overwritten by another user's stale write violates audit-trail completeness. Multi-submission-merge preserves both for explicit reconciliation.
- **Offline ops audit via batch-replay on reconnect, NOT live-streaming.** The `audit_pending` queue + `POST /api/sync/audit-batch` pattern is cheaper than maintaining a separate websocket for audit events. Reconnect-time batch is acceptable because audit-trail correctness, not real-time visibility, is what OCAP requires.
- **The substrate ships in `packages/admin/` (Layer 6), not in a new package.** Per the cluster-context "this is THE pattern primitive that v0.1 productivity surfaces compose on" + DIR-007 (Nuxt SPA bet). A new `packages/offline-sync` package would invite distribution-side forks; keeping the substrate in `packages/admin` makes inheritance via the framework's standard admin shell automatic. If a future need emerges to ship the substrate as a standalone library, it can be extracted then.
- **PWA manifest brand colour: deep-teal `#0f766e` per CLAUDE.md §Code-Style.** Not negotiable — consistent with the framework's existing brand commitment.

## Decisions deferred to implementer

- **Whether to use `@nuxtjs/pwa` or `@vite-pwa/nuxt`.** Latter is more current; former is more established. Implementer evaluates current Nuxt 3 + Vue 3 PWA-module support and picks one. Either is acceptable.
- **Token cipher key-derivation mechanism.** Implementer chooses between SW-stored key (more secure, requires SW lifecycle handling), password-derived key (most secure, requires user input on every cold start), or plain-text fallback. Recommendation: SW-stored key with graceful plain-text fallback + a feature-detection log.
- **`conflict_policy: 'last_write_wins'` declaration mechanism.** Implementer chooses between extending `#[BundleTemplate(..., conflictPolicy: ...)]` attribute (additive, requires `packages/field` change) OR a separate per-bundle config key. Recommendation: bundle-template attribute (cleaner co-location with bundle metadata).
- **Whether the `audit_pending` table preserves `created_at` from the offline time or the server stamps it.** Per FR-007: client preserves original `created_at`; server records `server_received_at` separately in audit-event attributes. Implementer verifies the `AuditEventDescriptor` shape from the audit substrate supports this distinction; extends if necessary.

Decision preference order per DIR-006: preserve OCAP audit lineage > minimise vendor lock-in > don't break codified policy gates.

## Out-of-band

- This mission MUST merge before per-surface offline integration missions enter implementation (Drive WP01 offline; Forms WP03 offline; Docs WP03 offline). Per alpha-to-beta-plan §1 substrate item #6 wording: "Per-surface integration: Drive metadata + downloaded blobs (~3 days), Forms LWW queue-then-submit (~3 days), Docs offline-read + save-on-reconnect with three-way merge (~5 days)."
- Future: `offline-sync-yjs-extension-*` — composes Yjs on top of this substrate for multi-user Sheets (alpha-to-beta-plan §1 item #24).
- Future: `offline-sync-mobile-pwa-polish-*` — A2HS prompts, install banners, mobile-tab badging.
- Cite `/tmp/waaseyaa-design-offline-first.md` §10 (Russell's "headline recommendation"): the substrate + Drive metadata sync + Forms per-field LWW unblock Robinson-Huron read-heavy workflows in 8-10 weeks once the per-surface missions land.
