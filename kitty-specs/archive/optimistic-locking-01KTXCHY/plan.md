# Implementation Plan: Optimistic Locking

**Branch**: `main` | **Date**: 2026-06-12 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `kitty-specs/optimistic-locking-01KTXCHY/spec.md`
**Tracking**: #1647 | **Target release**: v0.1.0-alpha.207

## Summary

Concurrent saves are silent last-write-wins on every surface. Verified current state (full evidence in [research.md](research.md)):

1. **The persistence pipeline has the seam but not the feature.** `SaveContext` is the established per-save flag carrier (newest precedent: `withActorUid()`, [packages/entity-storage/src/SaveContext.php:83-93](../../packages/entity-storage/src/SaveContext.php)) and reaches storage through `EntityRepository::save(…, ?SaveContext $context)` ([packages/entity-storage/src/EntityRepository.php:354-357](../../packages/entity-storage/src/EntityRepository.php)). `doSave()` already loads the original entity for every update (`:465-469`, head revision readable via `getRevisionId()`), already wraps single saves in a transaction when a `DatabaseInterface` is wired (`:516`), and already issues targeted base-row UPDATEs through `DatabaseInterface` (deferred-revision pointer `:574-577`; `setPublishedRevision` `:952-956`) — every ingredient of a guarded pointer-claim exists; nothing consults an expectation.
2. **The base write is unconditional.** `SqlStorageDriver::write()` UPDATEs keyed only on the id ([packages/entity-storage/src/Driver/SqlStorageDriver.php:181-239](../../packages/entity-storage/src/Driver/SqlStorageDriver.php)); `UpdateInterface::execute(): int` returns affected rows ([packages/database-legacy/src/UpdateInterface.php:13](../../packages/database-legacy/src/UpdateInterface.php)) — the signal a guarded UPDATE needs is already in the query-builder surface.
3. **Non-revisionable types have no change marker.** Base tables materialise system keys + `_data` only; no universal `changed` column exists (research, "Non-revisionable change marker"). Scenario 6 resolves to the spec's rejection arm (research D2).
4. **The agent tool can't say "the version I read".** `EntityUpdateTool` saves with no context ([packages/ai-tools/src/Entity/EntityUpdateTool.php:102](../../packages/ai-tools/src/Entity/EntityUpdateTool.php)); `EntityKeyGuard` already *refuses* `revision_id` inside `values` (kind `revision`, [packages/ai-tools/src/Entity/EntityKeyGuard.php:33](../../packages/ai-tools/src/Entity/EntityKeyGuard.php)) and owns the Mission 1 two-block structured-error shape (`:82-149`) the conflict error will reuse. `EntityRepositoryInterface::save()` has no context parameter — concrete-class typing is the framework precedent (`EntityDestination`, research).
5. **The API update path can't either — and saves through the revision-less legacy storage.** `JsonApiController::update()` receives only the parsed body (`JsonApiRouter:61`; `WaaseyaaContext` carries no headers — `If-Match` is unreachable), and persists via `getStorage()->save()` = `SqlEntityStorage`, "No revision support" ([packages/api/src/JsonApiController.php:331,395](../../packages/api/src/JsonApiController.php); [packages/foundation/src/Kernel/AbstractKernel.php:222-226](../../packages/foundation/src/Kernel/AbstractKernel.php)). `JsonApiError::conflict()` (409) exists with this controller's own precedent use; `JsonApiError` lacks a `meta` member (additive widening).
6. **Reads mostly already expose the marker (FR-008).** API reads emit `revision_id` as an attribute (serializer excludes only id/uuid — research D5); `entity.list_revisions` exposes per-revision ids; `entity.read`/`entity.list` need a deterministic top-level member.

The work: `SaveContext::withExpectedRevisionId()` + `RevisionConflictException` + a two-stage race-safe check in `doSave()` (fail-fast pre-check on the already-loaded head, authoritative guarded pointer-claim UPDATE inside the existing transaction — D1) with explicit rejections for every unsupported path (D2/D6); `expected_revision_id` on the entity update tool with the Mission 1 structured `revision_conflict` error and identical dry-run reporting (D3); `data.meta.expected_revision_id` conditional PATCH with a 409 `REVISION_CONFLICT` + meta body (D4); revision exposure pinned/added on reads (D5); docs + CHANGELOG.

