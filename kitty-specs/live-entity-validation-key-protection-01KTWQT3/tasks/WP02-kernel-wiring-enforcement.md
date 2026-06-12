---
work_package_id: WP02
title: Kernel Wiring & Framework-Wide Enforcement
dependencies:
- WP01
requirement_refs:
- FR-001
- FR-008
- NFR-001
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T006
- T007
- T008
- T009
agent: "claude:fable-5:implementer:implementer"
shell_pid: "8928"
history:
- date: '2026-06-12T01:48:54Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/foundation/src/Kernel/
execution_mode: code_change
owned_files:
- packages/foundation/src/Kernel/AbstractKernel.php
- tests/Integration/Validation/**
tags: []
---

# WP02 — Kernel Wiring & Framework-Wide Enforcement

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Tracks**: #1643
**Requirements**: FR-001, FR-008, NFR-001, NFR-003 | **Dependencies**: WP01
**Command**: `spec-kitty agent action implement WP02 --agent <name>`

## Objective

Turn validation ON framework-wide: every repository the kernel builds gets the shared default validator unless `WAASEYAA_ENTITY_VALIDATION` opts out. Then make the framework's own house pass its own rules: run the full suite with enforcement live and triage every newly-failing save. This WP is where the consumer-breaking change becomes real — the triage findings feed WP04's CHANGELOG note.

## Context (read first)

- `packages/foundation/src/Kernel/AbstractKernel.php:239-247` — the repository factory closure constructs `EntityRepository(...)` with named args `fieldRegistry:` and `logger:` but **no `validator:`**. The factory closure is the single seam: every kernel-resolved repository flows through it.
- `packages/entity-storage/src/EntityRepository.php` — constructor slot `?EntityValidator $validator = null` (position 7, named arg `validator:`); `doSave()` guard at `:361-372` throws `EntityValidationException`; `save()`/`saveMany()` accept `bool $validate = true`.
- WP01 delivered `EntityValidator::createDefault()` — one stateless instance, shared.
- Kernel env-reading precedent: `APP_ENV` / `APP_DEBUG` handling in the same class — match its style for reading `WAASEYAA_ENTITY_VALIDATION` (the framework uses `getenv()`, never `$_ENV`).
- Contract: `kitty-specs/live-entity-validation-key-protection-01KTWQT3/contracts/validation-error.md` — pre-persistence guarantee, saveMany rollback, opt-out equivalence. Research D1/D2.
- Integration tests boot real kernels against SQLite — find an existing example with `rg -l "extends.*KernelTestCase|new HttpKernel|ConsoleKernel" tests/Integration | head` and mirror its bootstrap.

## Subtasks

### T006 — Wire the validator with opt-out

**File**: `packages/foundation/src/Kernel/AbstractKernel.php`

1. Compute once (not per repository) whether validation is enabled:
   ```php
   $raw = getenv('WAASEYAA_ENTITY_VALIDATION');
   $validationEnabled = !\is_string($raw)
       || !\in_array(strtolower($raw), ['0', 'false', 'off'], true);
   ```
   Unset or any other value → enabled (default-on, research D2).
2. When enabled, build ONE `EntityValidator::createDefault()` and capture it in the repository factory closure; pass `validator: $validator` to the `EntityRepository` construction at `:239`. When disabled, pass nothing (current behavior, byte-identical).
3. Import `Waaseyaa\Entity\Validation\EntityValidator` — the kernel's cross-layer import exemption covers this (it already imports entity-storage types in the same closure).
4. Add a brief comment at the wiring site referencing #1643 and the env flag, in the style of the existing #1376 comment block below it.

**Validation**: T007 integration tests; existing suite (T008).

### T007 — Integration tests: booted-kernel enforcement

**File**: `tests/Integration/Validation/KernelValidationWiringTest.php` (NEW — `#[CoversNothing]`, namespace `Waaseyaa\Tests\Integration\Validation`)

Register a throwaway entity type with: required string field, integer field with `['min' => 0, 'max' => 100]`, and a per-field declared constraint (e.g. `GreaterThan`) — exercising all three constraint layers through one type. Cases:

1. **Rejection pre-persistence**: save with out-of-range int → expect `EntityValidationException`; assert the row does NOT exist (query storage directly) — contract clause 1.
2. **Violation completeness**: save with two violations → both present in the exception's list, each `propertyPath` prefixed with its field name — contract clause 2/3.
3. **Valid save unchanged**: well-formed entity saves; returns SAVED_NEW.
4. **Per-save opt-out**: `save($entity, validate: false)` persists the invalid entity (pre-mission behavior preserved).
5. **saveMany rollback**: batch of [valid, invalid] → exception; assert NEITHER row persisted (UnitOfWork transaction) — contract clause 5.
6. **Env opt-out**: with `WAASEYAA_ENTITY_VALIDATION=0` (use `putenv()` in setUp/tearDown — never `$_ENV`; restore the prior value in tearDown even on failure), a freshly booted kernel saves invalid data without throwing — contract clause 6. Boot the kernel AFTER setting the env var; the decision is boot-time.

### T008 — Full-suite triage

Run `./vendor/bin/phpunit` (full suite) with the wiring live. For every newly-failing test:

1. **Classify**: (a) the framework's own field definitions are wrong (e.g. a system entity declares required on a field its own bootstrap leaves empty) → fix the definition or the data; (b) a framework-internal write legitimately bypasses validation (migrations, schema bootstrap, fixture seeding) → switch that call site to `save($entity, validate: false)` with a one-line justification comment; (c) test fixture data is simply invalid → fix the fixture.
2. **Never weaken the seam**: do not add fields to an exclusion list, do not relax the builder, do not flip the default.
3. **Ownership boundary**: fixes under `tests/Integration/**` are yours. If a fix is required in a file owned by another WP or outside this WP's `owned_files` (e.g. a package's entity definition or a `save()` call site in `packages/*/src`), STOP for that file: record path, failing test, and proposed one-line fix in the **Triage Log** section you append to this WP file. The orchestrator applies or re-routes those.
4. Record EVERY triage decision (including clean classifications with no change) in the Triage Log — WP04's CHANGELOG note is written from it.

### T009 — Perf smoke (NFR-001)

**File**: same integration test class (or sibling `ValidationOverheadTest.php` in the owned dir), marked `#[Group('perf')]` if the suite has a perf group convention (check `rg "Group\('perf'\)" tests/` — if no convention exists, keep it a plain test with generous bounds).

