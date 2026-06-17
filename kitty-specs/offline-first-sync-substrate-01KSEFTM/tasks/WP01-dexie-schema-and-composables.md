---
work_package_id: WP01
title: Dexie IndexedDB schema (5 tables mirroring two-axis tuple), OfflineDatabase, base composables (useOfflineSync shell, useOfflineEntity)
dependencies: []
requirement_refs:
- FR-001
- FR-009
- FR-011
- NFR-001
- NFR-003
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-offline-first-sync-substrate-01KSEFTM
base_commit: 9e041b7090ba8a6e2eee100adaaeb96bc9a707d2
created_at: '2026-05-25T05:36:32.793489+00:00'
subtasks:
- T-A
- T-B
- T-C
shell_pid: '69774'
history: []
authoritative_surface: packages/admin/app/offline/db
execution_mode: code_change
mission_id: 01KSEFTMSAV1E8WNDG6XXHPHHP
owned_files:
- packages/admin/package.json
- packages/admin/app/offline/db/OfflineDatabase.ts
- packages/admin/app/offline/db/types.ts
- packages/admin/app/offline/db/index.ts
- packages/admin/app/composables/useOfflineSync.ts
- packages/admin/app/composables/useOfflineEntity.ts
- packages/admin/tests/unit/offline/db/OfflineDatabaseTest.test.ts
- packages/admin/tests/unit/offline/composables/useOfflineEntityTest.test.ts
tags:
- substrate
- offline
- dexie
- indexeddb
- admin-spa
- layer-6
wp_code: WP01
---

# WP01 — Dexie IndexedDB schema + OfflineDatabase + composable shells

