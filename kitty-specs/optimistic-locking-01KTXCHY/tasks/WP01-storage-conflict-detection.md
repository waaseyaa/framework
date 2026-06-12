---
work_package_id: WP01
title: Storage Conflict Detection
dependencies: []
requirement_refs:
- C-001
- C-002
- FR-001
- FR-002
- FR-003
- FR-004
- FR-007
- NFR-001
- NFR-002
- NFR-003
planning_base_branch: main
merge_target_branch: main
branch_strategy: Planning artifacts for this feature were generated on main. During /spec-kitty.implement this WP may branch from a dependency-specific base, but completed changes must merge back into main unless the human explicitly redirects the landing branch.
base_branch: kitty/mission-optimistic-locking-01KTXCHY
base_commit: c4d61b3e7b05c67dfb991572ac1702de70fe0dc4
created_at: '2026-06-12T08:10:38.311993+00:00'
subtasks:
- T001
- T002
- T003
- T004
- T005
- T006
shell_pid: "20768"
agent: "claude:fable-5:implementer:implementer"
history:
- date: '2026-06-12T00:00:00Z'
  event: created
  by: spec-kitty.tasks
authoritative_surface: packages/entity-storage/src/
execution_mode: code_change
owned_files:
- packages/entity-storage/src/SaveContext.php
- packages/entity-storage/src/EntityRepository.php
- packages/entity-storage/src/Exception/RevisionConflictException.php
- packages/entity-storage/tests/Unit/SaveContext/SaveContextExpectedRevisionTest.php
- packages/entity-storage/tests/Unit/EntityRepositoryOptimisticLockingTest.php
- tests/Integration/Locking/**
tags: []
---

# WP01 — Storage Conflict Detection

**Mission**: optimistic-locking-01KTXCHY | **Tracks**: #1647
**Requirements**: FR-001..FR-004, FR-007, NFR-001..NFR-003, C-001, C-002 | **Dependencies**: none
**Command**: `spec-kitty agent action implement WP01 --agent <name>`

## Objective

After this WP: a caller can state "I am updating revision N" via `SaveContext::withExpectedRevisionId(N)`; a save against a moved head throws `RevisionConflictException` (entity type, id, expected, current) **before any write and before any lifecycle event**; two concurrent saves stating the same expectation cannot both succeed (guarded pointer-claim UPDATE inside the existing transaction — exactly one winner); every path that cannot honor an expectation rejects it with a distinct `\LogicException`; and saves with no expectation are byte-identical to today — zero added queries, disjoint-field merge intact.

## Context (read first)

- `research.md` "Verified ground truth" + D1 (the two-stage mechanism and why a plain re-read is not race-safe), D2 (non-revisionable = no change marker → reject), D6 (the full rejection matrix and the `\LogicException`-vs-`RevisionConflictException` distinction).
- `contracts/conflict-detection.md` — the authoritative behavior spec; every numbered clause (1–17) must hold.
- `data-model.md` "Conflict decision per save" — the exact decision tree to implement.
- **doSave() sequence reality** (`packages/entity-storage/src/EntityRepository.php:438-630`): context+actor (`:444-450`) → validation (`:452-463`) → original-entity load (`:465-469`) → preSave + PRE_SAVE (`:471-479`) → shouldCreateRevision (`:481`) → BeforeSaveEvent (`:489-493`) → transaction (`:516`) → writeRevision sets `$values['revision_id']` (`:524-531`) → base write (`:559`) → deferred pointer (`:566-581`) → commit (`:583`). The pre-check goes right after `:469`; the claim goes between the revision write and `driver->write()`.
- **The claim seam has two in-file precedents**: the deferred-revision pointer update (`:574-577`) and `setPublishedRevision()`'s targeted update (`:952-956`) — both `$this->database->update($table)->fields([...])->condition(...)->execute()`. `UpdateInterface::execute(): int` returns affected rows (`packages/database-legacy/src/UpdateInterface.php:13`).
- **SaveContext builder precedent**: `withActorUid()` (`packages/entity-storage/src/SaveContext.php:83-93`) — trailing private ctor param, accessor, and EVERY existing builder re-threads the new field (check all six construction sites in the file). Unlike actor, no boolean pin: null expectation has no third meaning (research, "SaveContext" ground truth).
- **Exception house shape**: `PartialSaveException` (`packages/entity-storage/src/Exception/PartialSaveException.php`) — final, extends `\RuntimeException`, promoted public readonly payload, `$errorCode` not `$code` (the docblock explains why — replicate the note), constructor-composed message, `@api`.
- **Head readability**: `$originalEntity->getRevisionId()` reads `values['revision_id']` (`packages/entity/src/RevisionableEntityTrait.php:149-158`); the raw pointer is also on the base row via `$this->driver->read()` (the `loadRevision()` pattern, `:700-701`).
- **Transactions**: single saves open `$this->database?->transaction()` when not inside a UnitOfWork (`:516`); `$this->database` is nullable (driver-only constructions get NO transaction — that's the no-database rejection). Inside `saveMany()` the UnitOfWork transaction wraps and `$transaction` is null — but `saveMany()` cannot carry a SaveContext at all (`:364-384`), so no expectation reaches that path; do NOT thread one.

## Requirement / contract map

| Deliverable | Requirement | Contract anchor |
|---|---|---|
| `withExpectedRevisionId()` + accessor | FR-001 | conflict-detection.md §1 |
| `RevisionConflictException` payload | FR-002, NFR-003 | §5 |
| Pre-write refusal, no events | FR-001 | §4, §6 |
| Guarded claim, one transaction | FR-004 | §7–9 |
| Exactly-one-winner pin | NFR-002 / SC-004 | §10 |
| Rejection matrix | FR-007 | §11–13 |
| Byte-identical no-expectation path | FR-003 / SC-003 | §14 |
| Zero added queries pin | NFR-001 | §15 |
| Disjoint-merge pin | C-002 | §16 |

## Out of scope for this WP (do not touch)

- `packages/ai-tools/**`, `packages/api/**` — WP02 owns the surfaces (including the new composer edges).
- `EntityStorageDriverInterface`, `EntityRepositoryInterface`, `Storage\EntityStorageInterface` — deliberately NOT widened (research grounds each; PHP optional-param redeclaration breaks implementors).
- `SqlStorageDriver::write()` — stays unconditional; the claim composes with it, never replaces it.
- `rollback()`/`setCurrentRevision()`/`setPublishedRevision()`/`saveTranslation*()`/`saveMany()` — no SaveContext threading (contract §13).
- CHANGELOG and `docs/specs/**` — WP03 owns documentation.

## Subtasks

### T001 — SaveContext expectation builder

**Files**: `packages/entity-storage/src/SaveContext.php`, `packages/entity-storage/tests/Unit/SaveContext/SaveContextExpectedRevisionTest.php` (NEW)

1. Trailing private ctor param `private readonly ?int $expectedRevisionId = null` (after `$actorOverridden`).
2. Builder:
   ```php
   /**
    * Return a new instance stating an optimistic-locking expectation
    * (mission optimistic-locking-01KTXCHY, FR-001): the save is refused with
    * {@see \Waaseyaa\EntityStorage\Exception\RevisionConflictException} when
    * the entity's current revision differs from $revisionId at write time.
    * `null` is the explicit no-expectation pass-through (callers may thread
    * an optional value without branching). Honored only on revision-creating
    * saves of single-axis revisionable types — see contracts/conflict-detection.md §2.
    *
    * @throws \InvalidArgumentException When $revisionId < 1.
    * @api
    */
   public function withExpectedRevisionId(?int $revisionId): self
   ```
   Reject `$revisionId !== null && $revisionId < 1`.
3. Accessor `expectedRevisionId(): ?int` (`@api`).
4. Re-thread `expectedRevisionId: $this->expectedRevisionId` through EVERY existing builder (`withActorUid`, `withoutNewRevision`, `withLangcode`, `asImport`, `withTranslations`) and every existing field through the new builder. `default()` unchanged (named args already omit it).
5. Tests: default null; builder sets; null pass-through; `0`/negative throw; immutability (original unchanged); the full re-threading matrix both directions (each builder preserves the expectation; the expectation builder preserves langcode/translations/actor pair/isImport/withoutNewRevision).

**Validation**: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/SaveContext/ --no-progress`; `composer phpstan`.

