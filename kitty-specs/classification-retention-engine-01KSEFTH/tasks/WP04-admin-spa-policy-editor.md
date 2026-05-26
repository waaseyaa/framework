---
work_package_id: WP04
title: Admin SPA retention-policy editor — useRetentionPolicies composable, list + detail pages, nav entry, i18n, docs, CHANGELOG
dependencies:
- WP02
requirement_refs:
- FR-014
- FR-016
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T-S
- T-T
history: []
authoritative_surface: packages/admin/app/pages/classification/policies
execution_mode: code_change
owned_files:
- packages/admin/app/composables/useRetentionPolicies.ts
- packages/admin/app/pages/classification/policies/index.vue
- packages/admin/app/pages/classification/policies/[id].vue
- packages/admin/app/i18n/en.json
- packages/admin/tests/unit/composables/useRetentionPolicies.test.ts
- packages/admin/e2e/classification-policies.spec.ts
- docs/specs/classification-and-retention.md
- CLAUDE.md
- CHANGELOG.md
tags:
- admin-spa
- classification
- schema-form
- frontend
agent: "claude:opus:reviewer:reviewer"
shell_pid: "485303"
---

# WP04 — Admin SPA retention-policy editor + docs

**Mission:** `classification-retention-engine-01KSEFTH`
**Spec:** [../spec.md](../spec.md) | **Plan:** [../plan.md](../plan.md)
**Depends on:** WP02 (needs JSON:API endpoint + JSON-Schema for `RetentionPolicy`). May proceed in parallel with WP03.

## Pattern references — READ FIRST

- `packages/admin/app/composables/useQueueJobs.ts` — composable shape (`{data, loading, error, fetch...}` + `useApi`).
- `packages/admin/app/composables/useSchema.ts` + `SchemaForm.vue` / `SchemaField.vue` / `SchemaView.vue` (per CLAUDE.md `packages/admin` orchestration row) — schema-driven rendering.
- Existing workflow-definition editor page (if any in `packages/admin/app/pages/workflows/`) for the create/edit page pattern.
- How `/queue` and `/notifications` register their static nav entries (from M5A WP02 reference) — mirror for a "Governance" group → `/classification/policies`.

## Subtasks

### T-S — Composable + pages + nav + i18n

**Composable** `packages/admin/app/composables/useRetentionPolicies.ts`:
```ts
export function useRetentionPolicies() {
  const policies = ref<RetentionPolicy[]>([])
  const loading = ref(false)
  const error = ref<Error | null>(null)
  const api = useApi()
  async function fetchPolicies() { ... }
  async function savePolicy(policy: RetentionPolicy) { ... }
  async function deletePolicy(id: string) { ... }
  return { policies, loading, error, fetchPolicies, savePolicy, deletePolicy }
}
```
Mirror `useQueueJobs.ts` error-handling.

**List page** `packages/admin/app/pages/classification/policies/index.vue`:
- Summary cards: total policies; by-action breakdown (purge / redact / hold-flag counts).
- Table: name, applies_to (labels), action, trigger_kind, trigger_value, action buttons (edit, delete).
- Empty state.
- Calls `fetchPolicies()` on mount.

**Detail/edit page** `packages/admin/app/pages/classification/policies/[id].vue`:
- Fetches policy by `id` (or `id === 'new'` for create).
- Uses `SchemaForm` against the `RetentionPolicy` JSON-Schema fetched from `/api/schemas/retention_policy` (verify endpoint shape via `SchemaPresenter`).
- Submit → `savePolicy()`; success → navigate back to list.
- Delete button on edit-mode → confirms then `deletePolicy()`.

**Nav entry:** Add a "Governance" group entry → `/classification/policies` mirroring how `/queue` is registered (READ the existing nav source — grep for `/queue` in `packages/admin/app/` — and add adjacent).

**i18n keys** in `packages/admin/app/i18n/en.json`: `classification_policies_title`, `classification_policies_empty`, `classification_policy_name`, `classification_policy_action_purge`, `..._action_redact`, `..._action_hold_flag`, `classification_policy_applies_to`, `classification_policy_trigger_kind`, `classification_policy_trigger_value`, `classification_policy_exemptions`, `classification_policy_edit`, `classification_policy_delete`, `classification_policy_confirm_delete`.

