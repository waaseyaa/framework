# Work Packages: classification-retention-engine-01KSEFTH

**Mission:** Classification + retention engine (gap-matrix A4; alpha-to-beta-plan §1 item #4). See `spec.md`, `plan.md`.
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` MUST be merged before WP01 begins.

Four WPs. WP01 ships the field type + label inheritance. WP02 ships the policy entity + access policy. WP03 ships the scheduled jobs + FR-015 integration test. WP04 ships the Admin SPA editor. WP02 depends on WP01; WP03 depends on WP01 + WP02; WP04 depends on WP02.

## Work Package WP01: Classification field type + label catalogue + inheritance resolver

**Owns:** `packages/field/src/Classification/*` (resolver + decision + parent resolvers + lifecycle subscriber + widget), `packages/field/src/Entity/ClassificationLabelDefinition.php`, `packages/field/migrations/2026_05_25_000003_*.php`, `packages/field/defaults/classification-labels.yaml`, `packages/field/src/Attribute/FieldTemplate.php` (additive `pii` parameter), `packages/field/src/FieldServiceProvider.php` (bindings + entity-type registration).
**Depends on:** `ocap-audit-log-substrate-01KSEFTF` merged.
**Blocks:** WP02, WP03.
**Authoritative surface:** `packages/field/src/Classification/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-001, FR-002, FR-003, FR-004, FR-007, NFR-001, NFR-005, C-001, C-002, C-005.
**Subtasks:** T-A (field type), T-B (label-definition entity + seed YAML), T-C (resolver + parent-resolver interface + three stock impls), T-D (lifecycle subscriber writing labels + dispatching classification.change audit events), T-E (unit tests).
**Prompt:** `tasks/WP01-classification-field-and-inheritance.md`.

## Work Package WP02: Retention-policy entity + access policy + clearance checker + JSON:API CRUD

**Owns:** `packages/field/src/Entity/RetentionPolicy.php`, `packages/field/migrations/2026_05_25_000004_*.php`, `packages/field/src/Classification/Policy/*`, `packages/field/src/Classification/ClassificationClearanceCheckerInterface.php` + `RoleBasedClearanceChecker.php`, `packages/field/src/Classification/ClassificationLabelRegistry*.php`, `packages/field/src/Classification/Permissions.php`, `packages/foundation/src/Kernel/BuiltinRouteRegistrar.php` (route additions).
**Depends on:** WP01.
**Blocks:** WP03, WP04.
**Authoritative surface:** `packages/field/src/Classification/Policy/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-005, FR-006, FR-008, FR-013, NFR-002, NFR-003, C-002, C-004.
**Subtasks:** T-F (entity + migration), T-G (access policy), T-H (clearance checker), T-I (label registry), T-J (permissions + role catalogue update), T-K (JSON:API routes), T-L (unit tests).
**Prompt:** `tasks/WP02-retention-policy-and-access.md`.

## Work Package WP03: Scheduled retention jobs + integration test

**Owns:** `packages/field/src/Classification/Schedule/*`, `packages/field/src/Classification/Job/*`, `packages/field/tests/Unit/Classification/Schedule/*` + `Job/*`, `tests/Integration/PhaseClassificationRetention/ClassificationRetentionIntegrationTest.php`.
**Depends on:** WP01, WP02.
**Blocks:** none (WP04 can parallel).
**Authoritative surface:** `packages/field/src/Classification/Job/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-009, FR-010, FR-011, FR-012, FR-015, NFR-004, C-003, C-004.
**Subtasks:** T-M (schedule entries), T-N (purge job), T-O (redact job), T-P (hold-scan job), T-Q (unit + best-effort tests), T-R (FR-015 integration test with dead-code guard).
**Prompt:** `tasks/WP03-scheduled-retention-jobs.md`.

## Work Package WP04: Admin SPA retention-policy editor

**Owns:** `packages/admin/app/composables/useRetentionPolicies.ts`, `packages/admin/app/pages/classification/policies/{index.vue,[id].vue}`, `packages/admin/app/i18n/en.json` (additions), `packages/admin/tests/unit/composables/useRetentionPolicies.test.ts`, `packages/admin/e2e/classification-policies.spec.ts`, `docs/specs/classification-and-retention.md`, `CLAUDE.md` (orchestration row), `CHANGELOG.md`.
**Depends on:** WP02 (needs the policy entity + JSON-schema endpoint).
**Blocks:** none.
**Authoritative surface:** `packages/admin/app/pages/classification/policies/`.
**Execution mode:** `code_change`.
**Requirement refs:** FR-014, FR-016, NFR-003.
**Subtasks:** T-S (composable + pages + nav), T-T (tests + docs + CHANGELOG).
**Prompt:** `tasks/WP04-admin-spa-policy-editor.md`.

## Mission-level acceptance

- All 16 FRs / 5 NFRs / 5 constraints in `spec.md` honoured.
- `bin/waaseyaa schedule:list` shows the three classification.retention.* tasks.
- `bin/waaseyaa config:import packages/field/defaults/classification-labels.yaml` idempotent.
- Integration test passes; reviewer verifies hold-block dead-code guard.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- Gates green: cs-check, phpstan, layers, dead-code, getquery-bindings, composer-policy.
- Downstream `per-record-ai-access-flagship-*`, `versioned-blob-media-abstraction-01KSEFTJ`, and `offline-first-sync-substrate-01KSEFTM` missions unblocked.
