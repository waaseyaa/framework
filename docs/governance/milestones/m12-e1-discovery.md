# M12-E1 Discovery Pass — Admin SPA Surface Review

## Discovery Baseline

- Branch: `m12-e1-admin-spa-hardening`
- Milestone slice: `M12-E1`
- Discovery constraint: observation only, no implementation changes
- Requested review paths `packages/admin/src/runtime/*`, `packages/admin/src/composables/*`, and `packages/admin/src/components/*` do not exist in the current repo.
- The live admin SPA implementation is mounted from `packages/admin/app/*`, which matches [admin-spa.md](/home/jones/dev/waaseyaa/docs/specs/admin-spa.md).
- The path mismatch is recorded below as a `spec-clarification` finding.

## Reviewed Surfaces

### Runtime and bootstrap surfaces reviewed
- `packages/admin/app/plugins/admin.ts`
- `packages/admin/app/middleware/auth.global.ts`

### Composable surfaces reviewed
- `packages/admin/app/composables/useAdmin.ts`
- `packages/admin/app/composables/useApi.ts`
- `packages/admin/app/composables/useAuth.ts`
- `packages/admin/app/composables/useEntity.ts`
- `packages/admin/app/composables/useLanguage.ts`
- `packages/admin/app/composables/useNavGroups.ts`
- `packages/admin/app/composables/useRealtime.ts`
- `packages/admin/app/composables/useSchema.ts`
- `packages/admin/app/composables/useCodifiedContext.ts`
- `packages/admin/app/composables/useEntityPipeline.ts`

### Component surfaces reviewed
- `packages/admin/app/components/IngestSummaryWidget.vue`
- `packages/admin/app/components/auth/BrandPanel.vue`
- `packages/admin/app/components/auth/ForgotPasswordForm.vue`
- `packages/admin/app/components/auth/LoginForm.vue`
- `packages/admin/app/components/auth/RegisterForm.vue`
- `packages/admin/app/components/auth/ResetPasswordForm.vue`
- `packages/admin/app/components/auth/VerificationBanner.vue`
- `packages/admin/app/components/layout/AdminShell.vue`
- `packages/admin/app/components/layout/NavBuilder.vue`
- `packages/admin/app/components/onboarding/OnboardingPrompt.vue`
- `packages/admin/app/components/pipeline/EntityViewNav.vue`
- `packages/admin/app/components/pipeline/PipelineCard.vue`
- `packages/admin/app/components/pipeline/PipelineColumn.vue`
- `packages/admin/app/components/pipeline/PipelineDetailPanel.vue`
- `packages/admin/app/components/schema/SchemaField.vue`
- `packages/admin/app/components/schema/SchemaForm.vue`
- `packages/admin/app/components/schema/SchemaList.vue`
- `packages/admin/app/components/schema/SchemaView.vue`
- `packages/admin/app/components/telescope/ContextHeatmap.vue`
- `packages/admin/app/components/telescope/DriftScoreChart.vue`
- `packages/admin/app/components/telescope/EventStreamViewer.vue`
- `packages/admin/app/components/telescope/ValidationReportCard.vue`
- `packages/admin/app/components/widgets/DateTimeInput.vue`
- `packages/admin/app/components/widgets/EntityAutocomplete.vue`
- `packages/admin/app/components/widgets/FileUpload.vue`
- `packages/admin/app/components/widgets/HiddenField.vue`
- `packages/admin/app/components/widgets/MachineNameInput.vue`
- `packages/admin/app/components/widgets/NumberInput.vue`
- `packages/admin/app/components/widgets/RichText.vue`
- `packages/admin/app/components/widgets/Select.vue`
- `packages/admin/app/components/widgets/TextArea.vue`
- `packages/admin/app/components/widgets/TextInput.vue`
- `packages/admin/app/components/widgets/Toggle.vue`

