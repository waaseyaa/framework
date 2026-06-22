---
work_package_id: WP01
title: Read-only workflow guards matrix surface
dependencies: []
requirement_refs:
- FR-001
- FR-002
- FR-003
- FR-004
- FR-005
- FR-006
- FR-007
- FR-008
- NFR-001
- NFR-002
- C-001
- C-002
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-workflow-guards-readonly-01KSDS5W
base_commit: 65ce669f65aace0f6bb34fb0dc0ed47f52abf36c
created_at: '2026-05-24T20:07:31.282344+00:00'
subtasks: []
shell_pid: "412258"
history: []
authoritative_surface: packages/api/src/Controller/WorkflowGuardsController.php
execution_mode: code_change
owned_files:
- packages/workflows/src/AuthoringRoleMatrix.php
- packages/workflows/tests/Unit/AuthoringRoleMatrixTest.php
- packages/api/src/Controller/WorkflowGuardsController.php
- packages/api/src/Http/Router/WorkflowGuardsApiRouter.php
- packages/api/src/ApiServiceProvider.php
- packages/api/tests/Unit/Controller/WorkflowGuardsControllerTest.php
- packages/api/composer.json
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- tests/Integration/PhaseWorkflowGuards/WorkflowGuardsEndpointsTest.php
- packages/admin/app/composables/useWorkflowGuards.ts
- packages/admin/app/components/workflows/WorkflowGuardsTable.vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useWorkflowGuards.test.ts
- packages/admin/e2e/workflow-guards.spec.ts
- docs/specs/admin-spa.md
- CHANGELOG.md
tags: []
agent: "claude:opus:reviewer:reviewer"
---

# WP01 — Read-only workflow guards matrix surface (M4A-5 Phase 1)