### T002 — RevisionConflictException

**Files**: `packages/entity-storage/src/Exception/RevisionConflictException.php` (NEW)

Mirror `PartialSaveException` exactly in shape: `final class RevisionConflictException extends \RuntimeException`, `@api`, promoted `public readonly string $entityTypeId`, `public readonly string $entityId`, `public readonly int $expectedRevisionId`, `public readonly ?int $currentRevisionId`, `public readonly string $errorCode = 'REVISION_CONFLICT'`. Constructor composes a deterministic message naming all four (current rendered as `none` when null — the row vanished). Replicate the "$errorCode, not $code" docblock note. No timestamps, no entity payloads (NFR-003 — assertable bytes).

**Validation**: covered via T005's unit matrix; `bin/check-dead-code` (wired by T003 — no `@api`-only dormancy beyond the class-level marker).

### T003 — Rejection gate + fail-fast pre-check

**Files**: `packages/entity-storage/src/EntityRepository.php`

1. At the top of `doSave()`, right after `$resolvedContext` resolves (`:446`), when `$resolvedContext->expectedRevisionId() !== null`:
   - `$isNew` → `\LogicException` "Cannot state a revision expectation for a new (unsaved) entity …"
   - `!$this->entityType->isRevisionable()` → `\LogicException` "… entity type '<id>' is not revisionable; revision expectations require revision tracking" (D2).
   - `$this->entityType->isRevisionable() && $this->entityType->isTranslatable()` → `\LogicException` naming the two-axis carve-out and the `saveTranslation` workflow.
   - `$this->database === null` → `\LogicException` "… requires a database connection" (mirror the `saveMany()` message style).
   Distinct, greppable messages — the tool/API surfaces translate them (WP02).