## Technical Context

**Language/Version**: PHP 8.5+ (charter baseline), Symfony 7.x components
**Primary Dependencies**: no new third-party deps. Two new internal manifest edges: `ai-tools → entity-storage` (L5→L1) and `api → entity-storage` (L4→L1) — both layer-legal, both pinned `^<current-tag>` per CP-NEW (research, "Manifest edges")
**Storage**: **no schema changes** (C-001 — `revision_id` IS the version column revision tracking already provides). One new exception class; one new guarded UPDATE on the expectation path only
**Testing**: PHPUnit 10.5 — entity-storage unit suites, ai-tools/api unit suites, new `tests/Integration/Locking/` (storage concurrency + invariance pins) and `tests/Integration/AgentRun/DualWriterConflictTest.php` (SC-001 end-to-end through a kernel-booted repository)
**Target Platform**: framework monorepo; ships in v0.1.0-alpha.207 under the CI-gated release flow (C-003)
**Project Type**: monorepo packages — `entity-storage` (L1), `ai-tools` (L5), `api` (L4), docs
**Performance Goals**: NFR-001 — the no-expectation path executes byte-identical code (every new branch behind one null check; pre-check reuses the existing original-entity load); pinned by a query-count test. Expectation path adds exactly one single-row UPDATE (+1 read on the racing-conflict branch)
**Constraints**: C-001 (additive only — optional ctor/builder params, optional tool argument, optional body meta, trailing `JsonApiError` params; no signature breaks: neither `EntityStorageDriverInterface` nor `EntityRepositoryInterface` is widened), C-002 (disjoint-merge pinned), C-004 (SaveContext + Mission 1 error shape + revision metadata — no parallel vocabulary), layer discipline, PHPStan baseline, dead-code gate, composer policy
**Scale/Scope**: 7 production files + 2 composer manifests + CHANGELOG + 4 spec docs; one new class (`RevisionConflictException`); no new packages, no new entity types, no new routes

## Charter Check

*GATE: evaluated 2026-06-12 against `.kittify/charter/charter.md`.*

- **PHP 8.5 baseline, Symfony components**: PASS — no new runtime deps beyond two internal manifest edges.
- **Per-package unit tests**: PASS — new/extended unit tests in `packages/entity-storage`, `packages/ai-tools`, `packages/api`, plus two integration suites (see Project Structure).
- **Quality gates (CI matrix, PHPStan baseline, composer policy, dead-code, getQuery)**: PASS — no exemptions requested; the new manifest edges satisfy CP-NEW (`^<current-tag>`, auto-advanced); the guarded UPDATE goes through the query builder (no `getQuery()` chain involved); everything ships wired.
- **DIRECTIVE_003 (decision documentation)**: six material decisions in [research.md](research.md) D1–D6 with rationale and rejected alternatives.
- **DIRECTIVE_010 (spec fidelity)**: one deliberate resolution pre-authorized by the spec itself — scenario 6's non-revisionable question resolves to the "cleanly rejected as unsupported" arm because no framework change marker exists (verified; research D2). One grounded adaptation: FR-006's "conditional-update semantics" ride request-body meta (`data.meta.expected_revision_id`) rather than an `If-Match` header, because headers cannot reach `JsonApiController` through `WaaseyaaContext` (verified; research D4 — header support is the documented additive follow-up). One documented behavioral asymmetry: an expectation-stated PATCH saves through the revision-aware repository pipeline (the legacy storage path cannot carry a context and does not move revisions) — opt-in only; the no-expectation PATCH is byte-identical (FR-003/SC-003).
- **Layer discipline**: PASS — entity-storage (L1) gains no new imports; ai-tools (L5) and api (L4) import L1 `entity-storage` on new declared edges; foundation untouched.

**Post-design re-check**: PASS — no new violations introduced; Complexity Tracking is empty.

## Project Structure

### Documentation (this feature)

```
kitty-specs/optimistic-locking-01KTXCHY/
├── plan.md              # This file
├── research.md          # Phase 0 — verified ground truth + decisions D1–D6
├── data-model.md        # Phase 1 — SaveContext/exception shapes, rejection matrix, error payloads
├── quickstart.md        # Phase 1 — SC-002 approve-time staleness recipe + reviewer script
├── contracts/
│   ├── conflict-detection.md    # FR-001..004, FR-007, NFR-001/002 — storage-level contract
│   └── conflict-surfaces.md     # FR-005/006/008, NFR-003 — tool + API shapes
└── tasks.md             # Phase 2 — WP01..WP03 breakdown (produced in the same pass)
```

