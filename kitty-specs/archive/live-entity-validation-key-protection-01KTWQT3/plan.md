# Implementation Plan: Live Entity Validation & Key Protection

**Branch**: `main` | **Date**: 2026-06-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `kitty-specs/live-entity-validation-key-protection-01KTWQT3/spec.md`
**Tracking**: #1643, #1646 | **Target release**: v0.1.0-alpha.204

## Summary

Make declared entity field constraints enforce themselves on every save framework-wide, and make the stock entity agent tools refuse writes to identity-key fields. All the enforcement machinery already exists and is dormant: `EntityRepository` has a validator slot and a save guard that throws `EntityValidationException` ([packages/entity-storage/src/EntityRepository.php:60](../../packages/entity-storage/src/EntityRepository.php), `doSave` guard at `:361-372`), `EntityTypeValidationConstraints` merges type-level manual constraints, and a per-save `$validate` flag already exists as the per-call opt-out. Three gaps make it dead in production:

1. The kernel repository factory never passes a validator ([packages/foundation/src/Kernel/AbstractKernel.php:239-247](../../packages/foundation/src/Kernel/AbstractKernel.php)).
2. `FieldDefinitionConstraintBuilder` has no numeric Range arm and never consumes per-field `FieldDefinition::getConstraints()` ([packages/entity/src/Validation/FieldDefinitionConstraintBuilder.php:47-89](../../packages/entity/src/Validation/FieldDefinitionConstraintBuilder.php)).
3. `EntityUpdateTool` sets every supplied values key verbatim ([packages/ai-tools/src/Entity/EntityUpdateTool.php:82-87](../../packages/ai-tools/src/Entity/EntityUpdateTool.php)); `EntityCreateTool` passes values raw into the entity constructor (`EntityCreateTool.php:83`).

The work is therefore: wire the validator in the kernel (with a default-on opt-out), complete the constraint derivation, add an identity-key guard to the entity tools, surface validation failures as structured tool errors, and document the break.

## Technical Context

**Language/Version**: PHP 8.5+ (charter baseline), Symfony 7.x components
**Primary Dependencies**: `symfony/validator` (already a dependency of `waaseyaa/entity` — `FieldDefinitionConstraintBuilder` and `EntityValidator` import it today)
**Storage**: No schema changes. Enforcement is pre-persistence; SQLite/MySQL/PostgreSQL unaffected.
**Testing**: PHPUnit 10.5 — unit tests per package (`packages/*/tests/Unit/`), integration tests in `tests/Integration/` booting a real kernel against SQLite
**Target Platform**: Framework monorepo; ships to consumers via the v0.1.0-alpha.204 cut
**Project Type**: Monorepo packages — `foundation` (L0), `entity` (L1), `entity-storage` (L1), `ai-tools` (L5), docs
**Performance Goals**: NFR-001 — ≤10% median save-time overhead for a ≤20-field entity in the integration environment
**Constraints**: Layer discipline (foundation kernel is the only sanctioned cross-layer wiring point); PHPStan baseline clean; dead-code gate; composer policy; consumer-breaking change documented under `[Unreleased]`
**Scale/Scope**: 4 packages touched + CHANGELOG + 2 spec docs; no new packages, no new entity types

## Charter Check

*GATE: evaluated 2026-06-12 against `.kittify/charter/charter.md`.*

- **PHP 8.5 baseline, Symfony components**: PASS — uses existing `symfony/validator` integration; no new runtime deps.
- **Per-package unit tests**: PASS — plan adds unit tests in `packages/entity/tests/Unit/Validation/`, `packages/ai-tools/tests/`, plus kernel integration tests.
- **Quality gates (CI matrix, PHPStan baseline, composer policy, dead-code, SCA)**: PASS — no gate exemptions requested. New code paths are reachable (no `@api` scaffolding needed beyond what ships wired).
- **DIRECTIVE_003 (decision documentation)**: Three material decisions documented in [research.md](research.md) — D1 (where the validator is constructed), D2 (opt-out mechanism), D3 (create-tool id refusal vs. the existing pre-set-id path).
- **DIRECTIVE_010 (spec fidelity)**: The one deliberate deviation candidate — create-tool pre-set IDs — is resolved in research.md D3 and reflected in the spec's acceptance scenario 5; no silent drift.
- **Layer discipline**: PASS — validator construction happens in the kernel (sanctioned cross-layer orchestrator); `ai-tools` (L5) already depends on `entity` (L1). No new upward edges.

**Post-design re-check**: PASS — no new violations introduced by Phase 1 design; Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```
kitty-specs/live-entity-validation-key-protection-01KTWQT3/
├── plan.md              # This file
├── research.md          # Phase 0 — decisions D1–D6 with alternatives
├── data-model.md        # Phase 1 — constraint sources, identity-key set, error shapes
├── quickstart.md        # Phase 1 — verify-by-hand script for reviewers
├── contracts/
│   ├── validation-error.md      # EntityValidationException / violation surface contract
│   └── tool-refusal.md          # Agent tool identity-key refusal + validation error contract
└── tasks.md             # Phase 2 (/spec-kitty.tasks — not created here)
```

### Source Code (repository root)

