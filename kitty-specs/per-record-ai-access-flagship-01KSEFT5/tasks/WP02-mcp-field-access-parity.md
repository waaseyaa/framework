---
work_package_id: WP02
title: MCP serializer field-access wiring + JSON:API parity
dependencies: []
requirement_refs:
- FR-005
- FR-006
- FR-007
- FR-013
- NFR-001
- NFR-002
- NFR-004
- C-002
- C-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-per-record-ai-access-flagship-01KSEFT5
base_commit: 6463e7ddfacd57cd606eda5b9f216f3ef16cb280
created_at: '2026-05-25T04:47:29.763234+00:00'
subtasks:
- T005
- T006
- T007
phase: Phase 1 — MCP serializer OCAP
assignee: ''
agent: "claude:sonnet:implementer:implementer"
shell_pid: "4172252"
history:
- timestamp: '2026-05-25T02:35:50Z'
  agent: system
  action: Prompt generated via /spec-kitty.tasks
authoritative_surface: packages/mcp/src/Serializer/McpEntityFieldFilter.php
execution_mode: code_change
owned_files:
- packages/mcp/src/Tools/EntityTools.php
- packages/mcp/src/Serializer/McpEntityFieldFilter.php
- packages/mcp/src/McpServiceProvider.php
- packages/access/src/EntityAccessHandler.php
- packages/mcp/tests/Unit/Serializer/McpEntityFieldFilterTest.php
- packages/mcp/tests/Unit/Tools/EntityToolsTest.php
- tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php
- docs/specs/mcp-endpoint.md
- docs/specs/field-access.md
tags: []
---

# WP02 — MCP serializer field-access wiring + JSON:API parity (M-A5)

**Mission:** `per-record-ai-access-flagship-01KSEFT5` — closes gap-matrix row **A5** (MCP serializer half). Operational embodiment of charter directive **DIR-004**.
**Requirement refs:** FR-005, FR-006, FR-007, FR-013, NFR-001, NFR-002, NFR-004, C-002, C-003. See `spec.md` and `plan.md`.

## THE pattern to mirror (read these before writing anything)

This WP closes the silent governance gap where the MCP endpoint emits more entity data than JSON:API does for the same caller. The fix is to share the field-policy consultation site between the two surfaces.

- READ `packages/api/src/Serializer/ResourceSerializer.php` — for how JSON:API consults `FieldAccessPolicyInterface` via `EntityAccessHandler`. This is the canonical call site to mirror in MCP.
- READ `packages/access/src/EntityAccessHandler.php` — for `filterFields()` (or whatever method JSON:API calls). If it's not already a public first-class method, extracting it from `ResourceSerializer` is part of this WP.
- READ `packages/access/src/FieldAccessPolicyInterface.php` + `docs/specs/field-access.md` — for the open-by-default semantics: Neutral/Allowed → exposed; Forbidden → redacted.
- READ `packages/mcp/src/Tools/EntityTools.php` — for the current entity-to-MCP-response shaping site.
- READ `packages/mcp/src/McpServiceProvider.php` — for the binding shape; ensure the new filter is wired through the kernel-resolved `EntityAccessHandler` (not a fresh instance).
- READ `packages/mcp/tests/Unit/Fixtures/PermissionAwareNodeVisibilityPolicy.php` — for the test-fixture policy pattern WP02 tests should mirror.
- READ `docs/specs/mcp-endpoint.md` — for the canonical MCP response shape this WP extends.

## What you're building

Today an MCP caller can receive entity fields a `FieldAccessPolicyInterface` would have forbidden through JSON:API. WP02 wires the same field-policy consultation into the MCP entity serializer, with a redaction-shape contract that preserves partial-disclosure semantics: forbidden fields are replaced by `{accessRestricted: true, reason: "field_forbidden_for_account"}`, not silently dropped (DIR-004 audit-lineage rule) and not 403'd (partial-disclosure violation).

The dead-code-in-production guard is the WP02 parity integration test (FR-007). Reviewer MUST verify the test fails when the wiring is reverted.

## Implementation phases

### T005 — Field-filter helper + service binding (FR-005, FR-006)