### Source Code (repository root)

```
packages/entity-storage/src/
├── SaveContext.php                    # MODIFY — withExpectedRevisionId(?int) builder + expectedRevisionId()
│                                      #          accessor; re-threaded through every existing builder
│                                      #          (mirror withActorUid; D1)
├── EntityRepository.php               # MODIFY — doSave(): expectation pre-check after validation on the
│                                      #          already-loaded head; guarded pointer-claim UPDATE inside
│                                      #          the existing transaction; rejection matrix for unsupported
│                                      #          paths (D1/D2/D6, FR-001/004/007)
└── Exception/
    └── RevisionConflictException.php  # NEW — entityTypeId/entityId/expectedRevisionId/currentRevisionId(?int)
                                       #       + errorCode 'REVISION_CONFLICT' (PartialSaveException shape, FR-002)

packages/ai-tools/
├── composer.json                      # MODIFY — + waaseyaa/entity-storage (^current-tag, CP-NEW)
└── src/Entity/
    ├── EntityUpdateTool.php           # MODIFY — expected_revision_id argument; SaveContext threading via
    │                                  #          instanceof EntityRepository; revision_conflict structured
    │                                  #          error; dry-run conflict reporting; success payload gains
    │                                  #          revision_id (D3, FR-005)
    ├── EntityReadTool.php             # MODIFY — top-level revision_id member (duck-typed getRevisionId; D5, FR-008)
    └── EntityListTool.php             # MODIFY — per-item revision_id member (D5, FR-008)

packages/api/
├── composer.json                      # MODIFY — + waaseyaa/entity-storage (^current-tag, CP-NEW)
└── src/
    ├── JsonApiController.php          # MODIFY — update(): data.meta.expected_revision_id parsing + screening;
    │                                  #          expectation-stated saves through getRepository()->save(context:);
    │                                  #          RevisionConflictException → 409 REVISION_CONFLICT;
    │                                  #          no-expectation path untouched (D4, FR-003/006)
    └── JsonApiError.php               # MODIFY — additive meta member (ctor param + toArray emission);
                                       #          conflict() gains optional code/meta passthrough (D4)

CHANGELOG.md                           # MODIFY — [Unreleased], inserted directly after the heading line:
                                       #          opt-in optimistic locking (storage/tool/API), the
                                       #          expectation-stated PATCH pipeline note, rejection matrix
docs/specs/revision-system-unified.md  # MODIFY — §3 Save contract: expectation semantics + claim mechanism
docs/specs/entity-system.md            # MODIFY — repository save contract + RevisionConflictException;
                                       #          also clears the Mission 3 drift flag (discoverable one-liner)
docs/specs/api-layer.md                # MODIFY — PATCH conditional-update contract, 409 catalogue entry,
                                       #          revision_id attribute documented load-bearing
docs/specs/ai-integration.md           # MODIFY — entity.update expected_revision_id + revision_conflict error,
                                       #          read/list revision exposure

tests:
packages/entity-storage/tests/Unit/SaveContext/SaveContextExpectedRevisionTest.php  # NEW — builder/accessor/
                                                                                    #   re-threading matrix
packages/entity-storage/tests/Unit/EntityRepositoryOptimisticLockingTest.php  # NEW — pre-check conflict, guard
                                                                              #   success, rejection matrix,
                                                                              #   vanished-row conflict
tests/Integration/Locking/ConcurrentSaveConflictTest.php   # NEW — NFR-002 exactly-one-winner pin (deterministic
                                                           #   interleave via BeforeSaveEvent competing writer)
tests/Integration/Locking/NoExpectationInvarianceTest.php  # NEW — NFR-001 query-count pin + C-002 disjoint-merge pin
packages/ai-tools/tests/Unit/Entity/EntityUpdateToolConflictTest.php   # NEW — arg/conflict/dry-run/unsupported matrix
packages/ai-tools/tests/Unit/Entity/EntityToolRevisionExposureTest.php # NEW — read/list revision_id exposure
packages/api/tests/Unit/JsonApiControllerConflictTest.php  # NEW — conditional PATCH matrix incl. 409 shape +
                                                           #   no-expectation invariance + show() revision_id pin
packages/api/tests/Unit/JsonApiErrorTest.php               # extend — meta member emission/omission
tests/Integration/AgentRun/DualWriterConflictTest.php      # NEW — SC-001 end-to-end dual-writer through the
                                                           #   agent tool against a kernel-booted repository
```

