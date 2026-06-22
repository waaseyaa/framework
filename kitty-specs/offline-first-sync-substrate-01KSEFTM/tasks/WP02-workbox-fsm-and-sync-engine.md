---
work_package_id: WP02
title: Workbox service worker + PWA manifest, SyncStateMachine FSM, ConflictResolver (classification-aware), SyncEngine, Mercure consumer extension
dependencies:
- WP01
requirement_refs:
- FR-002
- FR-003
- FR-004
- FR-005
- FR-008
- FR-011
- NFR-001
- NFR-004
- NFR-005
- C-002
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-D
- T-E
- T-F
- T-G
- T-H
- T-I
shell_pid: '588123'
history: []
authoritative_surface: packages/admin/app/offline/sync
execution_mode: code_change
mission_id: 01KSEFTMSAV1E8WNDG6XXHPHHP
owned_files:
- packages/admin/sw.ts
- packages/admin/nuxt.config.ts
- packages/admin/app/offline/sync/SyncStateMachine.ts
- packages/admin/app/offline/sync/ConflictResolver.ts
- packages/admin/app/offline/sync/SyncEngine.ts
- packages/admin/app/composables/useOfflineRealtime.ts
- packages/admin/app/composables/useRealtime.ts
- packages/admin/tests/unit/offline/sync/SyncStateMachineTest.test.ts
- packages/admin/tests/unit/offline/sync/ConflictResolverTest.test.ts
- packages/admin/tests/unit/offline/sync/SyncEngineTest.test.ts
- packages/admin/public/offline.html
tags:
- substrate
- offline
- workbox
- service-worker
- fsm
- sync
- mercure
wp_code: WP02
---

# WP02 — Workbox service worker + sync FSM + ConflictResolver + SyncEngine + Mercure consumer

**Mission:** `offline-first-sync-substrate-01KSEFTM`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01.

## Pattern references — READ FIRST

- `/tmp/waaseyaa-design-offline-first.md` §3 "Proposed Substrate" (especially §"Libraries & Patterns" table and §"Sync Protocol"); §"Conflict policy" (C-003 multi-submission-merge default); §8 "Implementation Sequencing".
- `packages/admin/app/composables/useRealtime.ts` — existing Mercure SSE consumer; extend or sibling.
- `packages/mercure/src/MercurePublisher.php` — verify what events are currently published (`entity.saved`, `entity.deleted`).
- Workbox docs (via context7 if available): `runtimeCaching`, `backgroundSync`, `precacheAndRoute`.
- Nuxt PWA docs (via context7 if available): pick `@vite-pwa/nuxt` (current) or `@nuxtjs/pwa` (legacy).
- `CLAUDE.md` §Code-Style — deep-teal brand colour `#0f766e`.

## Subtasks

### T-D — Workbox service worker + PWA manifest

1. `npm install --save-dev @vite-pwa/nuxt workbox-window` (or the chosen PWA module).
2. `packages/admin/nuxt.config.ts` — register the PWA module; declare PWA manifest:
   ```ts
   pwa: {
     manifest: {
       name: 'Waaseyaa Admin',
       short_name: 'Waaseyaa',
       theme_color: '#0f766e',
       background_color: '#0d4f4f',
       display: 'standalone',
       start_url: '/',
       icons: [/* placeholder; real art later */],
     },
     workbox: { /* see sw.ts */ },
   }
   ```
3. `packages/admin/sw.ts` (or wherever the chosen PWA module expects it) per plan.md §T-D. Routes: API `GET` → network-first w/ 24h cache (NFR-005); static → cache-first; backgroundSync queue `waaseyaa-offline-writes` for `POST|PATCH|DELETE /api/entities/*` + `/api/sync/acknowledge`; `precacheAndRoute([{url: '/offline.html', revision: '1'}])`.
4. `packages/admin/public/offline.html` — minimal HTML page: "You're offline. Some functionality is limited. Your work is being queued for sync."

### T-E — SyncStateMachine FSM

1. `packages/admin/app/offline/sync/SyncStateMachine.ts` per plan.md §T-E. JSDoc `@public` on the exported class.
2. Events: `network_online`, `network_offline`, `fetch_failure_threshold_hit`, `write_attempt`, `acknowledge_409`, `sse_sync_conflict`, `user_resolved_conflict`, `drain_complete`.
3. State transition table per spec.md FR-003.
4. Exposes a reactive ref (`shallowRef('online')` per Vue 3 conventions) for `useOfflineSync` to subscribe to.

### T-F — ConflictResolver (classification-aware)

