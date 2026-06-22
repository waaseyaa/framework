---
work_package_id: WP01
title: Constraint Derivation & Default Validator
dependencies: []
requirement_refs:
- FR-002
- FR-003
- FR-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-live-entity-validation-key-protection-01KTWQT3
base_commit: 911346fa69eb15132beff201091cdccf8ffd30f1
created_at: '2026-06-12T01:54:46.391147+00:00'
subtasks:
- T001
- T002
- T003
- T004
- T005
shell_pid: "3660"
agent: "claude:fable-5:reviewer:reviewer"
history:
- date: '2026-06-12T01:48:54Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/entity/src/Validation/
execution_mode: code_change
owned_files:
- packages/entity/src/Validation/FieldDefinitionConstraintBuilder.php
- packages/entity/src/Validation/EntityValidator.php
- packages/entity/tests/Unit/Validation/**
tags: []
---

# WP01 — Constraint Derivation & Default Validator

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Tracks**: #1643
**Requirements**: FR-002, FR-003, FR-004 | **Dependencies**: none
**Command**: `spec-kitty agent action implement WP01 --agent <name>`

## Objective

Complete the dormant pieces inside `packages/entity` so the constraint pipeline is whole: numeric Range derivation, per-field declared-constraint merge, and a `createDefault()` factory so the kernel (WP02) can construct a validator without importing Symfony validator internals.

This WP is pure package-local, additive change. Nothing here turns enforcement on — WP02 does that. After this WP, anything that *does* wire an `EntityValidator` gets complete constraint coverage.

## Context (read first)

- `packages/entity/src/Validation/FieldDefinitionConstraintBuilder.php` — derives NotBlank/NotNull, Length, Email, Choice (allowed_values + enum), scalar Type. **No Range arm; `FieldDefinition::getConstraints()` never read.**
- `packages/entity/src/Validation/EntityValidator.php` — stateless wrapper over `Symfony\Component\Validator\Validator\ValidatorInterface`; validates per-field via cast-aware `EntityInterface::get()` (#1181 ST-6 — do not change that).
- `packages/entity/src/Validation/EntityTypeValidationConstraints.php` — type-level manual constraints REPLACE builder output per field. **Preserve this precedence** (research D5: per-field declared constraints APPEND; type-level manual REPLACES).
- `packages/field/src/FieldDefinition.php:199` — `getConstraints(): array` documented `@return Constraint[]`.
- `FieldDefinitionConstraintBuilder::normalizeDefinition()` (line ~130) already threads `constraints:` from array-shaped definitions into `FieldDefinition` — the data arrives; it's just never consumed.
- Mission contracts: `kitty-specs/live-entity-validation-key-protection-01KTWQT3/contracts/validation-error.md`, research decisions D1/D5 in `research.md`.

## Subtasks

### T001 — Range derivation arm

**File**: `packages/entity/src/Validation/FieldDefinitionConstraintBuilder.php`

In `constraintsForField()`, after the Length handling, derive a `Symfony\Component\Validator\Constraints\Range` for numeric field types:

1. Applies to types: `integer`, `int`, `float`, `double` (exactly the set `scalarTypeConstraint()` treats as numeric).
2. Read settings `min` and `max` (also accept nothing else — no `minimum`/`maximum` aliases; the Length arm's `max_length`/`maxLength` dual-key pattern exists because both spellings ship in the wild; `min`/`max` have a single spelling today — verify with `rg "'min'|'max'" packages/*/src --type php` and extend only if a second spelling is actually in use).
3. Numeric-guard each value (`is_numeric`) exactly like `lengthConstraint()` does; cast int for int types, float for float types.
4. Shapes: min-only → `new Range(min: $min)`, max-only → `new Range(max: $max)`, both → `new Range(min: $min, max: $max)`, neither → no constraint. Extract a private `rangeConstraint(FieldDefinitionInterface $def, string $type): ?Range` mirroring `lengthConstraint()`.

**Validation**: covered by T004.

### T002 — Per-field declared-constraint merge

**File**: same.

At the end of `constraintsForField()`, before `return`:

```php
foreach ($def->getConstraints() as $declared) {
    if (!$declared instanceof Constraint) {
        throw new \InvalidArgumentException(sprintf(
            'FieldDefinition::getConstraints() for field "%s" must contain only %s instances, got %s.',
            $fieldName,
            Constraint::class,
            get_debug_type($declared),
        ));
    }
    $constraints[] = $declared;
}
```

- **Append** after derived constraints (research D5 — declared tightens, never replaces; type-level replace semantics stay in `EntityTypeValidationConstraints`).
- Fail-loud matches `EntityTypeValidationConstraints::normalizeToList()` precedent.
- Note the docblock at the class head references `docs/specs/entity-system.md` (#1182) — WP04 updates that spec; here just keep the reference accurate.

### T003 — `EntityValidator::createDefault()`

**File**: `packages/entity/src/Validation/EntityValidator.php`

```php
public static function createDefault(): self
{
    return new self(\Symfony\Component\Validator\Validation::createValidator());
}
```

- Add a short docblock: intended for kernel wiring (research D1); stateless, safe to share across repositories.
- `symfony/validator` is already a dependency of `waaseyaa/entity` (`Validation::createValidator()` introduces no new composer edge — verify `packages/entity/composer.json` lists it; if it arrives transitively today, add the explicit require, respecting the CP-NEW internal-version policy for any `waaseyaa/*` edits, which this is not).

### T004 — Unit tests: Range arm

**File**: `packages/entity/tests/Unit/Validation/FieldDefinitionConstraintBuilderTest.php` (extend the existing test class; follow its existing fixture style)

Cases (use array-shaped definitions like the existing tests, plus at least one `FieldDefinition` instance):

1. `integer` with `settings: ['min' => 0, 'max' => 100]` → list contains Range(min 0, max 100).
2. min only / max only → Range with only that bound.
3. Neither setting → no Range in list.
4. Non-numeric setting values (`'min' => 'abc'`) → ignored (no Range), no throw.
5. Non-numeric type (`string` with min/max settings) → no Range.
6. `float` type → Range with float-cast bounds.
7. Required integer with range → both NotNull and Range present (arms compose).

### T005 — Unit tests: declared-constraint merge + precedence

**File**: same test class (merge cases), plus extend `packages/entity/tests/Unit/Validation/EntityTypeValidationConstraintsTest.php` if present (locate with `rg -l EntityTypeValidationConstraints packages/entity/tests/`).

1. Definition with `constraints: [new GreaterThan(0)]` → appended after derived constraints (assert order: derived first, declared last).
2. Declared constraints on a field with no derivable constraints → field appears in output with exactly the declared list.
3. Non-Constraint entry (`constraints: ['not-a-constraint']`) → `InvalidArgumentException` naming the field.
4. Precedence pin: entity type with BOTH type-level manual constraints for field F and per-field declared constraints on F → type-level wins entirely (existing replace semantics untouched).
5. `FieldDefinitionInterface` instance (not array) with declared constraints → merged identically (both normalize paths covered).

## Definition of Done

- [ ] All five subtasks complete; `./vendor/bin/phpunit packages/entity/tests/` green.
- [ ] `composer phpstan` clean against baseline (new code must not add baseline entries).
- [ ] `composer cs-check` clean.
- [ ] No changes outside `owned_files`.
- [ ] No behavior change for callers that don't declare ranges or per-field constraints (existing builder tests still green unmodified — if an existing test had to change, explain why in the review notes).

## Reviewer guidance

- The precedence pin (T005 case 4) is the highest-value assertion — it proves we didn't silently change `EntityTypeValidationConstraints` semantics.
- Check the Range arm guards `is_numeric` before casting; a string `'50'` in settings is valid input (settings come from arrays/config).
- `createDefault()` must NOT cache statically — kernel shares the instance; the factory stays dumb.

## Activity Log

- 2026-06-12T01:54:48Z – claude:fable-5:implementer:implementer – shell_pid=18748 – Assigned agent via action command
- 2026-06-12T02:03:04Z – claude:fable-5:implementer:implementer – shell_pid=18748 – Ready for review
- 2026-06-12T02:03:39Z – claude:fable-5:reviewer:reviewer – shell_pid=3660 – Started review via action command
- 2026-06-12T02:06:14Z – claude:fable-5:reviewer:reviewer – shell_pid=3660 – Review passed: Range arm, declared-constraint merge, createDefault() all correct; precedence pin proves type-level replace; gates green (513 entity tests, phpstan 0 errors, cs-check clean); diff is pure additions within owned files
- 2026-06-12T03:25:51Z – claude:fable-5:reviewer:reviewer – shell_pid=3660 – Done override: Mission squash-merged to main as 051766833
