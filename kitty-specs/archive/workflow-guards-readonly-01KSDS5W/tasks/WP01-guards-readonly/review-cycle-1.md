# WP01 Review — Cycle 1 (REJECTED)

**Reviewer:** opus (orchestrator + reviewer)
**Verdict:** REJECTED — dead code in production

## What's right

The PHP and frontend code is structurally sound:
- `AuthoringRoleMatrix::snapshot()`, `forWorkflow()`, `knownWorkflowIds()` correctly typed, deterministic ordering, backward-compatible constructor (optional `$workflowGuards` defaulting to `[]`).
- `WorkflowGuardsController` mirrors `WorkflowDefinitionsController` for registry-lookup symmetry.
- `WorkflowGuardsApiRouter` mirrors `QueueAdminApiRouter` cleanly.
- `BuiltinRouteRegistrar` route block uses string FQCN (layer-safe).
- Frontend page, composable, table component, i18n keys, vitest, Playwright spec all in place and pattern-matched to M4B.
- All gates green: phpunit (104+6+6 = 116 mission tests), phpstan, cs-check, layers, dead-code, getquery-bindings, composer-policy. npm test + typecheck + lint clean.
- M4A-5b follow-up #1579 filed correctly.

## What's WRONG — the blocker

**`AuthoringRoleMatrix` is NEVER constructed in production code.** I grep'd:

```
$ grep -rn 'new AuthoringRoleMatrix\|->singleton.*AuthoringRoleMatrix\|AuthoringRoleMatrix::class' packages/
# only matches: the class definition itself, the unit/integration/controller tests, and ApiServiceProvider::resolveOptional()
```

`packages/workflows/src/WorkflowServiceProvider.php` registers only the Workflow entity type. It does NOT bind `AuthoringRoleMatrix` in the container. Consequence:

1. `ApiServiceProvider::resolveOptional(AuthoringRoleMatrix::class)` throws → returns null
2. `WorkflowGuardsApiRouter` is never appended to `$routers`
3. Routes ARE still registered in `BuiltinRouteRegistrar`, but with no router matching `WorkflowGuardsController::*`, the dispatcher will return a generic 404
4. **`/workflows/{id}` admin page shows an empty guards section in every real install**

The feature is dead-code-in-production. The spec-kitty-implement-review skill calls this out as the most common review failure: *"A module with passing tests but no callers is NOT implemented. Tests pass but the feature is never invoked from the live command path."*

## Required changes (cycle 2)

1. **Bind `AuthoringRoleMatrix` in `WorkflowServiceProvider::register()`** as a container singleton. Seed the `$workflowGuards` constructor arg from the canonical existing data — that's `EditorialTransitionAccessResolver::TRANSITION_ROLE_MATRIX` per your own implementation report, keyed under the editorial workflow id (likely `'editorial'` — match `EditorialWorkflowPreset::create()->id()`).

   Sketch:
   ```php
   $this->singleton(AuthoringRoleMatrix::class, fn(): AuthoringRoleMatrix => new AuthoringRoleMatrix(
       bundles: [/* application-provided bundles */],
       roles: [/* role definitions */],
       workflowGuards: [
           EditorialWorkflowPreset::create()->id() => EditorialTransitionAccessResolver::TRANSITION_ROLE_MATRIX,
       ],
   ));
   ```
   Use whatever the `EditorialTransitionAccessResolver::TRANSITION_ROLE_MATRIX` shape actually is — the constructor expects `[transition_id => list<role>]`, so reshape inside the closure if needed.

   The `bundles` and `roles` may need to come from a config/registry — if no such surface exists yet, pass sensible defaults (or empty arrays) and add a one-line comment noting the application is expected to override.

2. **Add a regression test under `tests/Integration/PhaseWorkflowGuards/`** (or extend the existing one) that:
   - Boots a real kernel (no anonymous-class matrix injection).
   - Hits `GET /api/workflow-definitions/{id}/guards` for the editorial workflow id.
   - Asserts `data` is NON-EMPTY and contains rows for the documented editorial transitions.

   This regression test must FAIL on the current cycle-1 code (because the binding doesn't exist) and PASS after the binding lands. That's the smoke test for "the feature actually runs in production."

3. **Update the kitty-specs and the M4A-5b follow-up issue** if the binding design surfaces something Phase 2 needs to know (e.g. "if you persist the matrix, the WorkflowServiceProvider binding switches from a constant to a repository read").

## What does NOT need to change

- Public API of `WorkflowGuardsController`, `WorkflowGuardsApiRouter`, the frontend, i18n, or spec stamp. All structurally correct.
- The optional-constructor-arg design on `AuthoringRoleMatrix`. That's the right backward-compat strategy.
- The closure-based workflow registry pattern. Mirroring `WorkflowDefinitionsController` is correct.

## How to verify

After the fix:
1. `grep -rn 'new AuthoringRoleMatrix\|->singleton.*AuthoringRoleMatrix' packages/workflows/src/` MUST show the binding.
2. Manual: `composer dev`, browse `/workflows/editorial`, the guards section must show non-empty rows.
3. The new kernel-boot integration test must pass on the cycle-2 head and FAIL on the cycle-1 head (sanity check).

Cycle 1/3.
