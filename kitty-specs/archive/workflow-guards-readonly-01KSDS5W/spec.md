# Workflow Guards — Read-Only Matrix (M4A-5 Phase 1)

**Mission:** `workflow-guards-readonly-01KSDS5W`
**Target branch:** `main`
**Tracks:** GitHub issue #1470 (umbrella #1414). Phase 1 only; Phase 2 (edit) deferred per the issue's own recommendation.
**Pattern reference:** M4A-1 workflow definitions admin (PR #1429) for the controller / router / page shape.

## Why

`AuthoringRoleMatrix` (`packages/workflows/src/AuthoringRoleMatrix.php`) holds the `(workflow, bundle, transition) → required roles` mapping in memory. M4A-4's dry-run shows verdicts but hides the rule that produced them. Operators read this matrix by inspecting code today. Phase 1 ships read-only visibility; Phase 2 (edit) needs a persistence design that is out of scope for an admin-SPA mission.

## Scope

### In scope (single WP — Phase 1 only)

- **`packages/workflows/src/AuthoringRoleMatrix.php`:** add `public function snapshot(): array` returning the full mapping as `[{workflow_id, bundle, transition, required_roles: [...]}, ...]`, ordered by workflow_id, bundle, transition. Pure read accessor.
- **`packages/api/src/Controller/WorkflowGuardsController.php`:** `index(string $workflow_id): array` returns `{data: [{bundle, transition, required_roles}, ...]}` filtered to the workflow. 404 if the workflow isn't registered. Inject `AuthoringRoleMatrix` and the workflow registry (read M4A-1's `WorkflowDefinitionController` to find the registry service used there).
- **`packages/api/src/Http/Router/WorkflowGuardsApiRouter.php`:** mirror `QueueAdminApiRouter`. Match `WorkflowGuardsController::`, dispatch `index`. JSON:API error envelope.
- **`packages/api/src/ApiServiceProvider.php`:** add a fourth `resolveOptional()` block for `AuthoringRoleMatrix` + registry. Skip cleanly if absent.
- **`packages/foundation/src/Kernel/BuiltinRouteRegistrar.php`:** `api.workflow.guards.index` — `GET /api/workflow-definitions/{workflow_id}/guards`, `_role: admin`, string FQCN.
- **Frontend:** add a Guards section to the existing `/workflows/{id}` page (created in M4A-2). Read the existing layout first — if it's a single page, add a section/tab; if tabs already exist, add a new "Guards" tab. New composable `useWorkflowGuards()` + i18n keys + vitest + Playwright smoke.
- **Spec stamp** on `docs/specs/admin-spa.md`. **CHANGELOG** `[Unreleased]` → **Added**: `Admin SPA: workflow guards matrix visible at /workflows/{id}. (#1470)`

### Out of scope

- Editing the matrix (Phase 2). Deferred.
- Workflow definition editing.
- Per-account simulation (already covered by M4A-4).

## Requirements

| ID | Priority | Description |
|---|---|---|
| FR-001 | Mandatory | `AuthoringRoleMatrix::snapshot(): array` returns the full mapping ordered by workflow_id, bundle, transition. Read-only, no behavior change. |
| FR-002 | Mandatory | `GET /api/workflow-definitions/{workflow_id}/guards` returns `{data: [{bundle, transition, required_roles}, ...]}` for the named workflow. Admin-only via `_role: admin` route option. |
| FR-003 | Mandatory | The endpoint returns 404 if `$workflow_id` is not registered in the workflow registry. |
| FR-004 | Mandatory | Controller does NOT re-check role (NFR pattern from M4B). |
| FR-005 | Mandatory | `/workflows/{id}` admin page renders the guards matrix (inline section or tab — match existing M4A-2 layout). Columns: bundle, transition, required roles (chip list). Empty state renders. |
| FR-006 | Mandatory | `useWorkflowGuards()` composable is covered by vitest. Playwright smoke verifies the table renders. |
| FR-007 | Mandatory | `docs/specs/admin-spa.md` stamped. `CHANGELOG.md` `[Unreleased]` updated. |
| FR-008 | Mandatory | Phase 2 (edit) follow-up issue filed at WP wrap-up as `M4A-5b`. |
| NFR-001 | Mandatory | Controller / router / composable shapes mirror M4B and M4A-1. |
| NFR-002 | Mandatory | `AuthoringRoleMatrix` and the registry are resolved per-request via container; the controller never references either at construct time of the service provider. |
| C-001 | Constraint | Read-only. No mutate endpoint, no edit UI. |
| C-002 | Constraint | No workflow-definition editing. |

## Acceptance

- All FRs met.
- All gates green: `vendor/bin/phpunit` (mission scope), `composer cs-check`, `composer phpstan`, `bin/check-{package-layers,dead-code,getquery-bindings,composer-policy}`.
- `cd packages/admin && npm test && npm run typecheck && npm run lint` green.
- M4A-5b follow-up issue filed.
- Commit footers `Refs #1470` (partial — issue stays open if Phase 2 still pending).

## Risks

- **Access-control adjacency.** Read-only admin-gated listing is safe; Phase 2 mutation would need the access-control review checklist — which is exactly why Phase 2 is deferred.
- **Workflow registry coupling.** The controller needs both the matrix and the registry. M4A-1's `WorkflowDefinitionController` already injects whatever registry service exists — mirror it.
- **Non-editorial workflows.** If only the editorial workflow has matrix entries, other workflows return `data: []`. Empty state handles it.

## Out-of-band

Follow-up filed at wrap-up:

> **`[admin-spa] M4A-5b: Workflow guard editing UI + persistence design`**
>
> M4A-5 Phase 1 (`workflow-guards-readonly-01KSDS5W`) shipped the read-only matrix at `/workflows/{id}`. Phase 2 — editing — needs a persistence design before any UI work: `AuthoringRoleMatrix` is in-memory only. Tasks:
>
> 1. ADR for matrix persistence (config-sync store vs. new table).
> 2. `PUT /api/workflow-definitions/{workflow_id}/guards` mutation endpoint.
> 3. Inline edit UI with role-multi-select per transition.
> 4. Audit-log integration so changes are traceable.
>
> Parent: #1414. Phase 1 sibling: #1470 (this mission tracks Phase 1).
