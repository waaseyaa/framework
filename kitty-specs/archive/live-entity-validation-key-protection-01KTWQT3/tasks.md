# Tasks: Live Entity Validation & Key Protection

**Mission**: `live-entity-validation-key-protection-01KTWQT3` | **Branch**: `main` → merges to `main`
**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md) (D1–D6), [data-model.md](data-model.md), [contracts/](contracts/)
**Tracking**: #1643, #1646 | **Target release**: v0.1.0-alpha.204

## Subtask Index

| ID | Description | WP | Parallel |
|----|-------------|----|----------|
| T001 | Range derivation arm in FieldDefinitionConstraintBuilder | WP01 | | [D] |
| T002 | Per-field getConstraints() merge (append, fail-loud) | WP01 | | [D] |
| T003 | EntityValidator::createDefault() factory | WP01 | [D] |
| T004 | Unit tests: Range arm | WP01 | | [D] |
| T005 | Unit tests: declared-constraint merge + precedence | WP01 | | [D] |
| T006 | Kernel wiring with WAASEYAA_ENTITY_VALIDATION opt-out | WP02 | | [D] |
| T007 | Integration tests: booted-kernel enforcement, opt-outs, saveMany | WP02 | | [D] |
| T008 | Full-suite triage: fix framework saves that newly fail | WP02 | | [D] |
| T009 | Perf smoke for NFR-001 (≤10% median overhead) | WP02 | | [D] |
| T010 | EntityKeyGuard: refusal-set resolution + unit tests | WP03 | [D] |
| T011 | EntityCreateTool: refusal + structured validation errors | WP03 | | [D] |
| T012 | EntityUpdateTool: refusal + structured validation errors | WP03 | | [D] |
| T013 | Tool unit tests: short-circuit, shapes, determinism | WP03 | | [D] |
| T014 | Dispatch-path integration test (agent dispatch refusal) | WP03 | | [D] |
| T015 | CHANGELOG [Unreleased] BREAKING entry + upgrade note | WP04 | [D] |
| T016 | Spec docs + CLAUDE.md environment entry | WP04 | [D] |
| T017 | SqlStorageDriver id-exclusion comment + pinning test | WP04 | [D] |
| T018 | Drift check + quickstart walkthrough validation | WP04 | | [D] |

## WP01 — Constraint Derivation & Default Validator (entity package)

**Prompt**: [tasks/WP01-constraint-derivation-default-validator.md](tasks/WP01-constraint-derivation-default-validator.md) | **Priority**: P1 | **Estimated prompt**: ~330 lines
**Goal**: Complete the dormant constraint pipeline inside `packages/entity` so that numeric ranges derive from field settings, per-field declared constraints are honored, and a default validator can be constructed without the caller knowing Symfony internals.
**Independent test**: `./vendor/bin/phpunit packages/entity/tests/` green; new builder cases prove Range + merge semantics with zero kernel involvement.
**Dependencies**: none (lane root)

- [x] T001 Range derivation arm in FieldDefinitionConstraintBuilder (WP01)
- [x] T002 Per-field getConstraints() merge — append, fail-loud on non-Constraint (WP01)
- [x] T003 EntityValidator::createDefault() factory (WP01)
- [x] T004 Unit tests: Range arm — min/max/both/neither/non-numeric (WP01)
- [x] T005 Unit tests: merge + type-level precedence preserved (WP01)

**Implementation sketch**: extend `constraintsForField()` with the Range arm mirroring `lengthConstraint()`'s shape; append `$def->getConstraints()` with instanceof guard; add stateless `createDefault()` wrapping `Validation::createValidator()` (research D1, D5). Risks: none structural — pure additive package-local change.

## WP02 — Kernel Wiring & Framework-Wide Enforcement (foundation)

**Prompt**: [tasks/WP02-kernel-wiring-enforcement.md](tasks/WP02-kernel-wiring-enforcement.md) | **Priority**: P1 | **Estimated prompt**: ~380 lines
**Goal**: Every kernel-built repository validates on save by default; env opt-out works; the framework's own suite is green with enforcement live; NFR-001 pinned.
**Independent test**: `tests/Integration/Validation/KernelValidationWiringTest.php` green in a booted kernel; full `./vendor/bin/phpunit` green.
**Dependencies**: WP01

