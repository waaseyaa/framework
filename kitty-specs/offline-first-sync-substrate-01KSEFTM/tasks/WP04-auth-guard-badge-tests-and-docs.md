---
work_package_id: WP04
title: Auth offline guard, OfflineStatusBadge component, FR-012 integration test, FR-013 Playwright spec, docs, CHANGELOG
dependencies:
- WP02
- WP03
requirement_refs:
- FR-009
- FR-010
- FR-012
- FR-013
- FR-015
- NFR-002
- NFR-003
- C-005
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-N
- T-O
- T-P
- T-Q
- T-R
history: []
authoritative_surface: packages/admin/app/offline/auth
execution_mode: code_change
mission_id: 01KSEFTMSAV1E8WNDG6XXHPHHP
owned_files:
- packages/admin/app/offline/auth/OfflineAuthGuard.ts
- packages/admin/app/components/offline/OfflineStatusBadge.vue
- packages/admin/app/i18n/en.json
- packages/admin/app/layouts/default.vue
- packages/admin/tests/unit/offline/auth/OfflineAuthGuardTest.test.ts
- packages/admin/tests/integration/offline/OfflineSyncIntegrationTest.test.ts
- packages/admin/e2e/offline-sync.spec.ts
- docs/specs/offline-first-sync.md
- CLAUDE.md
- CHANGELOG.md
tags:
- substrate
- offline
- auth
- ui-badge
- integration-test
- docs
wp_code: WP04
---

# WP04 — Auth offline guard + status badge + integration test + Playwright + docs

**Mission:** `offline-first-sync-substrate-01KSEFTM`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP02 + WP03.

## Pattern references — READ FIRST

- `/tmp/waaseyaa-design-offline-first.md` §"OCAP Integration / Offline Writes" + §"AI-Access Toggle" + §"Reconnect Flow" — the spec's source for FR-007 + FR-009 semantics.
- `packages/admin/app/composables/useAuth.ts` (verify path) — existing auth composable that `OfflineAuthGuard` wraps.
- M5A WP02 — frontend prompt pattern (composable + component + i18n + tests + docs).
- `packages/admin/app/layouts/default.vue` — admin shell layout; mount the status badge in the header.

## Subtasks

### T-N — OfflineAuthGuard

1. `packages/admin/app/offline/auth/OfflineAuthGuard.ts`. JSDoc `@public`.
2. Lifecycle hooks:
   - On app boot: `await offlineDb.auth_session.toCollection().first()`. If row exists AND `expiresAt > Date.now() / 1000` AND tokens decrypt successfully → restore session via `useAuth().restoreFromOffline(row)`. Else → standard login flow.
   - On FSM `online` transition: `try { await useAuth().refresh() } catch { await wipeForAccountSwitch(0); redirectToLogin() }`. On success → POST `/api/sync/audit-batch` with all `audit_pending` rows; on response → `await offlineDb.audit_pending.clear()`.
3. Token cipher per spec.md §"Decisions deferred":
   - Try `crypto.subtle.deriveKey(...)` over a SW-stored key.
   - Fallback: store plain-text + emit a one-time `console.warn('Token cipher unavailable; storing plaintext in IndexedDB')`.
4. Classification-clearance cache population: on successful auth (online), populate the `auth_session.clearanceLevel` field by calling a new server endpoint OR by reading the JWT claims (verify whether `useAuth` exposes user roles; if yes, derive clearance from the role-clearance config the classification mission shipped, mirrored client-side as a JSON config asset).

### T-O — OfflineStatusBadge + i18n + mount

1. `packages/admin/app/components/offline/OfflineStatusBadge.vue`:
   ```vue
   <script setup lang="ts">
   import { useOfflineSync } from '~/composables/useOfflineSync'
   const { state, pendingCount, conflictCount } = useOfflineSync()
   </script>
   <template>
     <span v-if="state === 'offline'" class="badge-offline">{{ t('offline_status_offline') }}</span>
     <span v-else-if="state === 'syncing'" class="badge-syncing">{{ t('offline_status_syncing', { count: pendingCount }) }}</span>
     <span v-else-if="state === 'conflict'" class="badge-conflict">{{ t('offline_status_conflict', { count: conflictCount }) }}</span>
   </template>
   ```
2. Mount in `packages/admin/app/layouts/default.vue` header area.
3. i18n keys in `packages/admin/app/i18n/en.json`: `offline_status_online`, `offline_status_offline`, `offline_status_syncing`, `offline_status_conflict`, `offline_status_pending_count`, `offline_session_restored`, `offline_session_expired_redirect`, `offline_audit_flushed`.

### T-P — FR-012 vitest integration test