**Structure Decision**: One new class total (the exception). The storage change lands entirely inside `doSave()`'s existing sequence — the pre-check rides the original-entity load that already happens, the claim rides the transaction and the repository→`DatabaseInterface` UPDATE seam that already exist (two in-file precedents). The tool/API changes are argument-parsing + one alternate save call + one catch arm each, reusing Mission 1's error vocabulary and the controller's existing 409 factory. No interface is widened anywhere (`EntityStorageDriverInterface`, `EntityRepositoryInterface`, `EntityTypeInterface` all untouched — research grounds each).

## Design Outline

1. **`SaveContext::withExpectedRevisionId(?int)` (D1)** — trailing private ctor param `private readonly ?int $expectedRevisionId = null`, builder returning a new instance, accessor `expectedRevisionId(): ?int`. `null` ⇔ no expectation (so callers thread an optional value without branching: `->withExpectedRevisionId($maybeNull)` is the documented no-expectation pass-through); integers < 1 throw `InvalidArgumentException`. Every existing builder re-threads the field (the `withActorUid` re-threading pattern — and vice versa: the new builder re-threads actor/translations/etc.). No paired boolean pin needed (unlike actor, a null expectation has no third meaning — research).
2. **`RevisionConflictException` (FR-002)** — `final class … extends \RuntimeException`, promoted `public readonly string $entityTypeId`, `string $entityId`, `int $expectedRevisionId`, `?int $currentRevisionId` (null = row vanished), `string $errorCode = 'REVISION_CONFLICT'`; constructor-composed message naming all four (PartialSaveException house shape, including the `$errorCode`-not-`$code` rationale). Deterministic content: nothing timing-dependent beyond the two ids (NFR-003).
3. **Rejection gate (D2/D6, FR-007)** — first thing in `doSave()` when `expectedRevisionId() !== null`: throw `\LogicException` (distinct greppable messages) for new entities, non-revisionable types, two-axis (revisionable+translatable) types, and missing `DatabaseInterface`; after `shouldCreateRevision()` resolves, also for non-revision-creating saves. Paths with no SaveContext parameter (`rollback`, `setCurrentRevision`, `setPublishedRevision`, `saveTranslation*`, `saveMany`) cannot receive an expectation — documented, not code.
4. **Fail-fast pre-check (FR-001)** — after validation, immediately after `$originalEntity = $this->find($id)` (`:465-469`): `$current = $originalEntity?->getRevisionId()`; `$originalEntity === null || $current !== $expected` → throw `RevisionConflictException` before `preSave()`, before any event, before any write. Zero added queries (NFR-001's pre-check cost is free — the load already happens).
5. **Guarded pointer-claim (FR-004, D1)** — inside the existing transaction, after `writeRevision()` and before `driver->write()`: `$this->database->update($entityTypeId)->fields([$revisionKey => $newRevisionId])->condition($idKey, $id)->condition($revisionKey, $expected)->execute()`. Affected `1` → proceed (the subsequent full write re-asserts the same pointer, same transaction). Affected `0` → roll back (revision row included), re-read the base row for the current head, throw `RevisionConflictException`. Unambiguous on all backends because the SET always changes the value; two writers stating the same expectation → exactly one claim matches (NFR-002). The deterministic concurrency pin interleaves a competing save via a `BeforeSaveEvent` subscriber (events fire after the pre-check, before the transaction — the subscriber's save moves the head, the outer save's claim catches it; no threads needed).
6. **Tool surface (D3, FR-005)** — `expected_revision_id` (integer ≥ 1) in `inputSchema`; execute threads `SaveContext::default()->withExpectedRevisionId($n)` through `$repository->save($entity, context: …)` behind `instanceof EntityRepository` (non-concrete repository + stated expectation → structured `revision_expectation_unsupported` error, never silent); `catch (RevisionConflictException)` before the `\Throwable` arm → two-block error with `data: {error: 'revision_conflict', entity_type, id, expected, current}`; `\LogicException` rejections → `revision_expectation_unsupported`. Success payload gains the post-save `revision_id`. Dry-run with an expectation loads the entity and reports a head mismatch with the byte-identical `revision_conflict` payload (edge case); without an expectation dry-run is unchanged.
7. **API surface (D4, FR-006)** — `update()` reads `data.meta.expected_revision_id`: invalid type → 400; non-single-axis-revisionable type → 422 (friendly screen; storage `\LogicException` remains the backstop); stated → `getRepository()->save($entity, context: …)` with `EntityValidationException → 422` and `RevisionConflictException → 409` `JsonApiError::conflict(detail, code: 'REVISION_CONFLICT', meta: ['expected_revision_id' => N, 'current_revision_id' => M])`; absent → the existing `getStorage()->save()` line byte-identical. `JsonApiError` gains an additive `meta` member (ctor param + conditional `toArray()` emission) and `conflict()` gains optional `code`/`meta` passthrough.
8. **Read exposure (D5, FR-008)** — pin `revision_id` in `show()` attributes (already emitted — load-bearing now); `entity.read` + `entity.list` gain a top-level/per-item duck-typed `revision_id`; `entity.list_revisions` unchanged.
9. **Docs & CHANGELOG (C-003)** — `[Unreleased]` entries inserted directly after the heading line; four spec docs updated from the contracts; `entity-system.md` additionally gets the sanctioned Mission 3 `discoverable` cross-ref one-liner (clears the pending drift flag); drift detector run.

## Risks (premortem)

- **The claim UPDATE deadlocks/blocks under contention** — InnoDB row-lock waits or SQLite `SQLITE_BUSY` on the second writer. Accepted: the wait resolves to a clean 0-affected → conflict; SQLite busy-timeout behavior surfaces as a driver exception only under pathological hold times (transactions here are short). The concurrency pin runs the deterministic single-process interleave; true multi-process raciness is covered by the mechanism argument (research D1), not by a flaky test.
- **`driver->write()` after the claim re-writes the whole row unconditionally** — safe only because it runs in the same transaction as the successful claim; a future refactor that moves the base write outside the transaction would reopen the race. The contract pins "claim and full write share one transaction" explicitly.
- **Someone threads an expectation through a path the guard doesn't cover** (e.g. adds SaveContext to `saveMany()` later without the gate). Mitigation: the rejection gate lives at the top of `doSave()` — the single chokepoint every save funnels through — and the contract's rejection matrix is the review checklist for any future SaveContext threading.
- **Expectation-stated PATCH changes save semantics** (repository pipeline: events, validation, a revision cut) versus the legacy storage path. Intended and opt-in, but a consumer could be surprised that adding one meta member starts cutting revisions. CHANGELOG + contract state it in bold; the no-expectation invariance test pins the old path byte-for-byte (SC-003).
- **`revision_id` attribute disappears from API reads** (e.g. a future serializer "cleanup" excludes the revision key alongside id/uuid) — would silently break every expectation-forming client. Mitigation: the WP02 pin test + api-layer.md documenting it as load-bearing (FR-008).
- **Numeric-string ids / uuid-routed PATCHes** — `update()` accepts uuid locators; the guard keys on the real id from the loaded entity, not the request locator. Pinned in the controller test matrix.
- **`data.meta` collides with future JSON:API meta use** — `expected_revision_id` is namespaced by member name only. Accepted: JSON:API meta is free-form; the contract reserves this one member name on the PATCH request shape.
- **Two-axis carve-out surprises a consumer** whose type later flips `translatable: true` — previously-working expectations start rejecting with `\LogicException`/422. The message names the reason and the `saveTranslation` workflow; revision-system-unified.md documents the boundary and the langcode-scoped-guard lift path.
- **CP-NEW literal drift** — the new composer edges must equal `^<latest-tag>` at merge time (alpha.206 may be cut mid-mission). WP02 instructs running `git describe` + `composer check-composer-policy` rather than hardcoding from this plan.
- **InMemory/driver-only repositories in consumer tests** state expectations and hit the no-database rejection. Correct behavior (fail loud, not unsafe-quiet); the message says "requires a database connection" mirroring `saveMany()`.

## Complexity Tracking

*No charter violations to justify.*
