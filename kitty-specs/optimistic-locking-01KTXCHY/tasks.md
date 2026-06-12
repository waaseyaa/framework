# Tasks: Optimistic Locking

**Mission**: `optimistic-locking-01KTXCHY` | **Branch**: `main` → merges to `main`
**Input**: [spec.md](spec.md), [plan.md](plan.md), [research.md](research.md) (D1–D6), [data-model.md](data-model.md), [contracts/](contracts/)
**Tracking**: #1647 | **Target release**: v0.1.0-alpha.207

## Subtask Index

| ID | Description | WP | Parallel |
|----|-------------|----|----------|
| T001 | SaveContext: withExpectedRevisionId(?int) builder + accessor + full re-threading + unit matrix | WP01 | | [D] | [D] |
| T002 | RevisionConflictException (entityTypeId/id/expected/current + errorCode, PartialSaveException shape) | WP01 | | [D] |
| T003 | doSave(): rejection gate (new/non-revisionable/two-axis/no-db/non-revision-creating) + fail-fast pre-check after validation | WP01 | | [D] |
| T004 | doSave(): guarded pointer-claim UPDATE inside the existing transaction; rollback + re-read + throw on 0 affected | WP01 | | [D] |
| T005 | Concurrency pin (NFR-002, deterministic BeforeSaveEvent interleave) + repository unit matrix | WP01 | | [D] |
| T006 | No-expectation invariance pins (NFR-001 query count, C-002 disjoint merge) + WP01 gates | WP01 | | [D] |
| T007 | Manifest edges: ai-tools + api require waaseyaa/entity-storage (CP-NEW pin) | WP02 | | [D] |
| T008 | EntityUpdateTool: expected_revision_id arg + SaveContext threading + revision_conflict / unsupported structured errors + success revision_id | WP02 | | [D] |
| T009 | EntityUpdateTool dry-run conflict parity | WP02 | | [D] |
| T010 | Read exposure: EntityReadTool top-level revision_id + EntityListTool per-item revision_id | WP02 | | [D] |
| T011 | JsonApiError additive meta + JsonApiController conditional update (data.meta seam, 409 REVISION_CONFLICT, show() pin) | WP02 | | [D] |
| T012 | End-to-end dual-writer test (SC-001) + WP02 gates | WP02 | | [D] |
| T013 | CHANGELOG [Unreleased] entries (anchor directly after the heading line) | WP03 | | [D] |
| T014 | Spec docs: revision-system-unified.md, entity-system.md (+ discoverable cross-ref), api-layer.md, ai-integration.md | WP03 | | [D] |
| T015 | Drift detector run; resolve flags for the four touched specs | WP03 | | [D] |
| T016 | Execute quickstart.md steps 1–6 as final validation; record per-step results | WP03 | | [D] |

## WP01 — Storage Conflict Detection (#1647)

**Prompt**: [tasks/WP01-storage-conflict-detection.md](tasks/WP01-storage-conflict-detection.md) | **Priority**: P1 | **Estimated prompt**: ~330 lines
**Goal**: A caller can state an expected revision through `SaveContext`; a save against a moved head is refused before any write with a structured `RevisionConflictException`; the check is race-safe (guarded pointer-claim inside the existing transaction — exactly one winner, NFR-002); every unsupported path rejects explicitly (D2/D6, FR-007); the no-expectation path is byte-identical with zero added queries (NFR-001) and the disjoint-field merge mechanic intact (C-002).
**Independent test**: `./vendor/bin/phpunit packages/entity-storage/tests/ tests/Integration/Locking/` green, including the exactly-one-winner concurrency pin and the query-count invariance pin.
**Dependencies**: none (lane root)

