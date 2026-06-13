---
affected_files: []
cycle_number: 2
mission_slug: optimistic-locking-01KTXCHY
reproduction_command:
reviewed_at: '2026-06-12T08:40:27Z'
reviewer_agent: unknown
verdict: rejected
wp_id: WP01
---

# WP01 review — cycle 1 (changes requested)

Reviewed commit `0856e6383` on `kitty/mission-optimistic-locking-01KTXCHY-lane-a`.

Overall: very strong work package. The two-stage mechanism, transaction sharing, double-rollback
protection, event-silence guarantee, rejection matrix, exception shape, concurrency pin,
query-count pin, and disjoint-merge pins all verified correct against the contract and by
running the gates (757 tests green, phpstan/cs-check/check-dead-code/check-package-layers
clean, Locking suite deterministic across two runs). One soundness gap blocks approval.

**Issue 1 (blocking, FR-004/FR-007/contract §7–§10): the guarded claim is silently skipped when
`revisionDriver === null` on a revisionable type.**

`EntityRepository::__construct()` takes `?RevisionableStorageDriver $revisionDriver = null`
(packages/entity-storage/src/EntityRepository.php:69). Construct a repository for a
*revisionable* entity type with a `DatabaseInterface` wired but **no revision driver**, state an
expectation, and walk `doSave()`:

1. The clause-1 rejection gate (`:453-483`) passes — the type IS revisionable, the database IS
   wired, the entity is not new, not translatable.
2. `shouldCreateRevision()` (`:1619-1646`) never consults `$this->revisionDriver`, so
   `$createRevision === true` → the `:544` non-revision-creating rejection does not fire.
3. The claim branch (`:604`) requires `$createRevision && $this->revisionDriver !== null` —
   false → `writeRevision()` and the guarded pointer-claim are both skipped. The save falls
   through to `driver->write()` and **succeeds with only the TOCTOU-unsafe fail-fast pre-check**.

In this wiring the head pointer never moves (no revision is ever written), so the pre-check
passes forever: two concurrent saves stating the same expectation BOTH succeed —
last-write-wins on the `_data` blob. That is precisely the silent downgrade FR-007 bans and
the both-succeed outcome FR-004/NFR-002/contract §10 exclude. Contract §7 promises stage (b)
for every honored expectation; in this configuration it silently never runs.

Reachability: kernel wiring always pairs revisionable types with a revision driver
(packages/foundation/src/Kernel/AbstractKernel.php:260-262), but manual repository construction
is a documented, supported pattern (tests and downstream apps construct `EntityRepository`
directly), so the configuration is publicly constructible.

**Fix (surgical, matches the repository's established idiom):** add a fifth clause to the
expectation rejection gate at the top of `doSave()` (alongside the `$this->database === null`
check):

```php
if ($this->revisionDriver === null) {
    throw new \LogicException(
        'Cannot state a revision expectation: revision driver not configured for entity type '
        . "'{$entityTypeId}' — no revision can be written and no guarded pointer claim exists.",
    );
}
```

This mirrors the existing `'Revision driver not configured for entity type …'` idiom
(`loadRevision()` `:815`, `rollback()` `:861`, `assertTwoAxis()` `:1459`), is behind
`expectedRevisionId() !== null` (NFR-001 unaffected), and guarantees the claim branch is
always reached when an expectation survives the gate. Add a unit test to the rejection-matrix
section of `EntityRepositoryOptimisticLockingTest` (revisionable type + database wired +
`revisionDriver: null` + expectation → `\LogicException` with a distinct substring, e.g.
`'revision driver not configured'`). Note for WP03: this adds a sixth row to the D6/contract
§11 rejection matrix — the docs WP should pick it up.

---

Non-blocking notes (no action required in WP01; carry into WP03/future):

- **Contract §5 wording** (flagged per the WP premortem instruction): the implementation
  correctly surfaces a persisted row with a null/0 revision pointer as a conflict with
  `currentRevisionId === null`, and the exception docblock documents "row vanished or
  pre-backfill row with no revision pointer". Contract §5 still says "null if and only if the
  base row no longer exists" — WP03 must update §5 to the docblock's wording.
- **`EntityStorageCoordinator::write()`** accepts a `?SaveContext` today and ignores
  `expectedRevisionId()` silently. It is not reachable from any production save path (the
  repository's coordinator slot is dormant until WP10; only tests call `write()` directly),
  and the contract scopes itself to `doSave()`, so this is out of WP01's scope — but when WP10
  activates coordinator fan-out it MUST adopt the conflict-detection matrix per contract §13's
  closing rule. Worth recording in WP03's docs.
- The withoutNewRevision deviation (rejecting `withoutNewRevision() + expectation` even though
  `$createRevision` would be true because `doSave()` ignores the flag for suppression) is
  agreed: it matches research D6 row 4 verbatim, and honoring the expectation there would have
  silently ignored the caller's suppression request — reject-don't-guess is right.

Verified during this review (for the record):
- Diff scope exactly matches owned_files; no interface changes; `SqlStorageDriver` untouched;
  no SaveContext threading into saveMany/rollback/translation paths; zero modifications to
  existing tests.
- Claim shares the transaction with the revision write and base write; 0-affected → full
  rollback proven by the `[1, 2]` revision-id pin; double-rollback prevented by nulling
  `$transaction` before the explicit `rollBack()` (generic catch no-ops via `?->`).
- Concurrency pin genuinely interleaves (BeforeSaveEvent subscriber save commits between outer
  pre-check and outer transaction) and is deterministic across two runs.
- NFR-001 is structural (every added query sits behind `expectedRevisionId() !== null`; the only
  unconditional addition is the accessor read) and pinned at the enumerated legacy count of 5.
- Gates: `phpunit packages/entity-storage/tests/ tests/Integration/Locking/
  tests/Integration/Provenance/ tests/Integration/Validation/` → 757 tests, 2084 assertions, OK;
  `composer phpstan` clean; `composer cs-check` clean; `bin/check-dead-code` clean;
  `bin/check-package-layers` clean.