1. Confirm whether `EntityAccessHandler::filterFields($entity, $op, $account): array` exists as a public method. If yes, reuse. If no, extract the per-field loop from `ResourceSerializer` into `EntityAccessHandler::filterFields()` so both JSON:API and MCP consume one helper. Keep semantics intact (Neutral/Allowed = field present, Forbidden = field absent in the returned array). `@api`.
2. Create `packages/mcp/src/Serializer/McpEntityFieldFilter.php`:
   - `final class McpEntityFieldFilter` carrying `@api`.
   - Constructor receives `EntityAccessHandler`.
   - `applyTo(array $serializedEntity, EntityInterface $entity, AccountInterface $account): array` returns the entity with forbidden fields replaced by the canonical redaction marker `['accessRestricted' => true, 'reason' => 'field_forbidden_for_account']` in `attributes` (mirror JSON:API's attributes-vs-relationships layout if the MCP shape splits them; otherwise apply uniformly).
3. Update `packages/mcp/src/McpServiceProvider.php` to bind `McpEntityFieldFilter` and resolve `EntityAccessHandler` from the kernel (no local construction).
4. Update `packages/mcp/src/Tools/EntityTools.php` (and any sibling entity-emitting tool under `packages/mcp/src/Tools/`) to run every entity-shaped response through `McpEntityFieldFilter::applyTo()` before returning.

### T006 — Unit tests (FR-005, FR-006)

1. Add `packages/mcp/tests/Unit/Serializer/McpEntityFieldFilterTest.php`:
   - Allowed / Neutral field → value preserved.
   - Forbidden field → replaced by `{accessRestricted: true, reason: "field_forbidden_for_account"}`.
   - Entity envelope unchanged (no whole-entity 403).
2. Extend `packages/mcp/tests/Unit/Tools/EntityToolsTest.php` with a field-policy fixture (mirror `PermissionAwareNodeVisibilityPolicy`). Assert the redaction marker appears in the tool result.

### T007 — Parity integration test (FR-007) + spec stamps + CHANGELOG

1. Create `tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php`:
   - Boot the kernel; register a `FieldAccessPolicy` that returns `AccessResult::forbidden()` for field `body` on entity type `node` for account X.
   - As account X, exercise both surfaces against the same node:
     - MCP: call the `entity.read` tool path; assert the response includes `attributes.body == {accessRestricted: true, reason: "field_forbidden_for_account"}`.
     - JSON:API: `GET /api/node/{id}`; assert the response's `attributes` object does NOT contain `body`.
   - Assert the set of reachable field-keys is identical between the two surfaces, modulo the redaction marker's presence on the MCP side.
   - This test MUST fail with a clear message if `EntityTools` is reverted to skipping the filter.
2. Stamp `docs/specs/mcp-endpoint.md` with `<!-- Spec reviewed YYYY-MM-DD - per-record-ai-access-flagship-01KSEFT5 ... -->` and add a "serializer redaction shape" section documenting the canonical redaction marker.
3. Stamp `docs/specs/field-access.md` and add an "MCP parity" section explaining the asymmetric shape (JSON:API omits, MCP redacts — both compliant with open-by-default; MCP redacts to preserve audit lineage for callers that need to know something was withheld).
4. Update `CHANGELOG.md` `[Unreleased]` → **Added**: `mcp: FieldAccessPolicyInterface enforced in entity serializer; forbidden fields replaced by {accessRestricted: true, reason: "field_forbidden_for_account"} marker; JSON:API ↔ MCP field parity tested. (gap-matrix-A5)`.
5. Run the WP verification gate (below) and confirm green.

## Verification gate (in lane worktree)

1. `composer install`
2. `vendor/bin/phpunit packages/mcp/tests/ packages/access/tests/ tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php`
3. `composer cs-check && composer phpstan`
4. `bin/check-package-layers && bin/check-dead-code && bin/check-getquery-bindings && bin/check-composer-policy`

## Commit + handoff

Commits (footer `Refs gap-matrix-A5` on each):
- `feat(access): public EntityAccessHandler::filterFields() helper (gap-matrix-A5)` (only if extracted)
- `feat(mcp): McpEntityFieldFilter wires FieldAccessPolicyInterface into entity serializer (gap-matrix-A5)`
- `test(integration): McpJsonApiFieldParityTest as FR-007 dead-code guard (gap-matrix-A5)`
- `docs(specs): stamp mcp-endpoint + field-access for M-A5 (gap-matrix-A5)`

Then:
```
cd /home/fsd42/dev/waaseyaa
spec-kitty agent tasks mark-status T005 T006 T007 --status done --mission per-record-ai-access-flagship-01KSEFT5
spec-kitty agent tasks move-task WP02 --to for_review --mission per-record-ai-access-flagship-01KSEFT5 --note "WP02 MCP serializer wired; FR-007 parity test verified to fail without McpEntityFieldFilter"
```

## Report back with

- Whether `EntityAccessHandler::filterFields()` was reused as-is or extracted from `ResourceSerializer`.
- The exact redaction-marker shape used (must match `{accessRestricted: true, reason: "field_forbidden_for_account"}`).
- Confirmation FR-007 test was run with the wiring reverted and observed to fail.
- Field-set parity confirmation: the two surfaces agree on which fields are reachable for the same caller against the same entity.
- Any unexpected drift between JSON:API field-omission and MCP redaction semantics + how it was resolved.

## Activity Log

- 2026-05-25T04:47:31Z – claude:sonnet:implementer:implementer – shell_pid=4172252 – Assigned agent via action command
- 2026-05-25T05:05:52Z – claude:sonnet:implementer:implementer – shell_pid=4172252 – WP02 MCP serializer wired; FR-007 parity test verified to fail without McpEntityFieldFilter; McpEntityFieldFilter, EntityTools two-step serialization, and McpJsonApiFieldParityTest all committed
- 2026-05-25T05:06:39Z – claude:sonnet:implementer:implementer – shell_pid=4172252 – Opus review: lane-b work disciplined. Two-step serialization (entity-level via JsonApiController, then ResourceSerializer unfiltered, then McpEntityFieldFilter redaction) avoids double-filtering. REDACTION_MARKER shape matches FR-002/C-003. FR-007 dead-code-guard test present at tests/Integration/PhasePerRecordAiAccess/McpJsonApiFieldParityTest.php — verifies parity between MCP and JSON:API surfaces.
- 2026-05-26T18:52:43Z – claude:sonnet:implementer:implementer – shell_pid=4172252 – Done override: Sprint merge to main