- [x] T001 SaveContext: trailing `?int $expectedRevisionId = null` ctor param; `withExpectedRevisionId(?int)` builder (null = explicit no-expectation pass-through; `< 1` → InvalidArgumentException); `expectedRevisionId(): ?int` accessor; field re-threaded through every existing builder and every existing field re-threaded through the new builder; NEW `SaveContextExpectedRevisionTest` re-threading matrix (WP01)
- [x] T002 NEW `Exception/RevisionConflictException.php`: final, extends \RuntimeException, promoted readonly `entityTypeId`/`entityId`/`expectedRevisionId`/`currentRevisionId(?int)`/`errorCode = 'REVISION_CONFLICT'`, constructor-composed deterministic message (PartialSaveException house shape incl. the $errorCode-not-$code note) (WP01)
- [x] T003 doSave(): expectation rejection gate (isNew, non-revisionable, two-axis, no DatabaseInterface — at top; non-revision-creating — after shouldCreateRevision), distinct \LogicException messages; fail-fast pre-check immediately after the original-entity load (after validation, before preSave/any event): mismatch or vanished row → RevisionConflictException before any write (WP01)
- [x] T004 doSave(): guarded pointer-claim `UPDATE base SET revisionKey = :new WHERE idKey = :id AND revisionKey = :expected` via $this->database inside the existing transaction, after writeRevision and before driver->write; affected 0 → rollback, re-read head, throw RevisionConflictException; affected 1 → proceed unchanged (WP01)
- [x] T005 NEW `EntityRepositoryOptimisticLockingTest` (match/mismatch/vanished/no-events-on-refusal/rejection-matrix/validation-order) + NEW `tests/Integration/Locking/ConcurrentSaveConflictTest.php` — deterministic interleave via BeforeSaveEvent competing save; exactly one winner, loser's revision row rolled back (NFR-002 / SC-004) (WP01)
- [x] T006 NEW `tests/Integration/Locking/NoExpectationInvarianceTest.php` — counting DatabaseInterface decorator pins per-save query count unchanged (NFR-001); disjoint-field merge pins with and without expectations (C-002); gates: entity-storage suite, `composer phpstan`, `composer cs-check`, `bin/check-dead-code`, `bin/check-package-layers` (WP01)

**Implementation sketch**: research D1/D2/D6, contracts/conflict-detection.md is the authoritative behavior spec — every numbered clause must hold. The whole change is inside SaveContext + doSave() + one new exception; the claim UPDATE reuses the deferred-revision pointer-update seam already in the file. Biggest review risk: the claim and the full base write must share one transaction, and every new branch must sit behind `expectedRevisionId() !== null`.

## WP02 — Tool + API Surfaces (#1647)

**Prompt**: [tasks/WP02-tool-api-surfaces.md](tasks/WP02-tool-api-surfaces.md) | **Priority**: P1 | **Estimated prompt**: ~340 lines
**Goal**: `entity.update` accepts `expected_revision_id` and surfaces conflicts as the Mission 1 structured `revision_conflict` error with identical dry-run reporting (FR-005); the JSON:API PATCH accepts `data.meta.expected_revision_id` and responds 409 `REVISION_CONFLICT` with both ids in meta (FR-006); the current revision is readable on every expectation-forming surface (FR-008); the dual-writer scenario is proven end-to-end through the agent tool against a kernel-booted repository (SC-001); no-expectation calls are byte-identical on both surfaces.
**Independent test**: `./vendor/bin/phpunit packages/ai-tools/tests/ packages/api/tests/ tests/Integration/AgentRun/DualWriterConflictTest.php` green, including the existing tool/controller suites unchanged.
**Dependencies**: WP01

