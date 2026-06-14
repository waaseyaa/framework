---
work_package_id: WP03
title: Docs, CHANGELOG & Quickstart Walkthrough
dependencies:
- WP01
- WP02
requirement_refs:
- C-001
- C-003
- C-004
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
created_at: '2026-06-12T00:00:00+00:00'
subtasks:
- T013
- T014
- T015
- T016
agent: "claude:fable-5:reviewer:reviewer"
shell_pid: "27188"
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: docs/specs/
execution_mode: code_change
owned_files:
- CHANGELOG.md
- docs/specs/revision-system-unified.md
- docs/specs/entity-system.md
- docs/specs/api-layer.md
- docs/specs/ai-integration.md
tags: []
---

# WP03 — Docs, CHANGELOG & Quickstart Walkthrough

**Mission**: optimistic-locking-01KTXCHY | **Tracks**: #1647
**Requirements**: C-003 (documented before the alpha.207 tag exists), C-001/C-004 (the additive/no-parallel-vocabulary story told correctly) | **Dependencies**: WP01, WP02
**Command**: `spec-kitty agent action implement WP03 --agent <name>`

## Objective

The release is documented before the tag exists: CHANGELOG entries under `[Unreleased]` inserted **directly after the heading line** (the merge-conflict-avoidance pattern; never a pre-stamped alpha.207 heading), the four subsystem specs updated from the contracts — `entity-system.md` additionally clears the pending Mission 3 drift flag with the sanctioned `discoverable` one-liner — drift detector clean, and the quickstart executed end-to-end with recorded results.

## Context (read first)

- Write docs from `contracts/conflict-detection.md`, `contracts/conflict-surfaces.md`, and `data-model.md` — NOT from the diff (the contracts are the behavior spec; the diff is evidence).
- `research.md` D1–D6 carry the rationale sentences the specs should compress (especially: why non-revisionable types reject — no change marker exists; why the API seam is body meta, not If-Match; why an expectation-stated PATCH rides the repository pipeline).
- CHANGELOG state: `[Unreleased]` currently also carries the Mission 3 (alpha.206-targeted) entries — if alpha.206 has been cut by the time you run, they will be under a stamped heading instead; either way, this mission's entries go under `[Unreleased]`, inserted directly after the heading line. Never restructure existing entries.
- `docs/specs/entity-system.md` carries a review marker predating Mission 3's `EntityType` change — the `discoverable` flag is documented only in `api-layer.md`. Adding the one-line cross-ref while editing the file is **sanctioned scope** (it clears the standing drift flag), e.g. in the EntityType definition section: "`discoverable: bool = true` — visibility in the `GET /api` discovery index only (see api-layer.md, mission request-surface-hardening-01KTX7F2); not an access control."
- `docs/specs/revision-system-unified.md` is the LIVE canonical revision spec; §3 "Save contract" is the anchor for expectation semantics; the two-axis carve-out belongs beside §3a (`saveTranslation`).
- Doc convention: each touched spec gets/updates its `<!-- Spec reviewed YYYY-MM-DD … -->` HTML comment summarizing the change with mission reference (see the Mission 3 marker at the top of `api-layer.md` for the house style).

## Out of scope for this WP (do not touch)

- Any production or test code — if the quickstart walkthrough fails, stop and flag the owning WP; do not patch code from here.
- `docs/specs/jsonapi.md`, `mcp-endpoint.md`, cookbook files — not named by the plan; resist completeness creep.
- Restructuring CHANGELOG sections or rewriting Mission 3 entries.

## Subtasks

### T013 — CHANGELOG

**Files**: `CHANGELOG.md`

Insert directly after the `## [Unreleased]` heading line (before whatever follows):

