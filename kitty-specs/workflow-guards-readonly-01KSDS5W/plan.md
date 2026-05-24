# Implementation Plan: Workflow Guards Read-Only (M4A-5 Phase 1)

**Mission:** `workflow-guards-readonly-01KSDS5W` — see `spec.md`.
**Pattern reference:** M4A-1 workflow definitions admin (PR #1429), M4B WP01 for cross-package resolveOptional.
**Single WP, single PR.**

## WP01 — Read-only matrix surface

### Backend: `packages/workflows`
- `AuthoringRoleMatrix.php` — add `public function snapshot(): array` returning the full mapping ordered by workflow_id, bundle, transition. If the matrix doesn't already expose a per-workflow iteration method, add one too (`forWorkflow(string $workflowId): array`).
- `tests/Unit/AuthoringRoleMatrixTest.php` — extend (or create) to cover `snapshot()` returning seeded entries.

### Backend: `packages/api`
- `Controller/WorkflowGuardsController.php` — `index(string $workflow_id): array`. 404 if not in registry. Returns `{data: [{bundle, transition, required_roles}, ...]}`. Inject `AuthoringRoleMatrix` and the workflow registry (mirror M4A-1's `WorkflowDefinitionController` for the registry service name).
- `Http/Router/WorkflowGuardsApiRouter.php` — mirror `QueueAdminApiRouter`.
- `ApiServiceProvider.php` — fourth `resolveOptional()` block for `AuthoringRoleMatrix` + workflow registry.
- `tests/Unit/Controller/WorkflowGuardsControllerTest.php` — happy path, 404 on unknown workflow.
- `tests/Integration/PhaseWorkflowGuards/WorkflowGuardsEndpointsTest.php` — boot kernel with a seeded matrix; hit endpoint as admin + non-admin; assert 200 / 403.

### Backend: `packages/api/composer.json`
- Add `"waaseyaa/workflows": "^<current-tag>"` to `require` (look at existing constraint floor for siblings). Add `"../workflows"` path repo if not already present. `composer update --lock waaseyaa/workflows`.

### Routes: `packages/foundation`
- `Kernel/BuiltinRouteRegistrar.php` — `api.workflow.guards.index` — `GET /api/workflow-definitions/{workflow_id}/guards`, `_role: admin`, string FQCN `'Waaseyaa\\Api\\Controller\\WorkflowGuardsController'`. Place the block after the notification routes (if those exist by merge time) or after scheduler.

### Frontend: `packages/admin`
- READ FIRST: `app/pages/workflows/[id].vue` (or `[id]/index.vue` — whichever M4A-2 created). Decide whether to add an inline "Guards" section or a tab.
- `app/composables/useWorkflowGuards.ts` — `{guards, loading, error, fetchGuards(workflowId)}`.
- `app/components/workflows/WorkflowGuardsTable.vue` — table with bundle / transition / required-roles chips columns.
- i18n: `guards_title`, `guards_empty`, `guards_column_bundle`, `guards_column_transition`, `guards_column_required_roles`, `guards_help`.
- `tests/unit/composables/useWorkflowGuards.test.ts` — vitest.
- `e2e/workflow-guards.spec.ts` — Playwright smoke: visit `/workflows/{id}`, assert guards table renders.

### Spec stamp + CHANGELOG
- `docs/specs/admin-spa.md` — stamp.
- `CHANGELOG.md` `[Unreleased]` → **Added**: workflow guards matrix visible at `/workflows/{id}`. (#1470)

## Verification gate

In lane worktree:
1. `composer install`
2. `vendor/bin/phpunit packages/workflows/ packages/api/tests/Unit/Controller/WorkflowGuardsControllerTest.php tests/Integration/PhaseWorkflowGuards/`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `cd packages/admin && npm install && npm test && npm run typecheck && npm run lint`
6. Playwright deferred.

## Reviewer focus

- (a) Read-only — no mutate endpoint or UI (C-001).
- (b) Admin-only via route option, not in controller.
- (c) 404 handling when workflow id isn't registered.
- (d) M4A-5b follow-up issue filed before merge.
- (e) Commit footers `Refs #1470` (partial — keeps the issue open until M4A-5b lands).