**Mission:** `workflow-guards-readonly-01KSDS5W` (#1470 Phase 1)
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Pattern reference (CANONICAL):** M4A-1 workflow definitions admin (PR #1429) — `WorkflowDefinitionController`, `pages/workflows/index.vue`. AND M4B WP01 (squash `f0317b429`) — `QueueController` for the controller-router-resolveOptional layout.

## CRITICAL — work in the lane worktree

```
cd /home/jones/dev/waaseyaa/.worktrees/workflow-guards-readonly-01KSDS5W-lane-a
```

(Path reported by `spec-kitty agent action implement WP01`.)

## Critical context

- Lane worktree needs `composer install` + `cd packages/admin && npm install` first.
- READ M4A-1's `WorkflowDefinitionController.php` to find which **workflow registry service** to inject. Mirror that injection.
- READ M4A-2's `/workflows/[id]` page layout to decide inline-section vs tab. Mirror.
- `_role: admin` enforced at route level only. Controller does NOT re-check.
- This is READ-ONLY (C-001). No mutate endpoint, no edit UI. Phase 2 is deferred.
- File the M4A-5b follow-up issue BEFORE moving to for_review.

## Subtasks

**T001 — `AuthoringRoleMatrix::snapshot()`**
- Add `public function snapshot(): array` returning the full mapping as `[{workflow_id, bundle, transition, required_roles: [...]}, ...]`, ordered by workflow_id, bundle, transition.
- If the matrix has no per-workflow iteration method, add `public function forWorkflow(string $workflowId): array` too — the controller will use it.
- Extend `tests/Unit/AuthoringRoleMatrixTest.php` (create if missing): seed entries, assert `snapshot()` and `forWorkflow()` shapes.

**T002 — API controller + router + service provider + composer**
- `WorkflowGuardsController.php` — `index(string $workflow_id): array`. Pull the workflow registry to verify the id; 404 if not registered. Return `{data: $this->matrix->forWorkflow($workflow_id)}` mapped to `[{bundle, transition, required_roles}, ...]`. Inject `AuthoringRoleMatrix` + the registry.
- `WorkflowGuardsApiRouter.php` — mirror `QueueAdminApiRouter`. Match `WorkflowGuardsController::`. JSON:API error envelope.
- `ApiServiceProvider.php` — fourth `resolveOptional()` block: resolve `AuthoringRoleMatrix::class` and the workflow registry. Skip if absent.
- `packages/api/composer.json` — add `"waaseyaa/workflows": "^<current-tag>"` (match existing sibling floor) and `"../workflows"` path repo. `composer update --lock waaseyaa/workflows`.

**T003 — Routes + tests**
- `BuiltinRouteRegistrar.php` — `api.workflow.guards.index` — `GET /api/workflow-definitions/{workflow_id}/guards`, `_role: admin`, string FQCN.
- `WorkflowGuardsControllerTest.php` — happy path, 404 unknown workflow. Anonymous-class fakes for matrix + registry.
- `tests/Integration/PhaseWorkflowGuards/WorkflowGuardsEndpointsTest.php` — boot kernel, seed matrix entries, hit endpoint as admin + non-admin, assert shape + 403.

**T004 — Frontend + spec stamp + CHANGELOG + follow-up**
- READ `app/pages/workflows/[id].vue` (M4A-2). Add a "Guards" section or tab depending on existing layout.
- `app/composables/useWorkflowGuards.ts` — `{guards, loading, error, fetchGuards(workflowId)}`. Mirror `useQueueJobs.ts`.
- `app/components/workflows/WorkflowGuardsTable.vue` — table with bundle, transition, required-roles (chips) columns. Empty state.
- i18n: `guards_title`, `guards_empty`, `guards_column_bundle`, `guards_column_transition`, `guards_column_required_roles`, `guards_help`.
- `tests/unit/composables/useWorkflowGuards.test.ts` — vitest.
- `e2e/workflow-guards.spec.ts` — Playwright smoke.
- `docs/specs/admin-spa.md` — stamp.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Admin SPA: workflow guards matrix visible at /workflows/{id}. (#1470)`
- File the M4A-5b follow-up issue:
  ```
  gh issue create \
    --title "[admin-spa] M4A-5b: Workflow guard editing UI + persistence design" \
    --label admin-spa,audit-followup \
    --body "<text from spec.md Out-of-band section>"
  ```

## Verification gate

In the lane worktree:
1. `composer install`
2. `cd packages/admin && npm install && cd -`
3. `vendor/bin/phpunit packages/workflows/`
4. `vendor/bin/phpunit packages/api/tests/Unit/Controller/WorkflowGuardsControllerTest.php`
5. `vendor/bin/phpunit tests/Integration/PhaseWorkflowGuards/`
6. `composer cs-check && composer phpstan`
7. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
8. `cd packages/admin && npm test && npm run typecheck && npm run lint`
9. Playwright deferred.

## Commit + handoff

- Commits:
  - `feat(workflows): AuthoringRoleMatrix::snapshot() accessor (#1470)`
  - `feat(api): workflow guards admin controller + routes (#1470)`
  - `feat(admin): /workflows/{id} guards section (#1470)`
  - `docs(specs): admin-spa.md stamp + CHANGELOG (#1470)`
- Every commit footer: `Refs #1470`.
- Then:
  ```
  cd /home/jones/dev/waaseyaa
  spec-kitty agent tasks mark-status T001 T002 T003 T004 --status done --mission workflow-guards-readonly-01KSDS5W
  spec-kitty agent tasks move-task WP01 --to for_review --mission workflow-guards-readonly-01KSDS5W --note "M4A-5 Phase 1 ready; M4A-5b follow-up #<N> filed for Phase 2 edit"
  ```

## Report back with

1. Commit SHAs.
2. Whether you added `forWorkflow()` to the matrix (yes if there wasn't already a per-workflow iteration method).
3. Which workflow registry service M4A-1 injects.
4. Whether you added an inline section or a tab on `/workflows/[id]`.
5. M4A-5b follow-up issue URL.

## Activity Log

- 2026-05-24T20:07:32Z – claude:sonnet:implementer:implementer – shell_pid=379607 – Assigned agent via action command
- 2026-05-24T20:25:56Z – claude:sonnet:implementer:implementer – shell_pid=379607 – M4A-5 Phase 1 ready; M4A-5b follow-up #1579 filed for Phase 2 edit. Playwright deferred (lane worktree limitation per CLAUDE.md gotcha).
- 2026-05-24T20:26:55Z – claude:opus:reviewer:reviewer – shell_pid=397177 – Started review via action command
- 2026-05-24T20:29:00Z – claude:opus:reviewer:reviewer – shell_pid=397177 – Moved to planned
- 2026-05-24T20:29:51Z – claude:sonnet:implementer:implementer – shell_pid=399835 – Started implementation via action command
- 2026-05-24T20:37:50Z – claude:sonnet:implementer:implementer – shell_pid=399835 – Cycle 2 — bound AuthoringRoleMatrix in WorkflowServiceProvider; new kernel-boot integration test verifies the dashboard returns non-empty data
- 2026-05-24T20:54:11Z – claude:opus:reviewer:reviewer – shell_pid=412258 – Started review via action command
- 2026-05-24T20:54:51Z – claude:opus:reviewer:reviewer – shell_pid=412258 – Review passed cycle 2 (opus). Dead-code-in-production blocker fixed: WorkflowServiceProvider now binds AuthoringRoleMatrix as singleton with editorial guards re-derived from EditorialTransitionAccessResolver::allowedRolesForTransition() (single source of truth preserved, no constant duplication). DEFAULT_BUNDLE_SENTINEL = '*' acceptable for Phase 1 read-only surface. Phase 2 transition path documented in inline PHPDoc (repository-backed swap-in, same binding shape). Kernel-boot integration test asserts non-empty data and confirmed FAILS on cycle-1 head (3 errors, 0 assertions) and PASSES on cycle-2 head (3 tests, 62 assertions). All gates green: 119/119 tests, phpstan/cs-check/layers/dead-code/getquery/composer-policy clean. Sanity grep confirms ->singleton(AuthoringRoleMatrix::class, ...) present.
- 2026-05-24T20:59:34Z – claude:opus:reviewer:reviewer – shell_pid=412258 – Done override: Mission squash-merged to main with conflict resolution against M4C + queue-listjobs landings