**Mission:** `offline-first-sync-substrate-01KSEFTM`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` + `classification-retention-engine-01KSEFTH` both MUST be merged.

## Pattern references — READ FIRST

- `/tmp/waaseyaa-design-offline-first.md` §3 "Proposed Substrate" + §"Two-Axis Integration" + §"Sync Protocol" — full schema motivation. Read the §"Libraries & Patterns" table.
- `docs/specs/entity-storage-two-axis.md` — the `(entity_id, langcode, vid)` tuple this schema mirrors (NFR-001 / C-002).
- `packages/admin/app/composables/useQueueJobs.ts` — composable shape (`{data, loading, error, fetch...}`).
- `packages/admin/package.json` — current dependency list.

## Subtasks

### T-A — Dexie setup + OfflineDatabase

1. `npm install --save dexie@^4 --save-dev fake-indexeddb@^5` in `packages/admin/`.
2. `packages/admin/app/offline/db/types.ts` — TypeScript types for the 5 row shapes:
   ```ts
   export interface EntityCacheRow { entityType: string; entityId: string; langcode: string; tipVid: number; tipPayload: unknown; classificationLabel: string | null; hydratedAt: number; }
   export interface EntityRevisionRow { entityType: string; entityId: string; langcode: string; vid: number; payload: unknown; createdAt: number; createdBy: number; }
   export interface PendingWriteRow { id?: number; op: 'save' | 'delete'; entityType: string; entityId: string; langcode: string; baseVid: number; payload: unknown; createdAt: number; attemptCount: number; lastError?: string; }
   export interface AuthSessionRow { id?: number; accountUid: number; accessTokenCipher: string; refreshTokenCipher: string; expiresAt: number; cachedAt: number; roleSet: string[]; clearanceLevel: number; }
   export interface AuditPendingRow { id?: number; eventKind: string; accountUid: number; entityType: string | null; entityUuid: string | null; subjectUri: string | null; outcome: 'allowed' | 'denied' | 'error'; severity: 'info' | 'notice' | 'warning'; attributes: Record<string, unknown>; createdAt: number; }
   ```
3. `packages/admin/app/offline/db/OfflineDatabase.ts` per plan.md §T-A. Composite keys MUST match the two-axis substrate's `(entity_id, langcode, vid)` tuple (C-002).
4. `packages/admin/app/offline/db/index.ts` — singleton `export const offlineDb = new OfflineDatabase()`. Helper `export async function wipeForAccountSwitch(newAccountUid: number): Promise<void>` deletes the DB and re-opens for the new account (Risks mitigation — per-Nation data leakage via shared profile).

### T-B — Composable shells

1. `packages/admin/app/composables/useOfflineSync.ts` — shell with state stubs. Returns `{state: ref('online'), pendingCount: ref(0), conflictCount: ref(0), retry: async () => {}, resolveConflict: async () => {}}`. Real wiring lands in WP02 + WP04. JSDoc tags `@public`.
2. `packages/admin/app/composables/useOfflineEntity.ts` — `read(entityType, entityId, langcode): Promise<EntityCacheRow | null>`:
   - Query `offlineDb.entities.get([entityType, entityId, langcode])`.
   - If found: consult cached `auth_session.clearanceLevel` against the entity's `classificationLabel`. For this WP, use a placeholder mapping (the real classification → clearance integration uses the classification mission's `ClassificationClearanceCheckerInterface` — port the role-clearance config map into the IndexedDB cache during auth-cache population in WP04). If clearance < label confidentiality → return null (FR-009 / spec.md "Partial trust offline").
   - If not found → return null.

### T-C — Unit tests

1. `packages/admin/tests/unit/offline/db/OfflineDatabaseTest.test.ts` — uses `fake-indexeddb`:
   - Schema migration safety: open db v1 → close → re-open v1 → no errors.
   - Insert + query each table: roundtrip 1 row each; composite-key read works.
   - Multi-row composite-key query: insert 3 entities with same `entityType` but different `entityId` + `langcode`; query by `[entityType, entityId1, 'en']` returns only the en row.
2. `packages/admin/tests/unit/offline/composables/useOfflineEntityTest.test.ts`:
   - Seed two cached entities: `(node, n1, en, classificationLabel='public')` + `(node, n2, en, classificationLabel='confidential')`.
   - Seed `auth_session` with `clearanceLevel=1` (viewer).
   - `useOfflineEntity().read('node', 'n1', 'en')` returns the row.
   - `useOfflineEntity().read('node', 'n2', 'en')` returns null (clearance < confidential).
   - Update `auth_session.clearanceLevel = 10` (admin); re-read → returns the row.

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install`.
2. `npm test packages/admin/tests/unit/offline/`.
3. `npm run typecheck && npm run lint`.
4. `bin/check-dead-code` (admin TS): verify the harness covers TS files (or check that the new files don't trigger frontend dead-code findings).
5. `bin/check-package-layers` (PHP-side green; admin SPA is L6 — no PHP layer change).
6. Verify no PHP changes leaked into this WP — `git diff main -- packages/` should only touch `packages/admin/`.

## Commit + handoff

- `chore(admin): add dexie v4 + fake-indexeddb devDep`
- `feat(admin): OfflineDatabase Dexie schema (5 tables mirroring (entityId,langcode,vid))`
- `feat(admin): useOfflineEntity composable with classification-aware read filter`
- `feat(admin): useOfflineSync composable shell (wiring in WP02/WP04)`
- `test(admin): OfflineDatabase schema + useOfflineEntity classification filter`

```
spec-kitty agent tasks mark-status T-A T-B T-C --status done --mission offline-first-sync-substrate-01KSEFTM
spec-kitty agent tasks move-task WP01 --to for_review --mission offline-first-sync-substrate-01KSEFTM --note "Dexie schema in place mirroring two-axis tuple; composables shell + read filter ready for WP02/WP03/WP04 wiring"
```

## Report back with

1. Commit SHAs.
2. The Dexie schema string (`this.version(1).stores({...})`) so reviewer can verify the composite-key shape matches design-offline-first.md §"Two-Axis Integration".
3. Output of the classification-filter unit test (admin/viewer scenarios — both passing).
4. `npm run typecheck` green.
5. Confirmation `git diff main -- packages/` is `packages/admin/`-only.

## Activity Log
- 2026-05-25T05:46:34Z – unknown – shell_pid=69774 – Dexie schema in place mirroring two-axis tuple (entityId,langcode,vid); 5 tables; useOfflineEntity classification-aware read filter; useOfflineSync shell; 13 vitest tests green (node env, IDBFactory-isolated); typecheck clean on new files; admin-spa.md stamped. Refs gap-matrix-A7, DIR-005.
- 2026-05-25T05:47:13Z – unknown – shell_pid=69774 – Opus review: lane-a disciplined; 5 commits clean (deps + schema + composables + tests + spec stamp); 13 tests pass; DIR-005 preserved (composite PK mirrors two-axis tuple); classification-aware clearance filter present; per-Nation isolation via wipeForAccountSwitch()
