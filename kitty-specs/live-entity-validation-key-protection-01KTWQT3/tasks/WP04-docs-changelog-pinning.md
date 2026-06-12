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
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "24596"
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

## Quickstart Walkthrough (T018)

**Run**: 2026-06-11, WP04 worktree at commit d7a68d59b (Windows 11, PHP 8.5.5, PHPUnit 10.5.63). Per-step results for `quickstart.md` steps 1–6:

| Step | Command / check | Result |
|---|---|---|
| 1. Declared constraints enforce on save | `./vendor/bin/phpunit tests/Integration/Validation/ --no-progress` | **PASS** — 7 tests, 22 assertions, OK |
| 2. Per-field declared constraints honored | `./vendor/bin/phpunit packages/entity/tests/Unit/Validation/ --no-progress` (builder cases incl. GreaterThan append, Range min/max/both/neither, fail-loud) | **PASS** — 40 tests, 69 assertions, OK |
| 3. Agent tools refuse identity keys | `./vendor/bin/phpunit packages/ai-tools/tests/ --no-progress` | **PASS** — 36 tests, 124 assertions, OK |
| 4. Validation errors are model-correctable | `validation_failed` shape pinned in `EntityKeyGuardTest` / `EntityToolKeyRefusalTest` (`--filter validation`: 3 tests, 9 assertions) | **PASS** |
| 5. Opt-outs | `WAASEYAA_ENTITY_VALIDATION=0 ./vendor/bin/phpunit tests/Integration/Validation/ --filter OptOut` | **PASS** — 2 tests, 4 assertions, OK |
| 6. Gates (`composer verify` components run individually per the Windows-local convention; Linux CI is the release gate) | see below | **PASS** (2 pre-existing local artifacts, identical on clean main) |

Step 6 component results:

- Targeted phpunit: `tests/Integration/Validation/` 7/7 OK; `packages/ai-tools/tests/` 36/36 OK; `packages/entity-storage/tests/` 689 tests / 1878 assertions OK (incl. the new `updateNeverRewritesTheIdColumn` pinning test; 2 pre-existing deprecation notices in `PipelineInvariantTest`).
- `composer phpstan` — **clean** (0 errors).
- `composer cs-check` — **clean** (0 files flagged).
- `composer check-composer-policy` — **clean**.
- `bin/check-dead-code` — **clean** (no new findings beyond baseline).
- `bin/check-package-layers` — **clean**.
- `bin/check-getquery-bindings` — 2 "new" callsites reported (`packages/entity/src\Storage\EntityStorageInterface.php:19`, `packages/northcloud/src\Sync\NcSyncService.php:140`): the known Windows path-separator artifact (mixed `/` and `\` defeats baseline matching), reproduces identically on clean main — not introduced by this mission.
- Full-suite Windows-local failures (~145: OIDC PEM, CLI snapshots, temp-dir races, coverage-driver warning) are pre-existing and documented in WP02's Triage Log; unchanged by WP04 (docs + one driver comment + one test).

Drift detector (`tools/drift-detector.sh`): post-commit, `docs/specs/entity-system.md` **OK** and `docs/specs/ai-integration.md` **OK** (the two specs this WP owns). Remaining flag: `docs/specs/infrastructure.md` STALE via WP02's `packages/foundation/src/Kernel/AbstractKernel.php` change — outside WP04 `owned_files`, not fixed here; the kernel validation wiring is documented in `entity-system.md` § Entity Validation ("Kernel wiring"). Reviewer may want a one-line cross-reference in infrastructure.md if that flag should clear independently.

## Activity Log

- 2026-06-12T03:05:17Z – claude:fable-5:implementer:implementer – shell_pid=15484 – Started implementation via action command
- 2026-06-12T03:16:51Z – claude:fable-5:implementer:implementer – shell_pid=15484 – Ready for review: CHANGELOG breaks (#1643, #1646) + upgrade guidance from WP02 triage log; entity-system.md three-layer constraint table + kernel default-on + pre-persistence/saveMany rollback; ai-integration.md identity-key refusal contract; CLAUDE.md env line; SqlStorageDriver id-exclusion comment + pinning test green; drift detector OK for both touched specs (infrastructure.md flag is WP02's AbstractKernel, out of scope); quickstart steps 1-6 recorded, gates clean (phpstan/cs/policy/dead-code/layers)
- 2026-06-12T03:18:37Z – claude:fable-5:reviewer:reviewer – shell_pid=24596 – Started review via action command
- 2026-06-12T03:22:24Z – claude:fable-5:reviewer:reviewer – shell_pid=24596 – Review passed: CHANGELOG [Unreleased] Changed entry covers both breaks (#1643 incl. explicit scalar Type/cast-awareness case, #1646 incl. create-tool id change), both opt-outs with when-appropriate guidance, 3-part upgrade guidance, and is grounded in WP02's Triage Log (zero accommodations, 9759-test suite, ~1.05x/<=1.10x NFR-001). entity-system.md three-layer precedence table matches code (manual replaces, declared appends — verified against EntityTypeValidationConstraints + builder); kernel default-on wiring text matches AbstractKernel (0/false/off case-insensitive, read once at boot); pre-persistence + saveMany rollback restate validation-error.md; no stale not-wired caveats. ai-integration.md refusal section matches tool-refusal.md clause-for-clause (refusal set, whole-write, check order incl. no-existence-leak, both error shapes, dry-run, revision_log, #1638 pointer). CLAUDE.md env line present. SqlStorageDriver delta is comment-only; T017 pinning test goes through the UPDATE path and asserts storage state directly (row id unchanged, divergent id read null, table id list == [1]) — green, plus full entity-storage 689/689. Reviewer-run gates all clean: phpstan 0 errors, cs-check 0 files, check-composer-policy OK, check-dead-code OK, check-package-layers OK. Drift detector: both owned specs OK; infrastructure.md STALE is WP02's kernel change, noted in the WP file, out of WP04 scope.
- 2026-06-12T03:26:03Z – claude:fable-5:reviewer:reviewer – shell_pid=24596 – Done override: Mission squash-merged to main as 051766833