```
packages/foundation/src/Kernel/
└── AbstractKernel.php                 # MODIFY — repository factory passes validator (D1) gated by opt-out (D2)

packages/entity/src/Validation/
├── FieldDefinitionConstraintBuilder.php   # MODIFY — Range arm + per-field getConstraints() merge
└── EntityValidator.php                    # MODIFY — add static createDefault() factory (keeps symfony/validator
                                           #          construction inside the entity package)

packages/ai-tools/src/Entity/
├── EntityCreateTool.php               # MODIFY — identity-key refusal before construction; structured validation errors
├── EntityUpdateTool.php               # MODIFY — identity-key refusal before mutation; structured validation errors
└── EntityKeyGuard.php                 # NEW — shared identity-key resolution + refusal check (single seam for both tools)

packages/entity-storage/src/Driver/
└── SqlStorageDriver.php               # MODIFY (comment/test only) — document the deliberate id-exclusion on update (C-004)

CHANGELOG.md                           # MODIFY — [Unreleased] breaking note + upgrade guidance
docs/specs/entity-system.md            # MODIFY — validation section: wired-by-default, opt-out, constraint sources
docs/specs/ai-integration.md           # MODIFY — entity tool identity-key refusal contract

tests:
packages/entity/tests/Unit/Validation/FieldDefinitionConstraintBuilderTest.php   # extend — Range + merge cases
packages/ai-tools/tests/Unit/Entity/EntityKeyGuardTest.php                       # NEW
packages/ai-tools/tests/Unit/Entity/EntityCreateToolTest.php                     # extend — refusal + validation error
packages/ai-tools/tests/Unit/Entity/EntityUpdateToolTest.php                     # extend — refusal + validation error
tests/Integration/Validation/KernelValidationWiringTest.php                      # NEW — booted-kernel enforcement,
                                                                                 #       opt-out, saveMany, perf smoke
```

**Structure Decision**: All changes land in existing packages along the existing persistence pipeline; the only new class is `EntityKeyGuard` in `packages/ai-tools/src/Entity/` so both tools share one refusal seam (FR-006: no tool-private validation fork).

## Design Outline

1. **Kernel wiring (FR-001, FR-008)** — `AbstractKernel` builds one shared `EntityValidator` via `EntityValidator::createDefault()` and passes it to every `EntityRepository` it constructs, unless the opt-out (D2: `WAASEYAA_ENTITY_VALIDATION=0|false|off`) disables it. One validator instance for all repositories (it is stateless).
2. **Constraint derivation (FR-002, FR-003)** — `FieldDefinitionConstraintBuilder::constraintsForField()` gains: (a) a Range arm for numeric types reading `min`/`max` settings; (b) an unconditional merge of `FieldDefinition::getConstraints()` (Constraint instances appended after derived constraints; non-Constraint entries throw `InvalidArgumentException`, matching `EntityTypeValidationConstraints::normalizeToList()` fail-loud behavior). Existing precedence is preserved: entity-type-level manual constraints still *replace* derived+declared per-field sets.
3. **Identity-key guard (FR-005)** — `EntityKeyGuard::refusedKeys(EntityTypeInterface $definition, array $values): list<string>` resolves the protected column names from the registered entity keys for the kinds `id`, `uuid`, `revision`, `langcode`, `default_langcode` (the `label` and `bundle` keys are content/structure, NOT refused), plus the literal column names `uuid`, `langcode`, `default_langcode` as a belt-and-suspenders floor. Both tools call it before any mutation/construction and return a structured error naming all refused keys; nothing is written (whole-write rejection).
4. **Structured tool errors (FR-004, FR-007)** — both tools catch `EntityValidationException` ahead of the generic `\Throwable` arm and emit a deterministic, machine-readable error: violations sorted by field name, each with `field`, `message`, `invalid_value_type`. Refusals use the same shape with a `refused_keys` list. Contract: [contracts/tool-refusal.md](contracts/tool-refusal.md).
5. **Deliberate id-on-update exclusion (C-004)** — `SqlStorageDriver` already excludes the id key from update field sets (`packages/entity-storage/src/Driver/SqlStorageDriver.php:212-218`); add an explaining comment + a pinning test so the asymmetry is contract, not accident.
6. **Docs & break note (C-001)** — CHANGELOG `[Unreleased]` BREAKING entry (what now fails, why, the opt-out, the per-save `save($entity, validate: false)` escape hatch); update `docs/specs/entity-system.md` and `docs/specs/ai-integration.md` in the same PR (drift rule).

## Risks (premortem)

- **The framework's own suite breaks once enforcement is live** — system entities (user, config, audit) may have saves that violate their own declared constraints. Mitigation: wire first, run the full suite, fix *definitions or data* (never weaken the seam); framework-internal bootstrap writes that legitimately bypass validation use the existing `validate: false` parameter, visibly.
- **Ingestion/migration throughput** — bulk paths validate per entity. The per-save flag exists; the migration platform can opt out explicitly. Watch NFR-001 in the integration perf smoke.
- **Consumer breakage beyond intent** — derived `Type` constraints already exist and were never enforced; turning them on may reject loosely-typed-but-working data (e.g. numeric strings in int fields). The CHANGELOG note must call this exact case out; `EntityInterface::get()` is cast-aware (#1181 ST-6) which absorbs most of it.
- **Create-tool id refusal regresses a real flow** — D3 documents the trade-off; dry-run behavior preserved; if a consumer legitimately needs agent-driven pre-set IDs, that's #1638 (scoped write policy) territory, not the stock tool.

## Complexity Tracking

*No charter violations to justify.*