1. `packages/admin/app/offline/sync/ConflictResolver.ts` per plan.md §T-F. Cite design-offline-first.md §"Libraries & Patterns" and §"Conflict policy" in JSDoc.
2. Signature: `resolve(localPayload, serverPayload, entityMetadata: {classificationLabel: string | null, conflictPolicy: 'last_write_wins' | 'multi_submission_merge' | null}): ConflictResolution`.
3. Decision tree:
   - `classificationLabel !== 'public' && classificationLabel !== null` → ALWAYS multi-submission-merge (governed data is never silently overwritten, even if the bundle template opts into LWW — OCAP doctrine wins over per-bundle policy).
   - Else (public OR null) AND `conflictPolicy === 'last_write_wins'` → server-wins LWW.
   - Else → multi-submission-merge (default).

### T-G — SyncEngine

1. `packages/admin/app/offline/sync/SyncEngine.ts` per plan.md §T-G. Constructor `(db: OfflineDatabase, fsm: SyncStateMachine, resolver: ConflictResolver, fetchFn?: typeof fetch)`.
2. `drain()`: reads all `pending_writes`; for each, POST with the `If-Match` (or custom `X-Waaseyaa-Base-Vid`) header. Response handling per plan.
3. Backoff schedule: `[1000, 5000, 30000, 120000, 600000]` ms. After 5 failures → set `lastError` + move to a `failed` lane (separate Dexie table OR a boolean flag on `pending_writes` — implementer chooses).
4. After drain: if both `pending_writes` and `audit_pending` empty → `fsm.transition('drain_complete')` → `online`.

### T-H — Mercure consumer extension

1. Inspect `packages/admin/app/composables/useRealtime.ts`. Decision: extend in place OR add `useOfflineRealtime.ts` sibling. Preference: sibling (`useOfflineRealtime.ts`) so the existing `useRealtime` stays simple.
2. On `entity.saved`: parse payload; UPSERT into `entities` (update `tipVid` + `tipPayload`); APPEND to `entity_revisions` if not already present (idempotency by `[entityType, entityId, langcode, vid]`).
3. On `entity.deleted`: DELETE from `entities` for the matching composite key.
4. On `sync.conflict` (new event type added in WP03): parse `{entityType, entityId, langcode, clientVid, serverVid, serverValue}`; persist to a `pending_conflicts` Dexie table (extend the schema in v2 — Dexie migration policy from WP01 documented) OR inline in `pending_writes` with a special marker; transition FSM → `conflict`.

### T-I — Unit tests

1. `SyncStateMachineTest.test.ts` — for each documented transition: dispatch event, assert state.
2. `ConflictResolverTest.test.ts` — scenarios:
   - `(label='confidential', conflictPolicy='last_write_wins')` → multi-submission-merge (label wins).
   - `(label='public', conflictPolicy='last_write_wins')` → lww-server-wins.
   - `(label=null, conflictPolicy=null)` → multi-submission-merge (default).
3. `SyncEngineTest.test.ts` — fake `fetch` + fake `pending_writes`:
   - 200 → row removed.
   - 409 → resolver called; FSM → `conflict`.
   - Network error → `attemptCount` incremented; next attempt scheduled.
   - 5 failures → `failed` lane.

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install`.
2. `npm test packages/admin/tests/unit/offline/`.
3. `npm run typecheck && npm run lint && npm run build`.
4. PWA manifest validity: build output should include `manifest.webmanifest` with `theme_color: '#0f766e'`.
5. SW registration: verify `packages/admin/public/sw.js` (or wherever the chosen module emits) is present after build.
6. `bin/check-package-layers` (PHP-side — no PHP changes in this WP, should be unchanged).

## Commit + handoff

- `chore(admin): add @vite-pwa/nuxt + workbox-window devDeps`
- `feat(admin): Workbox service worker + PWA manifest (deep-teal brand)`
- `feat(admin): SyncStateMachine FSM (online/offline/syncing/conflict)`
- `feat(admin): ConflictResolver — multi-submission-merge default for governed data per design-offline-first.md §Conflict policy`
- `feat(admin): SyncEngine drains pending_writes with backoff + failed lane`
- `feat(admin): useOfflineRealtime — Mercure consumer feeds OfflineDatabase + handles sync.conflict`
- `test(admin): FSM transitions + ConflictResolver classification matrix + SyncEngine backoff`

```
spec-kitty agent tasks mark-status T-D T-E T-F T-G T-H T-I --status done --mission offline-first-sync-substrate-01KSEFTM
spec-kitty agent tasks move-task WP02 --to for_review --mission offline-first-sync-substrate-01KSEFTM --note "SW + FSM + Resolver + Engine + Mercure consumer in place; multi-submission-merge default verified"
```

## Report back with

1. Commit SHAs.
2. Which PWA module was chosen (and why).
3. Output of `ConflictResolverTest.test.ts` — paste the classification-matrix scenarios + their results.
4. Output of `SyncEngineTest.test.ts` showing the 5-failure → `failed` lane transition.
5. The PWA manifest section of `nuxt.config.ts` (paste — must show `theme_color: '#0f766e'`).
6. `npm run build` green (with the SW asset emitted).

## Activity Log