2. After `$createRevision = $this->shouldCreateRevision(...)` (`:481`): expectation stated && `!$createRevision` → `\LogicException` naming `withoutNewRevision`/`setNewRevision(false)`/`revisionDefault` and the fix (`setNewRevision(true)`).

   **Ordering subtlety**: clause-1 checks run before `preSave()`/`PRE_SAVE`; the `$createRevision` check necessarily runs after them (that's where the value exists). Acceptable: it is a `\LogicException` caller error, not a conflict — only conflicts must precede all events (contract §4 binds conflicts, §11 binds rejections to "explicit", not "pre-event").
3. Pre-check, immediately after the original-entity load (`:465-469`), expectation stated only:
   ```php
   if ($expected !== null) {
       $current = ($originalEntity instanceof RevisionableInterface) ? $originalEntity->getRevisionId() : null;
       if ($originalEntity === null || $current !== $expected) {
           throw new RevisionConflictException($entityTypeId, $id, $expected, $originalEntity === null ? null : $current);
       }
   }
   ```
   Before `preSave()`, before PRE_SAVE, before BeforeSaveEvent — a refused save dispatches NOTHING (contract §4/§6; pin with a spy dispatcher).

**Validation**: T005 unit matrix; `composer cs-check`.

### T004 — Guarded pointer-claim inside the transaction

**Files**: `packages/entity-storage/src/EntityRepository.php`

1. In the non-deferred revision branch (`:524-531`), after `writeRevision()` returns `$revisionId` and `$values['revision_id']` is set, expectation stated only:
   ```php
   $revisionKey = $this->entityType->getKeys()['revision'] ?? 'revision_id';
   $idKeyName = $this->entityType->getKeys()['id'] ?? 'id';
   $claimed = $this->database->update($entityTypeId)
       ->fields([$revisionKey => $revisionId])
       ->condition($idKeyName, $id)
       ->condition($revisionKey, $expected)
       ->execute();
   if ($claimed !== 1) {
       throw new RevisionConflictException(/* current re-read AFTER rollback — see step 2 */);
   }
   ```
   The deferred-revision branch (`$id === ''`) is unreachable with an expectation (new-entity rejection in T003) — assert/comment, don't handle.
2. Failure handling: let the throw ride the existing `catch (\Throwable)` → `$transaction?->rollBack()` (`:584-587`)? **No** — the exception must carry the post-rollback current head. Pattern: throw a private sentinel or restructure minimally — recommended: catch nothing new; instead, when `$claimed !== 1`, perform `$transaction?->rollBack()` explicitly, re-read `$this->driver->read($entityTypeId, $id)` for the current `revision_id` (null row → null current), throw `RevisionConflictException`, and ensure the generic catch does not double-rollback (DBAL transactions tolerate a single rollBack — guard with a flag or rethrow path; keep the existing catch semantics for every other throwable byte-identical). Keep it surgical; document the flow inline.
3. Success path: nothing else changes — `driver->write()` (`:559`) re-asserts the same pointer value in the same transaction (harmless; contract §8 pins the shared transaction).
4. Every line added in T003/T004 must be behind `expectedRevisionId() !== null` (NFR-001 — re-grep the diff for unguarded additions).

**Validation**: T005/T006 suites; `composer phpstan`.

### T005 — Repository unit matrix + concurrency pin

**Files**: `packages/entity-storage/tests/Unit/EntityRepositoryOptimisticLockingTest.php` (NEW), `tests/Integration/Locking/ConcurrentSaveConflictTest.php` (NEW)

Unit (sqlite `DBALDatabase::createSqlite()` + real `SqlStorageDriver`/`RevisionableStorageDriver` — follow the existing `EntityRepositoryRevisionTest` fixture style in `packages/entity-storage/tests/Unit/`):
1. Match: expectation = head → save succeeds, head advances, result `SAVED_UPDATED`.
2. Mismatch: pre-check refusal — exception payload member-by-member; spy dispatcher proves NO events (PRE_SAVE, BeforeSaveEvent, POST_SAVE, AfterSaveEvent, REVISION_CREATED all absent); base row + revision table unchanged (no orphan revision).
3. Vanished row: delete behind the caller's back → `currentRevisionId === null`.
4. Rejection matrix: each clause of T003 → `\LogicException` with its message (assert distinct substrings); `expectationStated + valid` on a two-axis type fixture; driver-only (no DatabaseInterface) repository.
5. Validation-before-conflict: an invalid entity with a stale expectation throws `EntityValidationException`, not the conflict (contract §3).
6. `withExpectedRevisionId(null)` pass-through behaves as no expectation.

Integration (`#[CoversNothing]`, integration suite conventions):
7. **Deterministic interleave (contract §10)**: register a `BeforeSaveEvent` subscriber that, once, performs a competing save of the same entity stating the same expectation (the subscriber's save commits and moves the head — events fire after the outer pre-check, before the outer transaction). Outer save's claim → 0 affected → `RevisionConflictException`. Assert: exactly one new revision from the winner + the entity carries the winner's values; the loser's revision row rolled back (revision count proves it); the exception's `currentRevisionId` equals the winner's head.

**Validation**: `./vendor/bin/phpunit packages/entity-storage/tests/Unit/EntityRepositoryOptimisticLockingTest.php tests/Integration/Locking/ConcurrentSaveConflictTest.php --no-progress`.

### T006 — Invariance pins + gates

**Files**: `tests/Integration/Locking/NoExpectationInvarianceTest.php` (NEW)

1. **NFR-001 query-count pin**: wrap the sqlite `DBALDatabase` in a counting `DatabaseInterface` decorator (forward every method, increment on `query`/`select`/`insert`/`update`/`delete` execution — see `tests/Integration/DBAL`/`DatabaseInterfaceCompositionTest` for composition precedent). Pin: the per-save query count of a no-expectation update of a revisionable entity is IDENTICAL with the mission's code in place (record the count in the test as the contract — if the pre-mission count was Q, assert Q, with a comment explaining the pin).
2. **C-002 disjoint-merge pins**: (a) two writers load head R, save disjoint fields with NO expectations → both succeed, final entity carries both fields; (b) same with BOTH stating R → first succeeds (merged fields intact for its own write), second gets `RevisionConflictException`; after re-read + restate, the second's save merges cleanly onto the new head.
3. Gates:
   ```bash
   ./vendor/bin/phpunit packages/entity-storage/tests/ tests/Integration/Locking/ --no-progress
   composer phpstan
   composer cs-check
   bin/check-package-layers
   bin/check-dead-code
   ```
   No PHPStan baseline additions; no manifest edits in this WP.

## Edge cases & risks (from the plan premortem)

- **The claim and the full base write must share one transaction** — any "optimization" separating them reopens the race (contract §8). Reviewer checklist item.
- **Double-rollback** in T004 step 2 — exercise the conflict path under the unit suite to prove no DBAL "no active transaction" secondary exception masks the conflict.
- **`getRevisionId()` returns `?int`** — a revisionable entity loaded from a pre-backfill row can have a null head; expectation vs null head = mismatch (conflict, `current` null? No — the row EXISTS with a null pointer: pass `currentRevisionId: null` only for vanished rows; a null pointer head is a mismatch with current `null`... decide and pin: contract §5 says `currentRevisionId` null ⇔ row no longer exists, so a null-pointer head must surface as a conflict carrying the readable truth — implement as: row exists with null/0 pointer → conflict with `currentRevisionId: null` is WRONG per §5; instead read the raw base row pointer and pass `0`-as-null… **Resolution**: treat a persisted row with no revision pointer as unhonorable — it cannot match any valid expectation (≥1) — and throw the conflict with `currentRevisionId` = the raw pointer when > 0, else `null`, AND extend the exception docblock: "`null` = no readable head (row vanished or pre-backfill row with no revision pointer)". Update contract §5 wording accordingly in WP03 docs if the implementer lands this nuance — flag it in the review notes.
- **Community-scoped drivers**: the claim UPDATE deliberately omits the community condition (it is keyed on id + pointer; the pre-loaded original entity already passed scope). Comment it.
- **PHPStan level 5 on the nullable database**: clause-1 rejection guarantees `$this->database !== null` on every claim path — assert or annotate rather than re-checking.

## Definition of Done

- [ ] All six subtasks complete; `packages/entity-storage/tests/` + `tests/Integration/Locking/` green; full suite untouched elsewhere (SC-003 — zero modifications to existing tests).
- [ ] Contract `conflict-detection.md` clauses 1–17 each verifiably hold (reviewer walks the list).
- [ ] Exactly-one-winner pin green (NFR-002 / SC-004); no orphan revision rows on the losing side.
- [ ] Query-count pin green (NFR-001); disjoint-merge pins green (C-002).
- [ ] `composer phpstan`, `composer cs-check`, `bin/check-package-layers`, `bin/check-dead-code` clean; no changes outside `owned_files`.

## Reviewer guidance

- Diff `EntityRepository` and verify EVERY added line sits behind `expectedRevisionId() !== null` (NFR-001 is structural, not just pinned).
- Verify no interface file changed; `SqlStorageDriver` unchanged; no SaveContext threading into saveMany/rollback/translation paths (contract §13).
- Walk the conflict path: refused pre-check → assert zero event dispatches in the test's spy; claim failure → rollback proven by revision-table count.
- Check the exception against `PartialSaveException` shape (promoted readonly, `$errorCode`, deterministic message).
- Run the concurrency pin twice — it must be deterministic (no sleeps, no retries).

## Activity Log

- 2026-06-12T00:00:00Z – spec-kitty.tasks – created
- 2026-06-12T08:10:40Z – claude:fable-5:implementer:implementer – shell_pid=20768 – Assigned agent via action command
