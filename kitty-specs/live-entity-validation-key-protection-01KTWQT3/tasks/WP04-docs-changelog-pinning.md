---
work_package_id: WP04
title: Documentation, Break Note & Id-Exclusion Pinning
dependencies:
- WP01
- WP02
- WP03
requirement_refs:
- C-001
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
subtasks:
- T015
- T016
- T017
- T018
agent: "claude:fable-5:implementer:implementer"
shell_pid: "15484"
history:
- date: '2026-06-12T01:48:54Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: docs/specs/
execution_mode: code_change
owned_files:
- CHANGELOG.md
- docs/specs/entity-system.md
- docs/specs/ai-integration.md
- CLAUDE.md
- packages/entity-storage/src/Driver/SqlStorageDriver.php
- packages/entity-storage/tests/**
tags: []
---

# WP04 — Documentation, Break Note & Id-Exclusion Pinning

**Mission**: live-entity-validation-key-protection-01KTWQT3 | **Tracks**: #1643, #1646
**Requirements**: C-001, C-004 (spec constraints; functional FRs covered by WP01–WP03) | **Dependencies**: WP01, WP02, WP03
**Command**: `spec-kitty agent action implement WP04 --agent <name>`

## Objective

Document the break before the tag exists, bring the two affected subsystem specs in line with the new behavior (the drift rule: behavior change and spec change in the same release), and turn the SqlStorageDriver id-exclusion accident into pinned contract. This WP gates the v0.1.0-alpha.204 release cut.

## Context (read first)

- **WP02's Triage Log** (appended to `kitty-specs/.../tasks/WP02-kernel-wiring-enforcement.md`) — the empirical record of what actually broke when enforcement went live. The CHANGELOG entry is written FROM this log, not from imagination.
- Mission contracts: `contracts/validation-error.md`, `contracts/tool-refusal.md` — the doc updates restate these, they do not invent.
- CHANGELOG convention: entries go under `[Unreleased]`, NOT a pre-stamped version heading (alpha.202 lesson — the release-cut workflow stamps the heading).
- `docs/specs/entity-system.md` — has a "Field definitions → constraints" section (#1182) describing the builder; currently implies validation runs; must now say it ACTUALLY runs, wired by default, with the opt-out.
- `docs/specs/ai-integration.md` — entity tool surface description; gains the identity-key refusal contract.
- Root `CLAUDE.md` "Environment" section — one line per env var; add `WAASEYAA_ENTITY_VALIDATION`.
- `packages/entity-storage/src/Driver/SqlStorageDriver.php:212-218` — update field-set assembly excludes the id key (the line range may have shifted; locate with `rg -n "idKey" packages/entity-storage/src/Driver/SqlStorageDriver.php`).

## Subtasks

### T015 — CHANGELOG `[Unreleased]` BREAKING entry

**File**: `CHANGELOG.md`

Under `[Unreleased]`, following the file's existing section conventions (check how alpha.200's breaking entries were formatted — `rg -n "BREAKING" CHANGELOG.md | head`):

1. **Changed (BREAKING)** — save-time validation now enforced framework-wide (#1643): declared field constraints (required, length, email, allowed values, enum, scalar type, NEW numeric min/max ranges, NEW per-field declared constraints) reject invalid saves with `EntityValidationException`. Name the exact consumer-visible consequence from WP02's Triage Log, **explicitly including the scalar `Type` constraint case** (loosely-typed-but-working data, e.g. numeric strings in int fields, now rejected — `EntityInterface::get()` cast-awareness absorbs most cases; the note says which it doesn't).
2. **Opt-outs**: `WAASEYAA_ENTITY_VALIDATION=0|false|off` (global, boot-time) and `save($entity, validate: false)` (per-call). One sentence each on when each is appropriate.
3. **Changed (BREAKING)** — stock entity agent tools refuse identity-key writes (#1646): `entity.create` / `entity.update` reject `values` containing id/uuid/revision/langcode/default_langcode keys, whole-write. Call out the create-tool change explicitly: model-supplied `id` on create is no longer accepted (research D3); custom tools are the path if a consumer needs that.
4. **Upgrade guidance**: consumers should (a) fix data/definitions surfaced by validation, (b) delete app-side write-boundary validation and identity-key deny-lists that the framework now owns, (c) audit their own `save()` call sites that intentionally write invalid interim data.

### T016 — Spec docs + CLAUDE.md

**Files**: `docs/specs/entity-system.md`, `docs/specs/ai-integration.md`, `CLAUDE.md`

1. **entity-system.md**: in the validation/constraints section — constraint source table from `data-model.md` (derived / per-field declared append / type-level manual replace), Range derivation, kernel default-on wiring, both opt-outs, the pre-persistence guarantee and saveMany rollback semantics (from `contracts/validation-error.md`). Mark the previous "validation is available but not wired" caveat (if present) as resolved at alpha.204.
2. **ai-integration.md**: identity-key refusal contract for the stock entity tools — refusal set, whole-write rejection, check order, error shapes, dry-run behavior (from `contracts/tool-refusal.md`). Note revision_log stays writable; note #1638 (scoped writes) as the separate, broader mechanism.
3. **CLAUDE.md**: add to the Environment list: `WAASEYAA_ENTITY_VALIDATION` — save-time entity validation toggle (default: enabled). Values `0`/`false`/`off` disable.

### T017 — SqlStorageDriver id-exclusion: comment + pinning test

**Files**: `packages/entity-storage/src/Driver/SqlStorageDriver.php`, `packages/entity-storage/tests/` (extend the existing driver test class — locate with `rg -l "SqlStorageDriver" packages/entity-storage/tests/`)

1. At the exclusion site, add a comment making the asymmetry deliberate: the id key is excluded from UPDATE field sets by contract — row identity is immutable through the storage driver; mutating identity requires delete+insert or a migration. Reference #1646 and `contracts/tool-refusal.md`.
2. Pinning test: hydrate an existing row, `set()` a different id on the entity, save via the driver/repository update path → the row's id in storage is unchanged (and no second row appears). This pins C-004's "deliberate behavior" claim.

### T018 — Drift check + quickstart walkthrough

1. Run `tools/drift-detector.sh`; resolve anything it flags against the two updated specs (other flagged-but-unrelated specs: note them in review notes, do not fix here).
2. Execute `kitty-specs/live-entity-validation-key-protection-01KTWQT3/quickstart.md` steps 1–6 end-to-end as final validation (this includes `composer verify`). Record pass/fail per step at the bottom of this WP file.

## Definition of Done

- [ ] CHANGELOG entry under `[Unreleased]` (NOT a version heading), covering both breaks, both opt-outs, the Type-constraint case, and the create-tool id change.
- [ ] Both specs updated from the contracts; CLAUDE.md env entry present.
- [ ] Pinning test green; comment in place.
- [ ] Drift detector clean for the two touched specs; quickstart walkthrough recorded as all-pass.
- [ ] `composer verify` green; no changes outside `owned_files`.

## Reviewer guidance

- Diff the CHANGELOG entry against WP02's Triage Log — every triage class that consumers could hit must appear; reject an entry written from the spec instead of the log.
- The specs must describe behavior (contract language), not implementation diff narration.
- The pinning test must go through the update path (not insert) and assert storage state, not driver return values.

## Activity Log

- 2026-06-12T03:05:17Z – claude:fable-5:implementer:implementer – shell_pid=15484 – Started implementation via action command
