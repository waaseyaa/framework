---
work_package_id: WP03
title: Agent Tool Identity-Key Guard & Structured Errors
dependencies:
- WP01
requirement_refs:
- FR-005
- FR-006
- FR-007
- NFR-002
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T010
- T011
- T012
- T013
- T014
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "15928"
history:
- date: '2026-06-12T01:48:54Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/ai-tools/src/Entity/
execution_mode: code_change
owned_files:
- packages/ai-tools/src/Entity/EntityKeyGuard.php
- packages/ai-tools/src/Entity/EntityCreateTool.php
- packages/ai-tools/src/Entity/EntityUpdateTool.php
- packages/ai-tools/tests/**
tags: []
---

# WP03 — Agent Tool Identity-Key Guard & Structured Errors

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Tracks**: #1646
**Requirements**: FR-005, FR-006, FR-007, NFR-002, NFR-003 | **Dependencies**: WP01 (lane-parallel with WP02)
**Command**: `spec-kitty agent action implement WP03 --agent <name>`

## Objective

Make the stock entity agent tools refuse identity-key writes whole-write, before any construction or mutation, and surface save-time validation failures as deterministic, machine-correctable structured errors. The authoritative behavior spec is `kitty-specs/live-entity-validation-key-protection-01KTWQT3/contracts/tool-refusal.md` — implement to that contract.

## Context (read first)

- `packages/ai-tools/src/Entity/EntityUpdateTool.php:82-87` — sets every string-keyed values entry verbatim via `$entity->set()`. `:93` catches `\Throwable` → flat `AgentToolResult::error(string)`.
- `packages/ai-tools/src/Entity/EntityCreateTool.php:83` — `new $class($values)` raw; `additionalProperties: true` in both input schemas (leave the schemas permissive — refusal is a runtime contract with a good error, not a schema game models can't read).
- `EntityTypeInterface::getKeys()` returns kind→column map (e.g. `['id' => 'nid', 'uuid' => 'uuid', 'label' => 'title', 'langcode' => 'langcode']`).
- `EntityValidationException` (`Waaseyaa\Entity\Validation`) carries `ConstraintViolationListInterface`; violations expose `getPropertyPath()` (field-name prefixed), `getMessage()`, `getInvalidValue()`.
- Inspect `packages/ai-tools/src/AgentToolResult.php` FIRST: if `error()` supports content payloads, attach the JSON payloads below; if not, message-only is acceptable (research D6) — do NOT widen the public API of `AgentToolResult` in this WP.
- Existing tool tests: `rg -l "EntityUpdateTool|EntityCreateTool" packages/ai-tools/tests/` — mirror their fixture/account style.
- Decision D3 (research.md): `id` IS refused on create — agent-created entities get system-assigned identity; the `enforceIsNew()` branch stays for non-tool callers but becomes unreachable via the stock tool.

## Subtasks

### T010 — `EntityKeyGuard`

**File**: `packages/ai-tools/src/Entity/EntityKeyGuard.php` (NEW, `final class`, pure static or instance-free)

```php
/** @return list<string> refused key names found in $values, sorted alphabetically */
public static function refusedKeys(EntityTypeInterface $definition, array $values): array
```

1. Build the refusal set: column names from `getKeys()` for kinds `id`, `uuid`, `revision`, `langcode`, `default_langcode` (skip kinds absent from the map), unioned with literals `uuid`, `langcode`, `default_langcode` (research D4 — the literal floor catches translatable schema columns on types that didn't register the kind).
2. `label` and `bundle` kinds are NEVER in the set — content and create-time structure respectively.
3. Intersect with `array_keys($values)` (string keys only), return sorted.
4. Also provide `public static function refusalError(string $toolName, array $refused): AgentToolResult` producing the contract shape:
   - message: `<toolName>: refused identity keys: <k1>, <k2> — identity fields cannot be written through this tool`
   - payload (if supported per D6): `{ "error": "identity_keys_refused", "refused_keys": [...] }`

**Unit tests** (`packages/ai-tools/tests/Unit/Entity/EntityKeyGuardTest.php`, NEW): every kind refused; renamed id column (`id => nid`) refused under its real name `nid`; literals refused even when kinds unregistered; `label`/`bundle` columns pass; empty values → empty list; sorted output.

### T011 — `EntityCreateTool` refusal + validation mapping

**File**: `packages/ai-tools/src/Entity/EntityCreateTool.php`

1. **Check order** (contract clause 4): capability → argument shape → `hasDefinition` → `requireCreateAccess` → **EntityKeyGuard** → construct/save. Refusal must come after access (no existence/identity probing for unauthorized callers) and before `new $class($values)`.
2. On refusal: return `EntityKeyGuard::refusalError('entity.create', $refused)` — nothing constructed, nothing saved.
3. Add a `catch (EntityValidationException $e)` arm BEFORE the existing `catch (\Throwable)`:
   - message: `entity.create: validation failed: <field>: <message>[; <field>: <message>...]` — violations sorted by field name, then insertion order (NFR-003).
   - payload (if supported): `{ "error": "validation_failed", "violations": [{ "field", "message", "invalid_value_type" }] }` where `invalid_value_type` = `get_debug_type($violation->getInvalidValue())`.
   - Extract the formatting into a shared private/static helper (it's identical in both tools — put it on `EntityKeyGuard` or a small `ValidationErrorFormatter` in the same namespace; either is within owned files).
4. `dryRun()`: run the guard and report the refusal identically (contract clause 5 — a dry run must not claim an invalid call would succeed). Keep the existing dry-run success shape otherwise.
5. The `enforceIsNew()` branch and `revision_log` handling stay untouched (revision_log is content — contract clause 6).

### T012 — `EntityUpdateTool` refusal + validation mapping

**File**: `packages/ai-tools/src/Entity/EntityUpdateTool.php`

Same five points, adapted: guard runs after `requireEntityAccess($entity, 'update', ...)` and BEFORE the `foreach ($values ...)` mutation loop — zero `set()` calls happen on a refused payload (whole-write rejection). The `id` tool argument (locator) is NOT part of `values` and is never refused — only the `values` payload is guarded.

### T013 — Tool unit tests

**Files**: extend `packages/ai-tools/tests/Unit/Entity/EntityCreateToolTest.php` / `EntityUpdateToolTest.php` (or create following the existing naming in that dir).

1. **Short-circuit proof**: refused update → repository `find()` may run (locator) but `save()` is never called AND the loaded entity object is unmutated; refused create → entity class never instantiated (use a stub repository/manager that throws on save, or assert call counts).
2. Error shape: message matches contract exactly, keys sorted; payload present when `AgentToolResult` supports it.
3. Validation mapping: repository stub throwing `EntityValidationException` with two violations (fields deliberately added out of alphabetical order) → message sorted by field, payload shape correct.
4. dry-run refusal for both tools.
5. `revision_log` argument still works on a clean payload.
6. Multiple refused keys in one payload → all named in one error.

### T014 — Dispatch-path integration test

**File**: `packages/ai-tools/tests/Integration/EntityToolRefusalDispatchTest.php` (NEW — or extend the existing dispatch-path test if one exists: `rg -l "dispatch" packages/ai-tools/tests/`).

One end-to-end case through the real tool dispatch surface (the same path the in-app agent/MCP uses, per how existing ai-tools integration tests invoke tools): registered translatable entity type, `entity.update` with `values: {"langcode": "xx"}` → structured refusal; row's langcode unchanged in storage. This is NFR-002's transport-level witness.

## Definition of Done

- [ ] All five subtasks complete; `./vendor/bin/phpunit packages/ai-tools/tests/` green.
- [ ] Every registered identity-key kind covered by a test on BOTH tools (NFR-002: 100%).
- [ ] Check order verified by test (refusal after access denial — an unauthorized caller gets the access error, never the refusal).
- [ ] `composer phpstan`, `composer cs-check` clean; dead-code gate clean (EntityKeyGuard is wired, no `@api` needed).
- [ ] No changes outside `owned_files`; `AgentToolResult` public API unchanged.

## Reviewer guidance

- The short-circuit tests are the contract's teeth — verify they assert *absence* of mutation/instantiation, not just the error string.
- Confirm the guard reads the *registered* key columns (renamed-id case), not just literals.
- Determinism: violation sorting must be by field name with a stable tiebreak; reject `usort` without one.
- Watch for scope creep into #1638 (per-type/per-field write scoping) — this WP protects identity keys only.

## Activity Log

- 2026-06-12T02:40:50Z – claude:fable-5:implementer:implementer – shell_pid=20852 – Started implementation via action command
- 2026-06-12T02:53:57Z – claude:fable-5:implementer:implementer – shell_pid=20852 – Ready for review
- 2026-06-12T02:54:49Z – claude:fable-5:reviewer:reviewer – shell_pid=14752 – Started review via action command
- 2026-06-12T02:59:26Z – claude:fable-5:reviewer:reviewer – shell_pid=14752 – Moved to planned
- 2026-06-12T03:00:01Z – claude:fable-5:implementer:implementer – shell_pid=14224 – Started implementation via action command
- 2026-06-12T03:02:31Z – claude:fable-5:implementer:implementer – shell_pid=14224 – Cycle 2: check-order pinning tests added
- 2026-06-12T03:03:04Z – claude:fable-5:reviewer:reviewer – shell_pid=15928 – Started review via action command
- 2026-06-12T03:04:47Z – claude:fable-5:reviewer:reviewer – shell_pid=15928 – Review passed cycle 2: check-order pinned
- 2026-06-12T03:25:59Z – claude:fable-5:reviewer:reviewer – shell_pid=15928 – Done override: Mission squash-merged to main as 051766833