### T-T — Tests + docs + CHANGELOG

- `packages/admin/tests/unit/composables/useRetentionPolicies.test.ts` — vitest covering fetch / save / delete / error.
- `packages/admin/e2e/classification-policies.spec.ts` — Playwright smoke: visit `/classification/policies`; assert title + table render. Deferred run per lane-worktree limitation.
- `docs/specs/classification-and-retention.md` — new spec file with sections: Overview, Why, Architecture (field type + label-definition entity + retention-policy entity + scheduled jobs + access policy composition), Schema (all three entity schemas), Inheritance semantics, Access composition (hold-override rules), Retention semantics (purge / redact / hold-flag), Scheduled jobs (cadences + best-effort), Permissions (`legal-hold-bypass`, `governance-viewer`), Admin UI, Cross-references to OCAP audit substrate. ~300–500 lines.
- `CLAUDE.md` orchestration table — add row `packages/field/src/Classification/*` → `docs/specs/classification-and-retention.md` AND `packages/field/src/Entity/RetentionPolicy.php` → same spec.
- `CHANGELOG.md` `[Unreleased]` → **Added**: `Classification + retention engine (packages/field): classification_label field type with parent-inheritance, ClassificationLabelDefinition entity (9 seed labels), RetentionPolicy entity, ClassificationFieldAccessPolicy honouring open-by-default with hold-override semantics, three scheduled jobs (6-hourly purge + redact, daily hold-scan), legal-hold-bypass permission, admin policy editor. Closes gap-matrix A4 / alpha-to-beta-plan §1 item #4. (classification-retention-engine-01KSEFTH)`.

## Verification gate (in lane worktree)

1. `cd packages/admin && npm install`.
2. `npm test && npm run typecheck && npm run lint`.
3. `bin/check-no-secrets` (docs change should be clean).
4. From repo root: `composer cs-check` for PHP-side files touched (none in this WP except docs/CHANGELOG/CLAUDE.md).
5. `tools/drift-detector.sh` if present.

## Commit + handoff

- `feat(admin): useRetentionPolicies composable`
- `feat(admin): classification-policies list + detail pages with SchemaForm`
- `feat(admin): Governance nav entry + i18n keys for classification policies`
- `test(admin): useRetentionPolicies vitest + Playwright smoke`
- `docs(specs): classification-and-retention.md + CLAUDE.md orchestration row + CHANGELOG`

```
spec-kitty agent tasks mark-status T-S T-T --status done --mission classification-retention-engine-01KSEFTH
spec-kitty agent tasks move-task WP04 --to for_review --mission classification-retention-engine-01KSEFTH --note "Admin policy editor + docs ready; Playwright smoke deferred (lane worktree)"
```

## Report back with

1. Commit SHAs.
2. Which file/mechanism registers the "Governance" nav entry (and how `/queue` does it).
3. `npm test && npm run typecheck` green.
4. The exact JSON-Schema URL the detail page fetches (`/api/schemas/retention_policy` or whichever — verify by inspecting the SchemaPresenter output).
5. CLAUDE.md orchestration-table diff (the row added).

## Activity Log
- 2026-05-25T22:05:54Z – unknown – Admin SPA retention-policy editor: useRetentionPolicies composable + list/detail pages (SchemaForm) + Governance nav + i18n + vitest + deferred playwright spec + docs/spec/CLAUDE.md/CHANGELOG. Admin gate green: vitest 279/279, typecheck clean, lint 0 errors. Also greened 3 pre-existing oidc typecheck/lint failures (useI18n->useLanguage, void->unknown) that predated WP04 on the rebase base.
- 2026-05-26T10:43:42Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Started review via action command
- 2026-05-26T10:49:58Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Review passed (claude:reviewer): composable hits WP02 routes; pages reachable; Governance nav wired; i18n complete; vue-tsc+eslint+vitest green.
- 2026-05-26T11:17:44Z – claude:opus:reviewer:reviewer – shell_pid=485303 – Done override: Feature squash-merged to main (b170e0a44)
