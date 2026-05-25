# Work Packages: offline-first-sync-substrate-01KSEFTM

**Mission:** Offline-first sync substrate (gap-matrix A7; alpha-to-beta-plan §1 item #6). See `spec.md`, `plan.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` + `classification-retention-engine-01KSEFTH` both MUST be merged.

Four WPs, sequential. WP02 depends on WP01; WP03 depends on WP01; WP04 depends on WP02 + WP03.

## Work Package WP01: Dexie schema + OfflineDatabase + base composables

**Owns:** `packages/admin/package.json` (Dexie dep), `packages/admin/app/offline/db/*`, `packages/admin/app/composables/useOfflineSync.ts` (shell), `packages/admin/app/composables/useOfflineEntity.ts`, unit tests.
**Depends on:** none (assumes audit + classification missions merged).
**Blocks:** WP02, WP03, WP04.
**Authoritative surface:** `packages/admin/app/offline/db/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-001, FR-009, FR-011, NFR-001, NFR-003, C-001, C-002.
**Subtasks:** T-A (Dexie setup), T-B (composable shell), T-C (tests).
**Prompt:** `tasks/WP01-dexie-schema-and-composables.md`.

## Work Package WP02: Workbox + service worker + FSM + ConflictResolver + SyncEngine + Mercure extension

**Owns:** `packages/admin/sw.ts`, `packages/admin/nuxt.config.ts` (PWA module + manifest), `packages/admin/app/offline/sync/*`, `packages/admin/app/composables/useOfflineRealtime.ts`, extension of `packages/admin/app/composables/useRealtime.ts`, `packages/admin/public/offline.html`, unit tests.
**Depends on:** WP01.
**Blocks:** WP04.
**Authoritative surface:** `packages/admin/app/offline/sync/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-002, FR-003, FR-004, FR-005, FR-008, FR-011, NFR-001, NFR-004, NFR-005, C-002, C-003, C-004.
**Subtasks:** T-D (service worker + Workbox + PWA manifest), T-E (FSM), T-F (ConflictResolver classification-aware), T-G (SyncEngine), T-H (Mercure consumer extension), T-I (unit tests).
**Prompt:** `tasks/WP02-workbox-fsm-and-sync-engine.md`.

## Work Package WP03: Server-side sync endpoints + Mercure event + classification policy meta

**Owns:** `packages/api/src/Sync/{SyncAcknowledgeController,OfflineBatchAuditController}.php`, `packages/api/src/Http/Router/SyncApiRouter.php`, `packages/api/src/ApiServiceProvider.php` (wiring), `packages/api/composer.json` (require-dev for audit), `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (routes), `packages/mercure/src/MercurePublisher.php` (sync.conflict event), unit tests.
**Depends on:** WP01 (needs the SyncStateMachine wire contract).
**Blocks:** WP04.
**Authoritative surface:** `packages/api/src/Sync/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-006, FR-007, FR-008, FR-014, NFR-002, NFR-003, C-001, C-004.
**Subtasks:** T-J (acknowledge controller), T-K (audit-batch controller), T-L (Mercure sync.conflict event), T-M (classification-policy meta hints).
**Prompt:** `tasks/WP03-server-side-sync-endpoints.md`.

## Work Package WP04: Auth offline guard + status badge + integration test + Playwright + docs

**Owns:** `packages/admin/app/offline/auth/OfflineAuthGuard.ts`, `packages/admin/app/components/offline/OfflineStatusBadge.vue`, `packages/admin/app/i18n/en.json` (additions), `packages/admin/app/layouts/default.vue` (badge mount), `packages/admin/tests/integration/offline/OfflineSyncIntegrationTest.test.ts`, `packages/admin/e2e/offline-sync.spec.ts`, `docs/specs/offline-first-sync.md`, `CLAUDE.md` (orchestration row), `CHANGELOG.md`.
**Depends on:** WP02, WP03.
**Blocks:** none.
**Authoritative surface:** `packages/admin/app/offline/auth/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-009, FR-010, FR-012, FR-013, FR-015, NFR-002, NFR-003, C-005.
**Subtasks:** T-N (auth guard), T-O (status badge + i18n + mount), T-P (FR-012 integration test), T-Q (FR-013 Playwright deferred), T-R (docs + CHANGELOG).
**Prompt:** `tasks/WP04-auth-guard-badge-tests-and-docs.md`.

## Mission-level acceptance

- All 15 FRs / 5 NFRs / 5 constraints honoured.
- `git diff main -- packages/entity-storage/` empty (DIR-005 / C-002 axis preservation).
- FR-012 vitest integration test passes (full offline → write → reconnect → conflict → resolve → drain → online cycle).
- `cd packages/admin && npm test && npm run typecheck && npm run lint && npm run build` green.
- Reviewer confirms multi-submission-merge as default for governed data (C-003) and the SW does NOT enforce access policy (C-004).
- Downstream per-surface missions (Drive WP01-offline, Forms WP03-offline, Docs WP03-offline per alpha-to-beta-plan §2) are unblocked.
- Cross-mission verification: offline ops audit-events show up in the OCAP audit substrate's `/api/audit/events` listing on reconnect (proving NFR-002 integration).