- [x] T006 Wire shared EntityValidator in AbstractKernel repository factory + WAASEYAA_ENTITY_VALIDATION opt-out (WP02)
- [x] T007 Integration tests: rejection pre-persistence, valid saves, env opt-out, validate:false, saveMany rollback (WP02)
- [x] T008 Full-suite triage: fix newly-failing framework saves (definitions/data or visible validate:false — never weaken the seam) (WP02)
- [x] T009 Perf smoke: 200-save comparison, assert ≤10% median overhead (WP02)

**Implementation sketch**: research D1/D2. Biggest risk is T008 unknowns — system entities violating their own declared constraints; budget most review attention there. If a fix is required in files outside this WP's ownership, record it in the WP review notes for orchestrator action instead of editing silently.

## WP03 — Agent Tool Identity-Key Guard & Structured Errors (ai-tools)

**Prompt**: [tasks/WP03-agent-tool-key-guard.md](tasks/WP03-agent-tool-key-guard.md) | **Priority**: P1 | **Estimated prompt**: ~400 lines
**Goal**: `entity.create` / `entity.update` refuse identity-key writes whole-write before any mutation, and surface validation failures as deterministic, machine-correctable errors per [contracts/tool-refusal.md](contracts/tool-refusal.md).
**Independent test**: `./vendor/bin/phpunit packages/ai-tools/tests/` green; refusal short-circuit proven (storage never touched).
**Dependencies**: WP01 (validation-error mapping consumes live constraint derivation in tests; runs in its own parallel lane)

- [x] T010 EntityKeyGuard: refusal set per research D4 + unit tests incl. renamed keys, label/bundle not refused (WP03)
- [x] T011 EntityCreateTool: guard before construction (id refused per D3), EntityValidationException mapping, dry-run refusal (WP03)
- [x] T012 EntityUpdateTool: guard before mutation, same error mapping, dry-run refusal (WP03)
- [x] T013 Tool unit tests: short-circuit, error shapes, sorted determinism, revision_log still writable (WP03)
- [x] T014 Dispatch-path integration test: refusal over the agent dispatch surface (WP03)

**Implementation sketch**: contracts/tool-refusal.md is the authoritative behavior spec; check order is capability → args → type existence → access → refusal → save. Risk: `AgentToolResult::error()` may not support content payloads — verify and fall back to message-only (D6 allows it).

## WP04 — Documentation, Break Note & Id-Exclusion Pinning

**Prompt**: [tasks/WP04-docs-changelog-pinning.md](tasks/WP04-docs-changelog-pinning.md) | **Priority**: P2 | **Estimated prompt**: ~290 lines
**Goal**: The break is documented before the tag exists; specs match the new behavior (drift rule); the SqlStorageDriver id-exclusion asymmetry becomes contract.
**Independent test**: quickstart.md walkthrough passes end-to-end; `composer verify` green.
**Dependencies**: WP01, WP02, WP03

- [x] T015 CHANGELOG [Unreleased]: BREAKING entry — newly-failing saves (incl. the Type-constraint case), both opt-outs, create-tool id refusal (WP04)
- [x] T016 docs/specs/entity-system.md + docs/specs/ai-integration.md updates; CLAUDE.md Environment entry for WAASEYAA_ENTITY_VALIDATION (WP04)
- [x] T017 SqlStorageDriver: explaining comment at the id-exclusion + pinning test (WP04)
- [x] T018 Run tools/drift-detector.sh; execute quickstart.md steps 1–6 as final validation (WP04)

**Implementation sketch**: write docs from the contracts, not from memory of the diff. T017 is the only code touch (comment + test). Risk: none; gate is `composer verify`.

## Lane / Parallelization Summary

- **Lane A**: WP01 → WP02 → WP04
- **Lane B**: WP03 (starts after WP01, parallel with WP02)
- MVP scope: WP01 + WP02 (framework-wide enforcement live); WP03 completes the agent-surface protection; WP04 gates the release cut.
