---
work_package_id: WP03
title: Per-file AI-access toggle field + admin UI + policy
dependencies: []
requirement_refs:
- FR-008
- FR-009
- FR-010
- FR-011
- FR-012
- FR-013
- NFR-001
- NFR-002
- NFR-004
- C-002
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-per-record-ai-access-flagship-01KSEFT5
base_commit: 6463e7ddfacd57cd606eda5b9f216f3ef16cb280
created_at: '2026-05-25T04:47:41.911842+00:00'
subtasks:
- T008
- T009
- T010
- T011
phase: Phase 1 — Per-file AI toggle
assignee: ''
agent: "claude"
shell_pid: "4172888"
history:
- timestamp: '2026-05-25T02:35:50Z'
  agent: system
  action: Prompt generated via /spec-kitty.tasks
authoritative_surface: packages/field/src/FieldType/AiAccessibleField.php
execution_mode: code_change
owned_files:
- packages/field/src/FieldType/AiAccessibleField.php
- packages/field/src/FieldServiceProvider.php
- packages/access/src/Policy/AiAccessibilityPolicy.php
- packages/media/src/Entity/Media.php
- packages/media/migrations/2026_05_25_000001_add_ai_accessible_to_media.php
- packages/attachment/src/Entity/Attachment.php
- packages/attachment/migrations/2026_05_25_000002_add_ai_accessible_to_attachment.php
- packages/admin/app/components/media/AiAccessibleToggle.vue
- packages/admin/app/i18n/en.json
- packages/field/tests/Unit/FieldType/AiAccessibleFieldTest.php
- packages/access/tests/Unit/Policy/AiAccessibilityPolicyTest.php
- packages/media/tests/Unit/Entity/MediaTest.php
- packages/admin/tests/unit/components/media/AiAccessibleToggle.test.ts
- tests/Integration/PhasePerRecordAiAccess/AiAccessibleToggleTest.php
- docs/specs/access-control.md
- docs/specs/ai-integration.md
tags: []
---

# WP03 — Per-file AI-access toggle field + admin UI + policy (M-A5)

**Mission:** `per-record-ai-access-flagship-01KSEFT5` — closes gap-matrix row **A5** (per-file half). Operational embodiment of charter directive **DIR-004**.
**Requirement refs:** FR-008, FR-009, FR-010, FR-011, FR-012, FR-013, NFR-001, NFR-002, NFR-004, C-002, C-004. See `spec.md` and `plan.md`.

## THE pattern to mirror (read these before writing anything)

This WP adds a first-class per-file "AI accessible: yes/no/inherit" toggle, lands the policy that enforces `'no'` for agent-initiated requests, and ships the admin SPA UI to set it. Default = `'inherit'`; until M-A4 (classification engine) lands, `'inherit'` resolves to `'yes'` to preserve current behaviour.

- READ `packages/field/src/FieldType/*.php` — pick the simplest existing field type (e.g., the boolean / string field) as a template for `AiAccessibleField`.
- READ `packages/field/src/FieldServiceProvider.php` — for the field-type registration mechanism.
- READ `packages/media/src/Entity/Media.php` + `packages/attachment/src/Entity/Attachment.php` — for the `fieldDefinitions()` shape to extend.
- READ `packages/media/migrations/` + `packages/attachment/migrations/` — for the migration pattern; reuse `SqlSchemaHandler` so SQLite + MySQL + PostgreSQL all get the column.
- READ `packages/access/src/Gate/PolicyAttribute.php` + an existing policy class (`packages/access/src/ConfigEntityAccessPolicy.php`) — for the `#[PolicyAttribute(entityType: ...)]` discovery pattern.
- READ `packages/access/src/FieldAccessPolicyInterface.php` + `docs/specs/field-access.md` — for the intersection-type policy pattern (must implement both `AccessPolicyInterface` AND `FieldAccessPolicyInterface`).
- READ `docs/specs/admin-spa.md` for the canonical field-renderer registration mechanism; do NOT invent a new mechanism.
- READ `packages/admin/app/components/` for an existing tri-state / select component to base `AiAccessibleToggle.vue` on.
- READ `packages/admin/app/i18n/en.json` for i18n-key shape conventions.

## What you're building

A typed `ai_accessible` field on every `media` and `attachment` entity, with values `'yes' | 'no' | 'inherit'` and default `'inherit'`. A new `AiAccessibilityPolicy` enforces the toggle at the OCAP layer: when the value is `'no'` AND the request is agent-initiated (per D-D2 — preferred mechanism: request-scope `_agent_run_id`), the policy returns `AccessResult::forbidden()`. A new admin SPA component renders the toggle on media + attachment edit forms.