### Test surfaces reviewed
- `packages/admin/tests/setup.ts`
- `packages/admin/tests/pages/dashboard.test.ts`
- `packages/admin/tests/composables/useAuth.spec.ts`
- `packages/admin/tests/components/auth/BrandPanel.spec.ts`
- `packages/admin/tests/components/auth/ForgotPasswordForm.spec.ts`
- `packages/admin/tests/components/auth/LoginForm.spec.ts`
- `packages/admin/tests/components/auth/RegisterForm.spec.ts`
- `packages/admin/tests/components/auth/ResetPasswordForm.spec.ts`
- `packages/admin/tests/components/auth/VerificationBanner.spec.ts`
- `packages/admin/tests/components/layout/AdminShell.test.ts`
- `packages/admin/tests/components/layout/NavBuilder.test.ts`
- `packages/admin/tests/components/schema/SchemaField.test.ts`
- `packages/admin/tests/components/schema/SchemaForm.test.ts`
- `packages/admin/tests/components/widgets/FileUpload.test.ts`
- `packages/admin/tests/components/widgets/Select.test.ts`
- `packages/admin/tests/components/widgets/TextInput.test.ts`
- `packages/admin/tests/components/widgets/Toggle.test.ts`
- `packages/admin/tests/unit/adapters/AdminSurfaceTransportAdapter.test.ts`
- `packages/admin/tests/unit/adapters/BootstrapAuthAdapter.test.ts`
- `packages/admin/tests/unit/adapters/JsonApiTransportAdapter.test.ts`
- `packages/admin/tests/unit/composables/useAdmin.test.ts`
- `packages/admin/tests/unit/composables/useApi.test.ts`
- `packages/admin/tests/unit/composables/useAuth.test.ts`
- `packages/admin/tests/unit/composables/useEntity.test.ts`
- `packages/admin/tests/unit/composables/useLanguage.test.ts`
- `packages/admin/tests/unit/composables/useNavGroups.test.ts`
- `packages/admin/tests/unit/composables/useRealtime.test.ts`
- `packages/admin/tests/unit/composables/useSchema.test.ts`
- `packages/admin/tests/unit/i18n/entityTypeLabels.test.ts`
- `packages/admin/tests/unit/plugins/admin.test.ts`
- `packages/admin/tests/fixtures/entityTypes.ts`
- `packages/admin/tests/fixtures/schemas.ts`
- `packages/admin/tests/vue-shim.d.ts`

## Findings

### F1 — execution-plan path mismatch
- Classification: `spec-clarification`
- Surfaces:
  - `packages/admin/app/*`
  - `docs/specs/admin-spa.md`
  - `#1021`
- Observation:
  - The governed E1 issue and the requested discovery paths refer to `packages/admin/src/*`, but the live Nuxt source tree is rooted at `packages/admin/app/*`.
  - The current spec already reflects `app/` as the active source directory.
  - This makes the execution-plan path vocabulary inconsistent with the implementation baseline.

### F2 — admin runtime availability is an implicit composable precondition
- Classification: `composable-contract`
- Surfaces:
  - `packages/admin/app/composables/useAdmin.ts`
  - `packages/admin/app/composables/useEntity.ts`
  - `packages/admin/app/composables/useSchema.ts`
  - `packages/admin/app/plugins/admin.ts`
- Observation:
  - These composables cast `useNuxtApp()` directly to `{ $admin: AdminRuntime }` and assume a fully bootstrapped runtime.
  - There is no explicit null or unavailable-runtime contract in the composable API.
  - Runtime absence is therefore handled as an implicit crash boundary rather than an explicit invariant.

### F3 — runtime auth state is split across two sources of truth
- Classification: `runtime-consistency`
- Surfaces:
  - `packages/admin/app/plugins/admin.ts`
  - `packages/admin/app/composables/useAuth.ts`
  - `packages/admin/app/middleware/auth.global.ts`
- Observation:
  - The plugin builds `$admin.account` from `/admin/_surface/session`, while `useAuth()` maintains a separate `useState()` account based on `/api/user/me`.
  - The route middleware consults `$admin` for runtime presence but uses `useAuth()` for embedded strategy and verified-email decisions.
  - That creates a dual-state model where session identity and auth state can diverge without a documented reconciliation rule.

### F4 — public-route detection is implemented with different matching rules in different runtime paths
- Classification: `runtime-consistency`
- Surfaces:
  - `packages/admin/app/plugins/admin.ts`
  - `packages/admin/app/middleware/auth.global.ts`
- Observation:
  - The client-side plugin skips bootstrap when `window.location.pathname` ends with a public auth path.
  - The server-side plugin branch and route middleware use exact-path inclusion checks.
  - The access decision therefore depends on where matching occurs and how the path is normalized.

### F5 — pipeline navigation visibility depends on network side effects during component mount
- Classification: `component-invariant`
- Surfaces:
  - `packages/admin/app/components/layout/NavBuilder.vue`
  - `packages/admin/app/composables/useEntity.ts`
  - `packages/admin/tests/components/layout/NavBuilder.test.ts`
