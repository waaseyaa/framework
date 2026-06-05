---
work_package_id: WP02
title: RetentionPolicy entity, ClassificationFieldAccessPolicy, RoleBasedClearanceChecker, label registry, JSON:API CRUD routes, legal-hold-bypass permission
dependencies:
- WP01
requirement_refs:
- FR-005
- FR-006
- FR-008
- FR-013
- NFR-002
- NFR-003
- C-002
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-F
- T-G
- T-H
- T-I
- T-J
- T-K
- T-L
history: []
authoritative_surface: packages/field/src/Classification/Policy
execution_mode: code_change
owned_files:
- packages/field/src/Entity/RetentionPolicy.php
- packages/field/migrations/2026_05_25_000004_create_retention_policy_table.php
- packages/field/src/Classification/Policy/ClassificationFieldAccessPolicy.php
- packages/field/src/Classification/ClassificationClearanceCheckerInterface.php
- packages/field/src/Classification/RoleBasedClearanceChecker.php
- packages/field/src/Classification/ClassificationLabelRegistryInterface.php
- packages/field/src/Classification/ClassificationLabelRegistry.php
- packages/field/src/Classification/Permissions.php
- packages/foundation/src/Kernel/BuiltinRouteRegistrar.php
- packages/field/tests/Unit/Classification/Policy/ClassificationFieldAccessPolicyTest.php
- packages/field/tests/Unit/Classification/RoleBasedClearanceCheckerTest.php
- packages/field/tests/Unit/Entity/RetentionPolicyTest.php
tags:
- substrate
- classification
- access-policy
- retention
- hold
agent: "claude:opus:reviewer:reviewer"
shell_pid: "485303"
---

# WP02 — RetentionPolicy entity + ClassificationFieldAccessPolicy + clearance + permissions + JSON:API CRUD

**Mission:** `classification-retention-engine-01KSEFTH`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP01 (must be approved).

## Pattern references — READ FIRST

- `docs/specs/access-control.md` + `docs/specs/field-access.md` — open-by-default semantics; `AccessPolicyInterface` + `FieldAccessPolicyInterface` intersection type; `AccessResult::allowed/neutral/forbidden`.
- `packages/access/src/Gate/PolicyAttribute.php` — registration mechanism. Verify whether `entityType: '*'` is supported; fall back to per-type registration in `FieldServiceProvider` if not.
- `packages/access/src/AccessChecker.php` — composition semantics (multiple policies; entity-level uses `isAllowed()`, field-level uses `!isForbidden()` per CLAUDE.md gotcha).
- `packages/user/src/` and the project's role catalogue file — for permission registration patterns.

## Subtasks

### T-F — `RetentionPolicy` entity + migration
- Entity per CLAUDE.md §"Adding an entity type". Schema per spec.md §In-scope. `@api`.
- Migration creates `retention_policy` + `retention_policy_data` tables.

### T-G — `ClassificationFieldAccessPolicy` (intersection)
- `implements AccessPolicyInterface, FieldAccessPolicyInterface` per `docs/specs/field-access.md`. Class-level `#[PolicyAttribute(entityType: '*')]` (or per-entity-type fallback).
- `access()` (entity-level): on `view`/`update`/`delete`, consult label. `hold-*` without `legal-hold-bypass` → forbidden. clearance < confidentiality → forbidden. Else neutral. `create` → neutral (other policies decide).
- `fieldAccess()` (field-level): same logic — only forbidden/neutral, never allowed (open-by-default).
- Hold takes precedence over clearance (FR-013, C-004): check hold first; if forbidden, return immediately; do NOT then check clearance.

### T-H — `ClassificationClearanceCheckerInterface` + `RoleBasedClearanceChecker`
- Interface `@api`.
- `RoleBasedClearanceChecker`: reads `classification.role_clearance` config; default `{admin: 10, nation-steward: 9, editor: 5, viewer: 1}`. Returns max level across the account's roles; 0 if none match.

### T-I — `ClassificationLabelRegistryInterface` + `ClassificationLabelRegistry`
- `definition(string $labelId): ?ClassificationLabelDefinition`. In-memory cache per-request; cache miss → entity-repository lookup; cache invalidated on entity save.