The dead-code-in-production guard is the WP03 toggle integration test (FR-011), which exercises the whole pipeline end-to-end through WP01's tool boundary wiring (the test fails cleanly with a clear message if WP01 is missing).

## Implementation phases

### T008 — Field type + field registration + migrations (FR-008, FR-009, C-004)

1. Create `packages/field/src/FieldType/AiAccessibleField.php`:
   - `final class AiAccessibleField implements FieldTypeInterface`. `@api`.
   - Persisted values: `'yes'`, `'no'`, `'inherit'`. Default `'inherit'` (per C-004).
   - Validates value ∈ the literal set; rejects others.
   - Outputs JSON Schema for `SchemaPresenter` integration: `{type: "string", enum: ["yes","no","inherit"]}`.
   - Storage column type: `VARCHAR(8)`.
2. Update `packages/field/src/FieldServiceProvider.php` to register the new field type via `FieldTypeManager::addFieldType(new FieldType(id: 'ai_accessible', class: AiAccessibleField::class, ...))`.
3. Update `packages/media/src/Entity/Media.php` → add `ai_accessible` to `fieldDefinitions()`. Label `'AI accessibility'`, default `'inherit'`.
4. Update `packages/attachment/src/Entity/Attachment.php` similarly.
5. Create `packages/media/migrations/2026_05_25_000001_add_ai_accessible_to_media.php` using `SqlSchemaHandler::addColumn()` so all three DB backends are covered.
6. Create `packages/attachment/migrations/2026_05_25_000002_add_ai_accessible_to_attachment.php` similarly.
7. Add `packages/field/tests/Unit/FieldType/AiAccessibleFieldTest.php` covering value validation + default + JSON Schema.
8. Extend `packages/media/tests/Unit/Entity/MediaTest.php` to assert the field is registered.

### T009 — Policy class with intersection types (FR-010, C-002, D-D2)