1. **Added** — opt-in optimistic locking (#1647, mission optimistic-locking-01KTXCHY):
   - Storage: `SaveContext::withExpectedRevisionId()` + `RevisionConflictException` (entity type, id, expected, current; `errorCode: REVISION_CONFLICT`); race-safe via a guarded pointer-claim UPDATE inside the save transaction — exactly one winner under contention. Honored on revision-creating saves of single-axis revisionable types; everything else (non-revisionable — **no change marker exists** — two-axis, non-revision-creating, new entities, no DB) **rejects explicitly** with `LogicException`, never silently ignored.
   - Agent tool: `entity.update` accepts `expected_revision_id`; conflicts return the structured `revision_conflict` error (machine-correctable: expected + current in the payload); dry-run reports conflicts identically; unsupported paths return `revision_expectation_unsupported`; success payloads now carry the post-save `revision_id`; `entity.read` / `entity.list` expose `revision_id`.
   - JSON:API: `PATCH /api/{type}/{id}` accepts `data.meta.expected_revision_id` (conditional update; If-Match deliberately not supported — headers don't reach the controller); stale → **409** `code: REVISION_CONFLICT` with `meta {expected_revision_id, current_revision_id}`; `JsonApiError` gains an additive `meta` member (existing error bytes unchanged).
2. **Changed** — note prominently: an **expectation-stated** PATCH persists through the revision-aware repository pipeline (cuts a revision, dispatches repository lifecycle events); PATCHes and tool calls **without** an expectation are byte-identical to before (pinned: zero added queries, disjoint-field merge preserved).

Keep entries in the house voice (bold lead, package names in parens, issue ref).

### T014 — Spec docs (from the contracts)

**Files**: `docs/specs/revision-system-unified.md`, `docs/specs/entity-system.md`, `docs/specs/api-layer.md`, `docs/specs/ai-integration.md`

1. `revision-system-unified.md` §3 Save contract: the expectation seam (`withExpectedRevisionId`), the two-stage check (pre-check before any write/event; guarded claim in-transaction; affected-rows unambiguity argument), the conflict exception payload, the rejection matrix table (from data-model.md), the two-axis carve-out + the langcode-scoped-guard lift path note beside §3a. Update the review marker.
2. `entity-system.md`: repository save contract addendum (`RevisionConflictException` in the exceptions inventory; `SaveContext` expectation in the save flow; the `\LogicException`-vs-conflict distinction) **plus** the sanctioned Mission 3 `discoverable` one-liner cross-ref (see Context). Update the review marker — this clears the standing drift flag.
3. `api-layer.md`: PATCH conditional-update contract (request shape, the request-state table from data-model.md, the 409 body), the 409 catalogue note (REVISION_CONFLICT vs the codeless uuid-mismatch 409), `revision_id` documented as a **load-bearing** read attribute (FR-008 — removing it is a consumer break), the If-Match non-support + additive-follow-up note, the expectation-stated-pipeline consequence in bold. Update the review marker.
4. `ai-integration.md`: `entity.update` argument + both error shapes (`revision_conflict`, `revision_expectation_unsupported`), dry-run parity, read/list `revision_id` exposure, and a pointer to the quickstart's SC-002 approve-time staleness recipe as the canonical consumer pattern. Update the review marker.

### T015 — Drift check

```bash
tools/drift-detector.sh
```

Resolve flags for the four touched specs (including the cleared `entity-system.md` flag). Any flag on an UNtouched spec naming this mission's files → add the minimal cross-ref or record why it's out of scope in the Activity Log.

### T016 — Quickstart walkthrough

Execute `quickstart.md` steps 1–6 end-to-end (the test commands AND the by-hand SC-002 recipe at minimum through the tool surface); record per-step results in this WP file's Activity Log. Gate finish:

```bash
composer verify
bin/check-package-layers
```

## Definition of Done

- [ ] CHANGELOG entries under `[Unreleased]`, inserted directly after the heading line; no pre-stamped version heading; existing entries untouched.
- [ ] Four specs updated from the contracts with refreshed review markers; the entity-system.md drift flag cleared (discoverable cross-ref present).
- [ ] `tools/drift-detector.sh` clean for the touched specs.
- [ ] Quickstart steps 1–6 executed and recorded; `composer verify` green.
- [ ] No changes outside `owned_files`.

## Reviewer guidance

- Diff CHANGELOG: insertion point (directly after the heading), house voice, the pipeline-consequence note present and bold.
- Spot-check each spec against its contract clause numbers — the specs must not contradict the contracts (the contracts win).
- Verify the discoverable cross-ref is one line + a pointer, not a re-documentation (api-layer.md stays canonical for discovery).
- Confirm the walkthrough log shows real command output summaries, not checkbox theater.

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
- 2026-06-12T09:14:04Z – claude:fable-5:implementer:implementer – shell_pid=18580 – Started implementation via action command
- 2026-06-12 – claude:fable-5:implementer – T013+T014 committed on lane (`d6a699581 docs(M4-WP03): optimistic locking notes + spec updates`, 5 files, +306/−5). CHANGELOG entries inserted directly after `## [Unreleased]` (own `### Added` + `### Changed` blocks; Mission 3 entries below untouched; no pre-stamped heading). Specs written from the contracts; the rejection matrix documented as the SIX-row family actually landed (WP01 review cycle 2 added the no-revision-driver clause). Null-current semantics documented per WP01's premortem resolution: `currentRevisionId === null` ⇔ no readable head (row vanished OR pre-backfill pointer-less row) — contract §5 wording aligned in the same main-branch commit as this log entry. entity-system.md drift flag cleared via the sanctioned one-line `discoverable` cross-ref (api-layer.md stays canonical). Note: revision-system-unified.md is not in drift-detector's PATTERN_TO_SPEC mapping (entity-storage/* maps to entity-system.md), so it cannot be flagged — updated regardless (§3b).
- 2026-06-12 – claude:fable-5:implementer – **T015 drift check**: `tools/drift-detector.sh` (N=5 and N=10) → exit 0 after the docs commit. ai-integration.md OK, api-layer.md OK, entity-system.md OK (the standing Mission 3 flag is cleared). Zero remaining flags, related or unrelated.
- 2026-06-12 – claude:fable-5:implementer – **T016 quickstart walkthrough** (each step executed, real output):
  - §1 tests: `packages/entity-storage/tests/Unit/SaveContext/` → OK 14 tests/31 assertions; `EntityRepositoryOptimisticLockingTest.php` → OK 13/37; `tests/Integration/Locking/` → OK 4/22. (Only "issue": no code-coverage driver — environmental.)
  - §2 SC-001 pin: `tests/Integration/AgentRun/DualWriterConflictTest.php` → OK 2 tests/21 assertions.
  - §2 SC-002 by-hand recipe executed through the tool surface (scratch script in temp dir, in-memory SQLite, kernel-shape repository factory): 13/13 checks PASS — `entity.read` exposed `revision_id: 1` (draft time); competing plain update moved head to 2; stale `entity.update {expected_revision_id: 1}` refused with `{"error":"revision_conflict","entity_type":"test_revisionable","id":"42","expected":1,"current":2}`; dry-run with the same stale expectation produced the byte-identical JSON payload (string-equal); writer B's field intact / writer A's absent after the refusal; retry with `expected_revision_id: 2` succeeded, success payload carried post-save `revision_id: 3`; final entity carried BOTH writers' disjoint fields. §5 rejection: non-revisionable type + expectation → `revision_expectation_unsupported` with the greppable storage reason ("entity type 'test_plain' is not revisionable…"). §1 PHP primitive: matching `withExpectedRevisionId` save moved head 3→4; stale save threw `RevisionConflictException` expected=3 current=4 errorCode=REVISION_CONFLICT. The recipe works exactly as written in quickstart.md.
  - §3 tests: `EntityUpdateToolConflictTest` + `EntityToolRevisionExposureTest` → OK 17 tests/75 assertions. §4 tests: `JsonApiControllerConflictTest` + `JsonApiErrorTest` → OK 23/85.
  - §6 gates: `composer phpstan` → OK no errors; `composer cs-check` → 0 violations; `bin/check-dead-code` → OK no new findings; `composer check-composer-policy` → OK; `bin/check-package-layers` → OK (the two new downward edges pass). Targeted suites: entity-storage package → OK 744 tests/2034 assertions (2 pre-existing deprecations); ai-tools + api packages → OK 564/1753; Integration/Locking + Integration/AgentRun → OK 7/53. (`composer verify` full-suite run delegated to CI per the orchestrator's targeted-gate instruction.)
- 2026-06-12 – claude:fable-5:implementer – Deviation log: (1) commit-time spec-kitty guard emitted "out of scope" WARNINGs naming WP01's owned_files list for all five WP03-owned files — stale guard scope, non-blocking, files match this WP's `owned_files` frontmatter exactly. (2) CHANGELOG now contains a second `### Changed` heading inside `[Unreleased]` — the inevitable shape of the prescribed insert-directly-after-the-heading merge-anchor pattern; the release-cut consolidation can merge them.
- 2026-06-12T09:28:13Z – claude:fable-5:implementer:implementer – shell_pid=18580 – Ready for review
- 2026-06-12T09:28:54Z – claude:fable-5:reviewer:reviewer – shell_pid=27188 – Started review via action command
- 2026-06-12T09:33:14Z – claude:fable-5:reviewer:reviewer – shell_pid=27188 – Review passed: diff scope exact (5 owned files, CHANGELOG pure insertion 11/0); CHANGELOG anchored directly after [Unreleased], no alpha.207 heading, six-clause rejection matrix incl. cycle-2 driverless clause, both surfaces, bold expectation-stated-PATCH pipeline note; all four specs spot-verified against code (doSave two-stage check + affected-rows argument, six LogicException clauses grepped, JsonApiController 400/422/409 screens, EntityUpdateTool two-block error shapes, entity-system discoverable cross-ref clears Mission 3 drift); contract §5 null-current edit on main matches WP01 landed semantics and was the WP01 cycle-2 mandated carry-over; gates re-run independently: phpstan OK, cs-check 0 violations, check-dead-code OK, drift-detector exit 0, Integration/Locking+AgentRun 7 tests/53 assertions green; honest boundaries documented (If-Match non-support + additive follow-up, two-axis langcode-scoped-guard lift path, load-bearing revision_id FR-008)
- 2026-06-12T09:35:17Z – claude:fable-5:reviewer:reviewer – shell_pid=27188 – Done override: Mission squash-merged to main
