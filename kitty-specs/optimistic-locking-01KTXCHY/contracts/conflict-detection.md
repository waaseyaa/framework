# Contract: Storage-Level Conflict Detection

**Mission**: optimistic-locking-01KTXCHY | **Requirements**: FR-001..FR-004, FR-007, NFR-001, NFR-002, NFR-003, C-001, C-002

Applies to `EntityRepository::doSave()` (reached via `save()`), `SaveContext`, and `RevisionConflictException` in `packages/entity-storage`.

## Stating an expectation (FR-001)

1. **The seam is `SaveContext`**: `SaveContext::default()->withExpectedRevisionId(int $n)` states "I am updating the entity as of revision `n`". The builder returns a new instance (immutability preserved); `n < 1` throws `InvalidArgumentException`; `withExpectedRevisionId(null)` is the explicit no-expectation pass-through (equivalent to never calling the builder). Every other builder on the chain preserves the expectation, and `withExpectedRevisionId()` preserves every other field.
2. **Scope of honor**: an expectation is honored exactly when ALL hold — the entity is not new; the type is revisionable and NOT translatable (single-axis); the save is revision-creating (`shouldCreateRevision() === true`); a `DatabaseInterface` is wired. Anything else rejects per the matrix (clauses 11–13).
3. **Check order**: Mission 1 save-time validation runs first; the conflict check runs at write time, after validation. A save that is both invalid and conflicted reports the validation failure (`EntityValidationException`), not the conflict.

## Refusal semantics (FR-001, FR-002)

4. **Pre-write refusal**: when the loaded head's revision differs from the expectation (or the row no longer exists), the save throws `RevisionConflictException` BEFORE any write, before `preSave()`, and before ANY lifecycle event (`PRE_SAVE`, `BeforeSaveEvent` are not dispatched; `AfterSaveEvent`/`POST_SAVE` never fire for a refused save).
5. **Structured conflict**: `RevisionConflictException` carries `entityTypeId` (string), `entityId` (string), `expectedRevisionId` (int), `currentRevisionId` (?int — `null` if and only if the base row no longer exists), `errorCode === 'REVISION_CONFLICT'`. Content is deterministic: no timestamps, nothing timing-dependent beyond the revision ids (NFR-003).
6. **The entity object is not mutated** by a refused save: no revision id back-fill, no `enforceIsNew` flip, no `preSave`/`postSave` hooks.

## Atomicity — the race closure (FR-004, NFR-002)

7. **Two-stage check**: (a) fail-fast compare against the already-loaded original entity (clause 4); (b) authoritative guarded pointer-claim INSIDE the write transaction: after the new revision row is written and before the full base write, the repository executes `UPDATE <base> SET <revisionKey> = :new WHERE <idKey> = :id AND <revisionKey> = :expected` and reads the affected-row count.
8. **Claim outcomes**: affected `1` → the claim holds; the full base write proceeds in the SAME transaction (the claim and the full write MUST share one transaction — a refactor separating them reopens the race) and the save commits. Affected `0` → the entire transaction rolls back (the freshly written revision row included — no orphan revisions), the current head is re-read, and `RevisionConflictException` is thrown with that head as `currentRevisionId`.
9. **Unambiguous signal**: the claim's SET always changes the pointer value (the new revision id is freshly allocated and can never equal the expectation), so a 0-affected result means "predicate did not match" on every supported backend (SQLite, MySQL/InnoDB, Postgres) — never "matched but unchanged".
10. **Exactly one winner (NFR-002)**: of any set of concurrent saves stating the same expectation, at most one commits; every other receives `RevisionConflictException`. Pinned by `tests/Integration/Locking/ConcurrentSaveConflictTest.php` using a deterministic interleave: a `BeforeSaveEvent` subscriber performs a competing expectation-stated save of the same entity (events fire after the pre-check and before the transaction), so the outer save's pre-check passes, the inner save commits and moves the head, and the outer save's claim returns 0 → conflict. Exactly one winner, no threads, no sleeps.

## Explicit rejection of unsupported paths (FR-007)

11. **Stated-but-unhonorable expectations throw `\LogicException`** with distinct, greppable messages — never silently ignored, never silently downgraded to last-write-wins: new (unsaved) entity; non-revisionable type (no framework change marker exists — see research D2); two-axis (revisionable + translatable) type; non-revision-creating save (`withoutNewRevision()`, entity `setNewRevision(false)`, type `revisionDefault: false`); no `DatabaseInterface` wired.
12. **`\LogicException` vs `RevisionConflictException`**: rejections are caller programming errors (wrong path for the feature); conflicts are data races (right path, lost the race). Callers may rely on the type distinction.
13. **Paths that cannot carry an expectation**: `rollback()`, `setCurrentRevision()`, `setPublishedRevision()`, `saveTranslation()`/`saveTranslationRevision(s)()`, and `saveMany()` accept no `SaveContext` — an expectation is unstatable there, so the "silently ignored" failure mode is unreachable by construction. Any future SaveContext threading through these signatures MUST adopt this contract's matrix first.

## No-expectation invariance (FR-003, NFR-001, C-002)

14. **Byte-identical legacy behavior**: with no expectation stated (`expectedRevisionId() === null`, including the null pass-through and a null/default context), every branch this mission adds is skipped. Same write sequence, same events, same query count.
15. **Zero added queries (NFR-001)**: pinned by `tests/Integration/Locking/NoExpectationInvarianceTest.php` with a counting `DatabaseInterface` decorator — the per-save query count of a no-expectation update equals the pre-mission count.
16. **Disjoint-field merge preserved (C-002)**: two writers loading the same head and saving disjoint fields without expectations still merge cleanly (set-only-supplied-fields onto a fresh head); two writers WITH the same expectation on disjoint fields produce exactly one winner + one conflict (the success path of the winner still merges only its supplied fields). Both pinned in the same suite.
17. **No schema changes (C-001)**: the version column is the existing `revision_id` pointer; no migration, no new columns, no new tables.

## Verification

- Unit: `SaveContextExpectedRevisionTest` — builder validation, null pass-through, immutability, full re-threading matrix (every builder × the expectation field, both directions).
- Unit: `EntityRepositoryOptimisticLockingTest` — match proceeds; mismatch refuses pre-write with no events dispatched (spy dispatcher) and correct exception payload; vanished-row → `currentRevisionId === null`; the full rejection matrix (clause 11) message-by-message; validation-before-conflict ordering (clause 3).
- Integration: `ConcurrentSaveConflictTest` (clause 10 pin), `NoExpectationInvarianceTest` (clauses 14–16 pins).