- Observation:
  - `NavBuilder` discovers pipeline availability by calling `runAction(type, 'board-config')` for every catalog entry inside `onMounted()`.
  - The component silently treats failures as "no pipeline" and performs discovery sequentially.
  - The navigation invariant is therefore derived from side-effecting runtime requests rather than a stable declared capability.

### F6 — form widget coordination relies on string-key provide/inject contracts
- Classification: `component-invariant`
- Surfaces:
  - `packages/admin/app/components/schema/SchemaForm.vue`
  - `packages/admin/app/components/widgets/MachineNameInput.vue`
- Observation:
  - `SchemaForm` provides `schemaFormData` and `schemaFormEditMode` under plain string keys.
  - `MachineNameInput` injects those keys and degrades to a dev-only warning when they are missing.
  - The widget contract is implicit, untyped at the injection boundary, and not enforced by runtime guarantees.

### F7 — user-facing text remains partially outside the translation layer
- Classification: `component-invariant`
- Surfaces:
  - `packages/admin/app/components/layout/AdminShell.vue`
  - `packages/admin/app/components/layout/NavBuilder.vue`
  - `packages/admin/app/components/schema/SchemaForm.vue`
  - `packages/admin/app/components/schema/SchemaView.vue`
  - `packages/admin/app/components/auth/VerificationBanner.vue`
- Observation:
  - Several user-facing strings remain literal rather than routed through `useLanguage()`.
  - Examples observed during the sweep include `Skip to main content`, `Pipeline`, `Failed to load entity`, `Save failed`, `Hide`, `Show`, `Please verify your email address.`, `Email sent — check your inbox.`, and `Dismiss`.
  - The UI therefore mixes translated and non-translated render paths inside the same surface.

### F8 — realtime behavior drift was stale and is now closed by verification
- Classification: `runtime-consistency`
- Surfaces:
  - `packages/admin/app/composables/useRealtime.ts`
  - `packages/admin/app/components/schema/SchemaList.vue`
  - `packages/admin/tests/unit/composables/useRealtime.test.ts`
- Observation:
  - Follow-up verification on 2026-04-02 confirmed the original drift is no longer present.
  - `useRealtime()` targets the canonical `/api/broadcast` SSE endpoint, `SchemaList` conditionally consumes it behind `enableRealtime`, and unit tests assert the canonical endpoint plus the default `admin` channel.
  - No implementation change was required for F8; the finding is closed as a stale discovery observation.

### F9 — degraded runtime-state coverage was backfilled and is now closed
- Classification: `test-gap`
- Surfaces:
  - `packages/admin/tests/unit/plugins/admin.test.ts`
  - `packages/admin/tests/unit/composables/useAdmin.test.ts`
  - `packages/admin/tests/unit/composables/useEntity.test.ts`
  - `packages/admin/tests/unit/composables/useSchema.test.ts`
  - `packages/admin/tests/components/layout/NavBuilder.test.ts`
- Observation:
  - Follow-up work on 2026-04-02 added focused degraded-state coverage for:
    - explicit admin-runtime invariant failures in `useAdmin()`, `useEntity()`, and `useSchema()`
    - empty-catalog rendering in `NavBuilder`
    - client-side public-auth-route bootstrap skip in the admin plugin
    - 401 session bootstrap state fallback, missing catalog bootstrap state fallback, and unreachable surface API handling in the admin plugin
  - The original F9 gap is closed for the audited surfaces.

### F10 — admin bootstrap test fixtures encode broad default capabilities that may mask navigation and action assumptions
- Classification: `test-gap`
- Surfaces:
  - `packages/admin/tests/setup.ts`
  - `packages/admin/tests/fixtures/entityTypes.ts`
  - `packages/admin/tests/components/layout/NavBuilder.test.ts`
  - `packages/admin/tests/pages/dashboard.test.ts`
- Observation:
  - Shared fixtures give most catalog entries a wide-open capability set and a uniform successful bootstrap.
  - That keeps tests stable, but it also reduces pressure on capability-specific branches and empty/partial catalog behaviors.
  - As a result, some admin surface assumptions are validated indirectly rather than explicitly.

## Discovery Classification Summary

- `runtime-consistency`
  - F3, F4
- `composable-contract`
  - F2
- `component-invariant`
  - F5, F6, F7
- `test-gap`
  - F10
- `spec-clarification`
  - F1

## Notes

- No implementation changes were made during this discovery pass.
- No proposed fixes are included in this log.
- The findings above are observations intended to guide the next M12-E1 execution-planning refinement step.