- [ ] T007 Manifest edges: `packages/ai-tools/composer.json` + `packages/api/composer.json` gain `waaseyaa/entity-storage` pinned `^<latest v-tag at merge time>` (run `git describe --tags --abbrev=0 --match='v*.*.*'`; `composer check-composer-policy` is the gate); sorted per CP; layers stay downward (WP02)
- [ ] T008 EntityUpdateTool: `expected_revision_id` (integer, minimum 1) in inputSchema; invalid type → arg error; threading via `instanceof EntityRepository` + `save($entity, context: SaveContext::default()->withExpectedRevisionId($n))`; non-concrete repository + expectation → structured `revision_expectation_unsupported`; `catch (RevisionConflictException)` before \Throwable → two-block `revision_conflict` payload (entity_type/id/expected/current); \LogicException rejections → `revision_expectation_unsupported`; success payload gains post-save `revision_id`; NEW `EntityUpdateToolConflictTest` (WP02)
- [ ] T009 EntityUpdateTool::dryRun(): with `expected_revision_id`, load + compare head; mismatch → byte-identical `revision_conflict` payload (pinned by comparison against execute()'s payload in the same world); match → existing `would_update`; without the argument dry-run byte-identical to today (WP02)
- [ ] T010 EntityReadTool: top-level `revision_id` via duck-typed getRevisionId() (omitted on non-revisionable); EntityListTool: per-item `revision_id`; EntityListRevisionsTool untouched; NEW `EntityToolRevisionExposureTest` (FR-008) (WP02)
- [ ] T011 JsonApiError: additive trailing `array $meta = []` + conditional toArray emission + `conflict()` code/meta passthrough (existing bytes pinned unchanged in `JsonApiErrorTest`); JsonApiController::update(): parse `data.meta.expected_revision_id` (invalid → 400; non-single-axis-revisionable → 422), expectation-stated saves via `getRepository()->save(…, context:)` with EntityValidationException → 422 and RevisionConflictException → 409 `REVISION_CONFLICT` + meta; no-expectation path untouched; uuid-locator resolution honest; show() `revision_id` attribute pin; NEW `JsonApiControllerConflictTest` (WP02)
- [ ] T012 NEW `tests/Integration/AgentRun/DualWriterConflictTest.php` — SC-001: kernel-booted repository, writer A reads head R (tool read), writer B updates (head R+1), A's `entity.update` with `expected_revision_id: R` → structured conflict with B's write intact; A re-reads + retries → success; contrast case without expectations documents last-write-wins; gates: ai-tools + api suites, `composer phpstan`, `composer cs-check`, `bin/check-package-layers`, `composer check-composer-policy`, `bin/check-dead-code` (WP02)

**Implementation sketch**: research D3–D5, contracts/conflict-surfaces.md authoritative. Both surfaces only translate the storage contract — no surface-level head comparison on the execute path (dry-run's read-compare is the documented exception). Biggest review risks: the no-expectation invariance on both surfaces (existing test suites must pass unchanged), and the JsonApiError widening leaving every existing error byte-identical.

## WP03 — Docs + CHANGELOG + Quickstart Walkthrough

**Prompt**: [tasks/WP03-docs-changelog.md](tasks/WP03-docs-changelog.md) | **Priority**: P2 | **Estimated prompt**: ~240 lines
**Goal**: The release is documented before the tag exists: CHANGELOG under `[Unreleased]` (entries inserted directly after the heading line — merge-conflict-avoidance pattern, never a pre-stamped alpha.207 heading), the four subsystem specs updated from the contracts (entity-system.md also clears the pending Mission 3 drift flag with the sanctioned `discoverable` cross-ref), drift detector clean, quickstart walkthrough recorded.
**Independent test**: quickstart.md steps 1–6 pass end-to-end; `composer verify` components green.
**Dependencies**: WP01, WP02

- [ ] T013 CHANGELOG `[Unreleased]`, inserted directly after the heading line (alpha.206-targeted Mission 3 entries may still be present — append above/alongside, never restructure): **Added** — opt-in optimistic locking: `SaveContext::withExpectedRevisionId()` + `RevisionConflictException` (storage), `expected_revision_id` on `entity.update` with structured `revision_conflict` errors + dry-run parity, JSON:API conditional PATCH via `data.meta.expected_revision_id` → 409 `REVISION_CONFLICT`, `revision_id` exposure on tool reads/lists, `JsonApiError` meta member; **Changed** — note: an expectation-stated PATCH persists through the revision-aware repository pipeline (cuts a revision); no-expectation saves byte-identical everywhere; **note** the rejection matrix (non-revisionable/two-axis/non-revision-creating/new entities reject explicitly) (WP03)
- [ ] T014 docs/specs updates from the contracts (not from the diff): `revision-system-unified.md` §3 Save contract — expectation semantics, claim mechanism, two-axis carve-out + lift path; `entity-system.md` — repository save contract + RevisionConflictException + the Mission 3 `discoverable` one-liner cross-ref (sanctioned drift-flag clear); `api-layer.md` — conditional PATCH contract, 409 catalogue entry (REVISION_CONFLICT vs the codeless uuid-mismatch 409), `revision_id` attribute documented load-bearing, If-Match non-support + follow-up note; `ai-integration.md` — entity.update argument + error shapes, read/list revision exposure, approve-time staleness pattern pointer to quickstart (WP03)
- [ ] T015 `tools/drift-detector.sh` run; resolve flags for the four touched specs (WP03)
- [ ] T016 Execute quickstart.md steps 1–6 as final validation; record per-step results in the WP file (WP03)

**Implementation sketch**: write docs from the two contracts and data-model.md. CHANGELOG-under-[Unreleased]-after-the-heading-line is the standing pattern; the release-cut workflow stamps the heading. `[Unreleased]` likely still carries the alpha.206 (Mission 3) entries — coexist, don't restructure.

## Lane / Parallelization Summary

- **Lane A (serial)**: WP01 → WP02 → WP03
- WP02 consumes WP01's SaveContext/exception API directly — no parallel start.
- MVP scope: WP01 + WP02 (all eight FRs live); WP03 gates the release cut.