1. `packages/admin/tests/integration/offline/OfflineSyncIntegrationTest.test.ts`. Uses `fake-indexeddb` + `msw` (Mock Service Worker) for fetch mocking.
2. Scenario per spec.md FR-012:
   - Seed `OfflineDatabase`: 2 entities (one `public`, one `confidential`), 1 `pending_write`, 1 `audit_pending`.
   - Boot the SyncEngine + ConflictResolver + Mercure mock.
   - Simulate `navigator.onLine = true`; trigger FSM → `syncing`.
   - Assert: msw captures the POST to the entity endpoint; replays a 200 OK; pending_write removed.
   - Assert: msw captures the POST to `/api/sync/audit-batch`; replays 200 `{accepted: 1, skipped: 0}`; `audit_pending` cleared.
   - Assert: FSM → `online`.
   - **Conflict branch**: re-seed a fresh `pending_write`; configure msw to return 409 with `serverValue`; assert ConflictResolver invoked; FSM → `conflict`; `pending_conflicts` row appears; simulate user resolution; FSM returns to `syncing` → drains → `online`.

### T-Q — FR-013 Playwright e2e (deferred)

1. `packages/admin/e2e/offline-sync.spec.ts`:
   - Visit `/`; assert badge shows `online` (no visible badge text).
   - Use Playwright's `context.setOffline(true)`; trigger a save in the UI; assert `pending_writes` grows; badge shows `Offline`.
   - `context.setOffline(false)`; wait for badge to show `Syncing N`; eventually `online`.
2. Run deferred per lane-worktree limitation. Commit only.

### T-R — Docs + CHANGELOG

1. `docs/specs/offline-first-sync.md` per plan.md §T-R. Cite design-offline-first.md by anchor throughout (especially §"Two-Axis Integration", §"Sync Protocol", §"Conflict policy", §"OCAP Integration / Offline Reads", §"Reconnect Flow", §10 headline recommendation).
2. `CLAUDE.md` orchestration table — add two rows:
   - `packages/admin/app/offline/*` → `docs/specs/offline-first-sync.md`
   - `packages/api/src/Sync/*` → `docs/specs/offline-first-sync.md`
3. `CHANGELOG.md` `[Unreleased]` → **Added**: `Offline-first sync substrate (packages/admin/app/offline + packages/api/src/Sync): Dexie IndexedDB schema mirroring (entity_id, langcode, vid) two-axis tuple, Workbox service worker with backgroundSync queue, SyncStateMachine FSM, classification-aware ConflictResolver (multi-submission-merge default for governed data), SyncEngine with exponential backoff, OfflineAuthGuard with token caching + partial-trust offline, /api/sync/acknowledge + /api/sync/audit-batch endpoints, Mercure sync.conflict event, OfflineStatusBadge in admin shell. Substrate only — per-surface integration (Drive / Forms / Docs) ships in subsequent missions per alpha-to-beta-plan §2. Closes gap-matrix A7 / alpha-to-beta-plan §1 item #6. (offline-first-sync-substrate-01KSEFTM)`.

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install`.
2. `npm test packages/admin/tests/unit/offline/ packages/admin/tests/integration/offline/`.
3. `npm run typecheck && npm run lint && npm run build`.
4. `composer cs-check` (PHP docs/CHANGELOG/CLAUDE.md only).
5. `bin/check-no-secrets`.
6. Reviewer: confirm the FR-012 integration test drains the full offline → write → reconnect → conflict → resolve → drain → online cycle.
7. Cross-mission verification: a hand-test in dev server (boot admin SPA online; trigger an offline op via dev-tools network throttling; reconnect; verify the audit event appears in `GET /api/audit/events` from the OCAP audit substrate — proves NFR-002 end-to-end integration).

## Commit + handoff

- `feat(admin): OfflineAuthGuard — token caching, reconnect refresh, audit-pending flush, partial-trust offline`
- `feat(admin): OfflineStatusBadge + i18n + admin-shell mount`
- `test(admin): OfflineSyncIntegrationTest — full offline → reconnect → conflict → resolve cycle`
- `test(admin): offline-sync.spec.ts Playwright (deferred run)`
- `docs(specs): offline-first-sync.md + CLAUDE.md orchestration rows + CHANGELOG`

```
spec-kitty agent tasks mark-status T-N T-O T-P T-Q T-R --status done --mission offline-first-sync-substrate-01KSEFTM
spec-kitty agent tasks move-task WP04 --to for_review --mission offline-first-sync-substrate-01KSEFTM --note "Substrate complete: auth guard + status badge + integration test passing; Playwright + cross-mission audit smoke verified by hand"
```

## Report back with

1. Commit SHAs.
2. The FR-012 integration test output showing the complete cycle (paste).
3. Token cipher mechanism chosen (SW-stored vs plain-text fallback — paste the feature-detection branch).
4. Cross-mission audit-smoke output: a sample `GET /api/audit/events?account=<the-user>` showing an offline-captured audit event arrived in the substrate.
5. CLAUDE.md orchestration-table diff (2 rows added).
6. `npm run build` green + dist sizes within reasonable budget (Dexie ~50KB gzipped; Workbox runtime ~20KB).

## Activity Log