### T-J — Permissions + role catalogue update
- `packages/field/src/Classification/Permissions.php` — declares `const LEGAL_HOLD_BYPASS = 'legal-hold-bypass'`. `@api`.
- The default role catalogue gains the permission entry; `admin` role does NOT carry it by default; a separate `legal-hold-bypass` role (or `legal-counsel` role) carries it. Exact role-catalogue file location: verify in `packages/user/` or the project's `defaults/`. Coordinate with the existing role catalogue file ownership.

### T-K — JSON:API routes
- `BuiltinRouteRegistrar` additions:
  - `api.classification.policies.index` → `GET /api/classification/policies`, `_role: governance-viewer`.
  - `api.classification.policies.show` → `GET /api/classification/policies/{id}`, `_role: governance-viewer`.
  - `api.classification.policies.create` → `POST /api/classification/policies`, `_role: admin`.
  - `api.classification.policies.update` → `PATCH /api/classification/policies/{id}`, `_role: admin`.
  - `api.classification.policies.delete` → `DELETE /api/classification/policies/{id}`, `_role: admin`.
- Controllers: use the framework's standard `JsonApiController` entity dispatch (no bespoke controller required if the entity is properly registered; verify by checking how `RetentionPolicy` is reachable via the standard `/api/entities/{type}` route — if that suffices, the explicit `/api/classification/policies/*` routes are friendlier URLs).
- The new `governance-viewer` role: add to the default role catalogue with permission `view classification policy`.

### T-L — Unit tests
Per plan.md §T-L. Anonymous classes for intersection types per CLAUDE.md gotcha.

## Verification gate (in lane worktree)

1. `composer install`.
2. `vendor/bin/phpunit packages/field/tests/Unit/Classification/ packages/field/tests/Unit/Entity/RetentionPolicyTest.php`.
3. `composer cs-check && composer phpstan`.
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`.
5. Hold-override smoke (FR-013 / C-004): write a tiny scratch test that seeds a `hold-legal`-labelled entity + an admin account WITHOUT `legal-hold-bypass`; calls `ClassificationFieldAccessPolicy::access()` for `view`; asserts `AccessResult::forbidden()`. Paste this output in the report (the integration test in WP03 will retest end-to-end).

## Commit + handoff

- `feat(field): RetentionPolicy entity + migration`
- `feat(field): ClassificationFieldAccessPolicy (entity + field intersection) with hold-override`
- `feat(field): ClassificationClearanceCheckerInterface + RoleBasedClearanceChecker`
- `feat(field): ClassificationLabelRegistry with per-request cache`
- `feat(field): legal-hold-bypass permission + governance-viewer role`
- `feat(foundation): /api/classification/policies/* JSON:API routes`
- `test(field): access policy + clearance checker + retention policy entity`

```
spec-kitty agent tasks mark-status T-F T-G T-H T-I T-J T-K T-L --status done --mission classification-retention-engine-01KSEFTH
spec-kitty agent tasks move-task WP02 --to for_review --mission classification-retention-engine-01KSEFTH --note "Policy entity + access policy with hold-override + JSON:API CRUD in place"
```

## Report back with

1. Commit SHAs.
2. Hold-override smoke output (paste the `AccessResult::forbidden()` assertion).
3. Confirmation that `ClassificationFieldAccessPolicy` registers via `#[PolicyAttribute]` (or paste the per-type registration block from `FieldServiceProvider` if the wildcard isn't supported).
4. Role-catalogue diff (where `legal-hold-bypass` + `governance-viewer` were added).
5. `bin/check-package-layers` green output.

## Activity Log
- 2026-05-25T20:19:52Z – unknown – Claiming WP02 in lane-a worktree
- 2026-05-25T20:41:07Z – unknown – WP02 implementation complete on lane-a. 7 commits (0a2dd4819..e9855bbe1). Verification gate green: phpunit 455/455 (756 assertions), phpstan clean on WP02 surface, cs-check 0/1781, all check-* scripts pass. Hold-override smoke (FR-013/C-004): admin without legal-hold-bypass blocked from hold-legal entity.
- 2026-05-26T10:43:38Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Started review via action command
- 2026-05-26T10:49:55Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Review passed (claude:reviewer): all 7 criteria met; C-004/FR-013 hold-overrides-clearance enforced & test-proven; policy wired via #[PolicyAttribute]; gates green.
- 2026-05-26T11:17:38Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Done override: Feature squash-merged to main (b170e0a44)