1. Create `packages/access/src/Policy/AiAccessibilityPolicy.php`:
   - `final class AiAccessibilityPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface`. `@api`.
   - Carries `#[PolicyAttribute(entityType: 'media')]` AND `#[PolicyAttribute(entityType: 'attachment')]`.
   - Constructor takes the request (or a request-scope reader) so it can detect `_agent_run_id` per D-D2 mechanism (b). Per C-002, NO `use Waaseyaa\AI\` imports — read the attribute as a string key from the request.
   - `access(EntityInterface $entity, string $op, AccountInterface $account): AccessResult`:
     - Read `ai_accessible` value off `$entity`.
     - If `'no'` AND request has `_agent_run_id` attribute → `AccessResult::forbidden()`.
     - If `'inherit'` → `AccessResult::neutral()` (defers to other policies; until M-A4 ships, neutral resolves to `'yes'` at the AccessChecker level).
     - If `'yes'` → `AccessResult::neutral()` (does not affirmatively grant; other policies decide).
   - `fieldAccess()` mirrors the same rule per the open-by-default semantics.
2. Confirm the policy is auto-discovered via `#[PolicyAttribute]` + `WaaseyaaEntrypointProvider` (already wired for `bin/check-dead-code` per CLAUDE.md).
3. Add `packages/access/tests/Unit/Policy/AiAccessibilityPolicyTest.php`:
   - `'no'` + agent request → forbidden.
   - `'no'` + non-agent request → neutral.
   - `'yes'` → neutral.
   - `'inherit'` → neutral.
   - `fieldAccess()` mirrors `access()`.
   - Use an anonymous class implementing the intersection type per CLAUDE.md testing gotcha (`createMock` can't mock intersection types).

### T010 — Admin SPA toggle (FR-012)

1. Create `packages/admin/app/components/media/AiAccessibleToggle.vue`:
   - Tri-state control (radio group or select) for `'yes' | 'no' | 'inherit'`.
   - `v-model` bound to the `ai_accessible` field value.
   - Disabled / default state shows `'inherit'`.
   - Use the existing `SchemaField` renderer chain; do NOT invent a new field-renderer mechanism (per the read-first guidance above).
2. Update `packages/admin/app/i18n/en.json` with keys:
   ```
   "media": {
     "ai_accessible": {
       "label": "AI accessibility",
       "help": "Controls whether AI tools may read this file. Inherit defers to the file's classification.",
       "yes": "Yes — AI tools may read this file",
       "no": "No — AI tools may not read this file",
       "inherit": "Inherit from classification"
     }
   }
   ```
3. Add `packages/admin/tests/unit/components/media/AiAccessibleToggle.test.ts` (vitest): tri-state render, change emit, default `'inherit'`.

### T011 — Toggle integration test (FR-011) + spec stamps + CHANGELOG

1. Create `tests/Integration/PhasePerRecordAiAccess/AiAccessibleToggleTest.php`:
   - Boot the kernel; seed a media entity with `ai_accessible = 'no'`.
   - Construct an agent-initiated request context (set `_agent_run_id` per D-D2).
   - Exercise `EntityReadTool::execute()` via `AgentExecutor` against the media entity; assert structured `accessDenied` result.
   - Update the entity: `ai_accessible = 'yes'`.
   - Re-run the tool execution; assert the media entity is returned.
   - This test MUST fail with a clear message if `AiAccessibilityPolicy` is removed OR if the WP01 tool boundary wiring is reverted.
2. Stamp `docs/specs/access-control.md` with `<!-- Spec reviewed YYYY-MM-DD - per-record-ai-access-flagship-01KSEFT5 ... -->` + add "AI access at the tool boundary" section enumerating `ai_accessible` field semantics + `AiAccessibilityPolicy` registration.
3. Stamp `docs/specs/ai-integration.md` similarly + add a "Per-record access" subsection.
4. Update `CHANGELOG.md` `[Unreleased]` → **Added**: `field / media / attachment / access / admin: ai_accessible tri-state field (yes/no/inherit, default inherit) + AiAccessibilityPolicy enforces 'no' for agent-initiated requests; admin SPA toggle on media + attachment edit forms. (gap-matrix-A5)`.
5. Run the WP verification gate (below) and confirm green.

## Verification gate (in lane worktree)

1. `composer install && cd packages/admin && npm install && cd -`
2. `vendor/bin/phpunit packages/field/tests/ packages/access/tests/ packages/media/tests/ packages/attachment/tests/ tests/Integration/PhasePerRecordAiAccess/AiAccessibleToggleTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`
5. `cd packages/admin && npm test && npm run typecheck && npm run lint`

## Commit + handoff

Commits (footer `Refs gap-matrix-A5` on each):
- `feat(field): AiAccessibleField tri-state field type (gap-matrix-A5)`
- `feat(media,attachment): register ai_accessible field + migrations (gap-matrix-A5)`
- `feat(access): AiAccessibilityPolicy enforces 'no' for agent-initiated requests (gap-matrix-A5)`
- `feat(admin): AiAccessibleToggle component + i18n keys (gap-matrix-A5)`
- `test(integration): AiAccessibleToggleTest as FR-011 dead-code guard (gap-matrix-A5)`
- `docs(specs): stamp access-control + ai-integration for M-A5 (gap-matrix-A5)`

Then:
```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T008 T009 T010 T011 --status done --mission per-record-ai-access-flagship-01KSEFT5
spec-kitty agent tasks move-task WP03 --to for_review --mission per-record-ai-access-flagship-01KSEFT5 --note "WP03 per-file AI toggle wired; FR-011 toggle test verified to fail without AiAccessibilityPolicy"
```

## Report back with

- D-D2 choice: mechanism (a) `AccountInterface::isAgentInitiated()` or (b) request-scope `_agent_run_id` + 1-sentence rationale.
- The exact migration files written (paths + column DDL).
- Confirmation FR-011 test was run with `AiAccessibilityPolicy` removed and observed to fail; separately confirm it fails when WP01 wiring is reverted (cross-WP integration check).
- Confirmation `bin/check-package-layers` is green (no L1 → L5 imports introduced).
- Confirmation no `use Waaseyaa\AI\` import exists in `packages/access/src/Policy/AiAccessibilityPolicy.php`.
- Any deviation from the `'inherit' → 'yes' until M-A4'` semantics + why.

## Activity Log

- 2026-05-25T04:47:43Z – claude – shell_pid=4172888 – Assigned agent via action command
- 2026-05-25T05:24:36Z – claude – shell_pid=4172888 – All gates green: PHPStan clean, CS 0/1704, bin/check-* OK (layers, dead-code, getquery-bindings). 80 WP03 PHP tests pass with bootstrap-worktree. 11 integration tests pass. 276/276 Vitest pass (43 files). AiAccessibilityPolicy covers media+attachment with no AI-layer imports (C-002). Default inherit preserves access-preserving default (C-004). Refs gap-matrix-A5, DIR-004.
- 2026-05-25T05:26:26Z – claude – shell_pid=4172888 – Opus review: lane-c work disciplined; 5 commits cleanly structured per WP03 plan (T008 field+entity, T009 AiAccessibilityPolicy intersection-type with #[PolicyAttribute], T010 admin Vue toggle, T011 integration tests + worktree bootstrap). Bonus: subagent fixed (void) cast syntax in TwoAxisAccessPolicyIntegrationTest that I missed. 276/276 Vitest pass, all bin/check-* clean.
- 2026-05-26T18:52:45Z – claude – shell_pid=4172888 – Done override: Sprint merge to main