- Construct a ~20-field entity type. Time 200 `save(..., validate: true)` vs 200 `save(..., validate: false)` (fresh rows each, same shape).
- Assert median validated-save time ≤ 1.10 × median unvalidated (use medians, not means; allow a retry-once guard against CI jitter).
- If the assertion is flaky in CI, loosen to 1.25× with a comment linking NFR-001 and note it in review — do NOT delete the test.

## Definition of Done

- [ ] Default-on wiring live; env opt-out boot-time effective.
- [ ] All T007 cases green; full `./vendor/bin/phpunit` green.
- [ ] Triage Log appended to this file (even if empty: state "no newly-failing tests").
- [ ] Perf smoke green and NFR-001 bound recorded in the Triage Log.
- [ ] `composer phpstan`, `composer cs-check` clean; no `getQuery()` gate regressions.
- [ ] No changes outside `owned_files` (out-of-bounds needs are in the Triage Log instead).

## Reviewer guidance

- The pre-persistence assertion (T007 case 1) and the saveMany rollback (case 5) are the contract's heart — verify they query storage directly rather than trusting the exception.
- Check the env flag is read at boot, once — not per save (perf) and not per repository (consistency).
- Scrutinize every `validate: false` introduced in triage: each needs a justification comment; reject bare flags.
- The Triage Log is a deliverable, not a scratchpad — WP04 consumes it verbatim.

## Activity Log

- 2026-06-12T02:07:12Z – claude:fable-5:implementer:implementer – shell_pid=8928 – Started implementation via action command
