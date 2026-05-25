# Work Packages: versioned-blob-media-abstraction-01KSEFTJ

**Mission:** Versioned-blob media abstraction (gap-matrix A1; alpha-to-beta-plan §1 item #5). See `spec.md`, `plan.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` MUST be merged. `classification-retention-engine-01KSEFTH` SHOULD be merged before WP02 begins.

Four WPs, sequential. WP02 depends on WP01; WP03 depends on WP01 + WP02; WP04 depends on WP03.

## Work Package WP01: MediaVersion entity + CAS decorator + repository

**Owns:** `packages/media/src/Version/{MediaVersion,MediaVersionType,MediaVersionRepository,ContentAddressedFileRepositoryDecorator}.php`, `packages/media/src/File/FileWriteResult.php`, `packages/media/src/MediaServiceProvider.php`, the new migration, unit tests.
**Depends on:** none (assumes audit + classification missions are merged or aware-of).
**Blocks:** WP02, WP03, WP04.
**Authoritative surface:** `packages/media/src/Version/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-001, FR-002, FR-006, FR-007, NFR-001, NFR-003, NFR-005, C-002, C-003.
**Subtasks:** T-A (entity + migration), T-B (CAS decorator + FileWriteResult), T-C (repository + access-filtered queries), T-D (unit tests).
**Prompt:** `tasks/WP01-media-version-entity-and-cas.md`.

## Work Package WP02: Storage-driver hook + audit-enum amendment + cascade-delete + classification parent-resolver

**Owns:** `packages/media/src/Version/{MediaVersionStorageDriver,MediaCascadeDeleteSubscriber}.php`, `packages/media/src/Version/Classification/MediaVersionParentResolver.php`, `packages/audit/src/Enum/AuditEventKind.php` (additive amendment — coordinated with the audit substrate mission), `packages/audit/tests/Unit/Enum/AuditEventKindAmendmentTest.php`, unit tests for the driver + cascade.
**Depends on:** WP01.
**Blocks:** WP03.
**Authoritative surface:** `packages/media/src/Version/MediaVersionStorageDriver.php`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-003, FR-004, FR-005, FR-011, NFR-002, NFR-003, C-001, C-004.
**Subtasks:** T-E (storage-driver hook), T-F (audit-enum amendment + tests in `packages/audit/`), T-G (cascade-delete subscriber), T-H (classification parent-resolver), T-I (unit tests).
**Prompt:** `tasks/WP02-storage-driver-hook-and-audit-amendment.md`.

## Work Package WP03: JSON:API surface + integration tests

**Owns:** `packages/api/src/Media/{MediaVersionReadModelInterface,MediaVersionResource,ApiMediaVersionAdapter}.php`, `packages/api/src/Controller/MediaVersionController.php`, `packages/api/src/Http/Router/MediaVersionApiRouter.php`, `packages/api/src/ApiServiceProvider.php` (wiring), `packages/api/composer.json` (require-dev), `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (routes), unit + integration tests.
**Depends on:** WP01, WP02.
**Blocks:** WP04.
**Authoritative surface:** `packages/api/src/Media/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-008, FR-009, FR-010, FR-013, FR-014, NFR-002, NFR-004, NFR-005, C-002.
**Subtasks:** T-J (read-model + adapter), T-K (controller + router + provider wiring), T-L (foundation routes), T-M (unit + dedup + forbidden-version integration tests).
**Prompt:** `tasks/WP03-api-surface-and-integration-tests.md`.

## Work Package WP04: Admin SPA browser + docs + CHANGELOG

**Owns:** `packages/admin/app/composables/useMediaVersions.ts`, `packages/admin/app/components/media/MediaVersionBrowser.vue`, `packages/admin/app/pages/media/[id].vue` (integration), `packages/admin/app/i18n/en.json` (additions), `packages/admin/tests/unit/composables/useMediaVersions.test.ts`, `packages/admin/e2e/media-versions.spec.ts`, `docs/specs/versioned-blob-media.md`, `docs/specs/entity-storage-two-axis.md` (cross-ref append), `CLAUDE.md` (orchestration row), `CHANGELOG.md`.
**Depends on:** WP03.
**Blocks:** none.
**Authoritative surface:** `packages/admin/app/components/media/MediaVersionBrowser.vue`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-012, FR-015, NFR-003.
**Subtasks:** T-N (composable + component + page integration), T-O (tests + docs + CHANGELOG).
**Prompt:** `tasks/WP04-admin-spa-version-browser-and-docs.md`.

## Mission-level acceptance

- All 15 FRs / 5 NFRs / 5 constraints honoured.
- `git diff main -- packages/entity-storage/` empty (DIR-005 axis preservation — C-003).
- Integration test `MediaVersioningIntegrationTest` passes; dedup + audit-event count correct (FR-013).
- `ForbiddenVersionIntegrationTest` passes; admin sees all, non-admin sees filtered list (FR-014).
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Reviewer confirms the audit-enum amendment is additive and the OCAP audit substrate's tests still pass.
- Downstream `offline-first-sync-substrate-01KSEFTM` (WP01 — Drive metadata) and `per-record-ai-access-flagship-*` are unblocked.
