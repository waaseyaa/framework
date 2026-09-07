# #2985 audit lane — transaction and UnitOfWork boundaries

**Anchor:** #2985 (framework-wide competing-implementation audit)
**Lane:** commit timing, listener failure, rollback, retry
**Pinned commit:** `1b8362380ee681764aa93b490594f24b7abfd8e5`
**Branch / worktree:** `audit/2985-transaction-unitofwork` — `fw-2985-transaction-audit`
**Mode:** read-only. No runtime file modified; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim was re-verified
against source by the lane owner. Findings are CONFIRMED (read in the code) or
HYPOTHESIS (inference).

**All findings are CODE EVIDENCE. Nothing was executed by this lane.** Where the
repository's own test suite pins a behaviour, that is noted as *existing test
coverage* — it is not a claim that this lane ran it.

**The machinery is sound; its callers are not.** The transaction core is the
most carefully built subsystem audited so far, and the closed p0 this lane was
asked to distrust turns out to be genuinely fixed (T-1). But the guarantee it
provides is delivered to callers through a single exception type that almost
nobody handles, which produces two confirmed defects at the boundary (T-8, T-9).

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| `UnitOfWork` | Yes | Full source read |
| `DBALTransaction`, `TransactionCompletionCoordinator`, `TransactionCompletionInterface` | Yes | Full source read |
| `TransactionCompletionException`, `PartialSaveException` | Yes | Read + throw-site census |
| `EntityRepository` dispatch/branch sites | Yes | Guard/notification split, both fallback branches |
| Doctrine DBAL nesting semantics | Yes | Vendored 4.4.3 read directly |
| `DBALConsistentReadTransaction` | Yes | Read; confirmed outside the completion machinery |
| Listener failure semantics / caller handling | Yes | Registration-order census + caller catch-clause census |
| #2670 verification against source | **No** | Documented from the issue only |
| Executed proof | **No** | Out of scope |

## The documented contract

`docs/specs/entity-system.md:2664` states the rule as event **role**, not batch-ness:

> "GUARD events (`PRE_SAVE`, `BeforeSaveEvent`, `PRE_DELETE`) dispatch immediately
> inside the transaction so a refusal rolls the work back; NOTIFICATION events
> (`POST_SAVE`, `POST_DELETE`, `REVISION_CREATED`, `AfterSaveEvent`) and successor-token
> installs follow the outermost managed commit and are discarded on rollback."

`:1358` adds that a transaction implementation without `TransactionCompletionInterface`
"is refused before mutation because it cannot prove the notification boundary",
and `docs/specs/s1-concurrency-fencing.md:124-131` states the nesting rule:
"savepoint release does not publish successor tokens or POST/AFTER notifications,
an outer rollback discards them, and an outer commit drains them at connection
depth zero." *(reported by the contract lane; quotes verified.)*

Unusually for this audit program, **the code matches the spec.**

## T-1 — CONFIRMED: #2734 is genuinely fixed

This lane was asked to distrust the closed p0, because the program has twice
found a closed issue whose fix was not live (#1920's cache handlers landed in an
unregistered listener; #611 closed as done with the wiring absent). #2734 is not
such a case.

The fix is commit `4c0562470` ("fix(entity-storage): defer effects to outermost
commit (#2734) (#2803)"), verified an ancestor of the pinned HEAD via
`git merge-base --is-ancestor`. The mechanism, read directly:

- `TransactionCompletionCoordinator` keeps a stack of frames, one per managed
  transaction, shared per connection through a `WeakMap<Connection, …>`
  (`packages/database-legacy/src/DBALDatabase.php:20-27`).
- `committed()` pops the frame and, **when parent frames remain, pushes that
  frame's callbacks onto the parent instead of invoking them**
  (`TransactionCompletionCoordinator.php:50-55`). Callbacks run only when the
  stack empties (`:57-69`).
- `rolledBack()` pops the frame and discards its callbacks outright (`:72-76`),
  at any depth — including callbacks promoted upward from an inner commit.
- `begin()` refuses to open a managed frame when Doctrine's real nesting level
  diverges from managed depth (`:19-29`), so a raw unmanaged outer transaction
  cannot silently enclose a repository mutation.
- `UnitOfWork::transaction()` additionally rejects any transaction not
  implementing `TransactionCompletionInterface` (`UnitOfWork.php:62-69`), with
  the reason in the exception text: "so notifications cannot escape an outer
  rollback."
- The scheduler fence that made the original defect reachable was migrated off
  raw `Connection::transactional()` onto `DBALDatabase::transactional()`.

Existing repository test coverage pins it: `tests/Integration/Transaction/RepositoryOutermostCompletionTest.php`
is a real-composition proof across save/saveMany/delete/deleteMany × commit/rollback
× two schema layouts. *(This lane did not run it.)*

The second defect reported in the same issue — a drain loop with no per-event
isolation, where one throwing listener suppressed later buffered events — is also
fixed: each dispatch is individually wrapped (`UnitOfWork.php:98-105`).

**GitHub hygiene note:** #2734 is `CLOSED`/`COMPLETED` but still carries the label
`status:in-progress`. Reported as a stale label only; the code, the docs and the
closed state agree.

## T-2 — CONFIRMED: rollback cannot dispatch a post-write event

The highest-value question in the lane, because a post-write event on a
rolled-back write would corrupt every derived store that trusts it.

Three independent barriers, all read directly:

1. `UnitOfWork::transaction()`'s failure path calls `$transaction->rollBack()`
   then `reset()` and rethrows (`:77-82`). It never captures the buffers; only
   the success path (`:84-86`) hands them to the `afterCommit` closure. `reset()`
   clears `bufferedEvents` and `afterCommit` (`:167-172`).
2. `TransactionCompletionCoordinator::rolledBack()` discards the frame's
   callbacks with `array_pop` and invokes nothing (`:72-76`).
3. `DBALTransaction::rollBack()` routes to that coordinator method (`:33-42`).

No path was found — by this lane or by the two lanes that searched independently —
where a rollback dispatches a notification event.

## T-3 — CONFIRMED: the post-commit guarantee is enforced by a constructor invariant, not convention

`UnitOfWork::bufferEvent()` dispatches **immediately** when `inTransaction` is
false (`:141-148`), and `EntityRepository::save()`/`delete()` fall through to a
non-UnitOfWork path when `mutationAuthority === null || database === null`
(`:657-666`, `:740-751`). On that path `dispatchEvent()` receives no UnitOfWork
and dispatches directly (`:1522-1529`).

That configuration would reintroduce #2734's shape — an inner savepoint release
followed by immediate notification while an outer transaction is still open. **It
is closed by construction**, not left to discipline:

```php
if ($this->database instanceof DBALDatabase && $this->mutationAuthority === null) {
    throw new \LogicException('A DBAL-backed EntityRepository requires the universal entity mutation authority.');
}
```
(`EntityRepository.php:144-146`)

A DBAL-backed repository therefore cannot exist without mutation authority, and
`EntityTypeManagerFactory.php:133` passes `new EntityMutationAuthority($database, 'primary')`
unconditionally. A matching symmetric invariant (`:152-158`, added for #2728)
forbids authority-without-database, with the rationale stated in the comment: it
"makes delete()'s untransacted fallback provably tombstone-free."

The fallback is thus reachable only for a null or non-DBAL database — in-memory
test repositories. Recorded as **sound by design**; the residual is only that
`docs/specs/entity-system.md` does not state event-dispatch timing for the
unwired configuration (noted by the contract lane as a documentation gap).

Ordering on that fallback is correct anyway: `doSave()` commits its own
transaction at `:1384` before dispatching at `:1405`.

## T-4 — CONFIRMED: a caller can see an exception for a durably committed row

`TransactionCompletionException` (`packages/database-legacy/src/Exception/`) is
constructed inside `TransactionCompletionCoordinator::committed()` (`:67-69`),
which runs strictly **after** the real `$connection->commit()`
(`DBALTransaction.php:28` then `:30`). `UnitOfWork` then rethrows it without
rollback, with the reasoning in the code:

```php
} catch (TransactionCompletionException $failure) {
    // The database is committed. Never report a fictional rollback or
    // attempt to roll back a transaction that has already completed.
    throw $failure;
}
```
(`UnitOfWork.php:116-119`)

So `save()`/`delete()` can throw while the row is durable. This is deliberate and
well-reasoned — the alternative is a fictional rollback report — but the
consequence stands: **a caller that treats any exception as "the write failed"
will be wrong**, and the exception type is the only signal distinguishing the two
cases. Whether first-party callers make that distinction is the outstanding
lane's question (see Residual).

Note the adjacent generic branch: `catch (\Throwable) { $transaction->rollBack(); … }`
(`:120-123`) would call `rollBack()` on an already-completed transaction and hit
`RuntimeException('Transaction is no longer active.')` (`DBALTransaction.php:35-37`).
The `TransactionCompletionException` carve-out exists precisely to avoid that
path. HYPOTHESIS that any other post-commit throwable can reach the generic
branch; no such producer was identified.

## T-5 — CONFIRMED: nesting uses real savepoints, so partial rollback is supported

Recorded because the lane owner initially got this wrong and the repository
contains a comment making the same error.

The vendored Doctrine DBAL is **4.4.3**, where savepoint nesting is unconditional:
`beginTransaction()` calls `createSavepoint()` at nesting level > 1
(`vendor/doctrine/dbal/src/Connection.php:1062-1064`); `rollBack()` calls
`rollbackSavepoint()` and decrements (`:1152-1155`) rather than marking the
transaction rollback-only; and `setNestTransactionsWithSavepoints(false)` throws
`InvalidArgumentException` — "no longer supported" — on a setter marked
`@deprecated No replacement planned` (`:1000-1014`). No first-party code
configures it, and none can.

The lane owner's initial inference — that the absent
`setNestTransactionsWithSavepoints` call meant savepoints were off, hence no
partial rollback — was **wrong**, and rests on DBAL 2.x/3.x semantics.

The three layers line up exactly:

| Event | DBAL | Coordinator |
|---|---|---|
| inner `commit()` | releases savepoint | promotes callbacks to parent frame |
| inner `rollBack()` | rolls back to savepoint | pops frame, discards callbacks |
| outer `commit()` | real `COMMIT` | runs all promoted callbacks |

An inner failure is re-thrown, not swallowed (`UnitOfWork.php:77-82`). Whether the
outer transaction then rolls back is **caller discipline**, not an engine
guarantee — `DBALDatabase::transactional()` does roll back on any throwable, and
all shipped first-party outer callers use it. `assertTopFrame()` additionally
enforces LIFO completion ordering (`TransactionCompletionCoordinator.php:78-86`).

### T-5.1 — a stale in-repo comment asserts the opposite

`packages/foundation/src/Audit/StrictAuditLedgerInterface.php:33` still states
that "`DBALTransaction` has no savepoint support (so a nested domain rollback
would poison an enclosing audit write)." That predates the #2734 rebuild and is
false under DBAL 4.x. Documentation defect only — no code depends on it — but it
is exactly the claim that misled this lane's owner, so it is worth correcting.

## T-6 — CONFIRMED: the documented weaker contract governs a dormant path

`docs/specs/field-storage-backends.md:70-72` states that "storage remains
non-transactional across a multi-backend fan-out, and `PartialSaveException`
remains the reconciliation contract", and `docs/specs/entity-system.md:2595-2599`
adds that a failing backend "may have performed an internal write… no automatic
rollback is attempted."

That contract belongs to `EntityStorageCoordinator`, which is **instantiated in
four test files and zero production files** at this commit — verified by
`rg -n "new EntityStorageCoordinator" packages`, whose only hits are under
`packages/entity-storage/tests/Integration/`. The only `src/` references are
`EntityRepository`'s nullable constructor parameter defaulting to `null`
(`:116`), an accessor (`:332`), and the test-support factory in `src/Testing/`.
`PartialSaveException` is thrown only from `CoordinatorLifecycleDispatcher`
(`:157`, `:258`), which belongs to that coordinator.

So no production save can raise `PartialSaveException`, and the non-transactional
guarantee does not govern any live write. This resolves the ambiguity the
contract lane flagged — whether the coordinator is an alternate live path or a
parallel description — in favour of *dormant*.

Consequence worth naming: an author reading `field-storage-backends.md` would
plan reconciliation for a partial-save case that cannot occur, and might conclude
`AfterSaveEvent` carries no atomicity guarantee when on the live path it does.
Same family as the cache lane's spec-vs-runtime gap, but benign in direction — it
under-promises about the live path rather than over-promising.

## T-7 — CONFIRMED: no retry exists anywhere

No retry of a failed post-commit effect was found in `UnitOfWork`,
`TransactionCompletionCoordinator`, or the queue/scheduler packages. Failures are
collected, logged with bounded metadata, and surfaced once as
`TransactionCompletionException`. The only "retry" in the documented surface is
the migration save-advisory flow, a single bounded in-process retry of the same
candidate inside the same transaction — not transaction retry. Bounded to
`packages/{entity-storage,database-legacy,queue,scheduler}/src`.

Combined with the derived-store lane's finding that failures there are silently
swallowed, the framework-wide position is: **derived-store effects are
at-most-once, with no retry and no durable failure record.**

## T-8 — CONFIRMED (serious): a committed write can return HTTP 500

`TransactionCompletionException` is the sole signal distinguishing "the write
failed" from "the write succeeded, a side effect failed" (T-4). Exactly **four**
sites in the repository carve it out ahead of a generic handler — `UnitOfWork.php:116`,
`DBALDatabase.php:205`, `TransactionCompletionCoordinator.php:61`, and
`EntityRepository.php:3397`. All four are inside `entity-storage` and
`database-legacy`. **No caller in `packages/api`, `packages/cli`, or
`packages/publishing` catches it at all.**

`JsonApiController`'s create/update/destroy paths catch only specific domain
exceptions (`BundleUniqueKeyConflictException`, `UniqueConstraintViolationException`,
`EntityValidationException`, `SaveAdvisoryAcknowledgementRequiredException`,
`TransitionDeniedException`, `RevisionConflictException`,
`EntityMutationConflictException`). `TransactionCompletionException extends
\RuntimeException` and matches none of them, so it propagates to `HttpKernel`'s
terminal handler (`:118-132`) and becomes a generic **500 "An unexpected error
occurred."**

So an unguarded POST_SAVE listener throwing after a durable commit turns a
successful JSON:API write into a 500. The row is persisted; the client is told
the request failed. There is no 2xx-with-warning or 207 path.

Which listeners can trigger it is established in T-10.

## T-9 — CONFIRMED (serious): the publishing path destroys the signal and reports a misleading error

`DBALTransaction::commit()` sets `$this->active = false` (`:29`) **before**
calling `completionCoordinator->committed()` (`:30`) — and `committed()` is what
raises `TransactionCompletionException`. `rollBack()` throws
`RuntimeException('Transaction is no longer active.')` when `active` is false
(`:34-37`).

`IdempotencyStore::execute()` — which wraps `ContentPublisher::publish()` /
`updateDraft()`'s entity save in its own managed transaction — has a single
generic handler with no carve-out:

```php
    $transaction->commit();
    return $response;
} catch (\Throwable $exception) {
    $transaction->rollBack();
    throw $exception;
}
```
(`packages/publishing/src/Idempotency/IdempotencyStore.php:99-105`)

The sequence is therefore: the real `COMMIT` succeeds → `committed()` throws
`TransactionCompletionException` → the generic `catch` calls `rollBack()` on an
already-inactive transaction → that throws
`RuntimeException('Transaction is no longer active.')` → **`throw $exception;` is
never reached.**

Two consequences, both confirmed from the code: the original
`TransactionCompletionException` is **discarded and replaced** by a misleading
error, destroying the only signal that would tell a caller the row was committed;
and the handler's evident intent — roll back on failure — is unachievable, because
the transaction has already durably committed.

This is precisely the hazard the four core sites carve out against.
`IdempotencyStore` is the one non-core site that encloses an entity mutation in
its own managed transaction and lacks that carve-out.

`ContentPublisher.php:325-327` additionally documents the opposite of the actual
behaviour, stating that render/search/listing reaction happens "outside this
write, never blocking it". On the failure path it does block it: `publish()` and
`updateDraft()` throw when a POST_SAVE listener fails.

## T-10 — CONFIRMED: registration order decides which listeners are skipped

`UnitOfWork` wraps each buffered **event name** in its own try/catch
(`:98-105`), so one failing event name does not stop another. Within a single
`dispatch()` call, stock Symfony stops at the first throwing listener — and
`SymfonyEventDispatcherAdapter` is a bare pass-through with no per-listener
isolation. So the per-event isolation does **not** rescue same-event listeners.

Production `EntityEvents::POST_SAVE` registration order, with guard status:

| # | Listener | Registered at | Guarded? |
|---|---|---|---|
| 1 | `EntityWriteAuditListener` | `AbstractKernel.php:229` — before provider discovery | **No try/catch anywhere in the class** |
| 2 | `EntityLifecycleAuditListener` | `AuditServiceProvider.php:400` | Yes |
| 3 | `SearchIndexSubscriber` | `SearchServiceProvider.php:124` | Partial — the `find()` re-source runs before the `try` |
| 4 | `ThreadParticipantBootstrapSubscriber` | `MessagingServiceProvider.php:48` | Yes |
| 5 | `WorkflowRepublishListener` | `WorkflowServiceProvider.php:284-287` | **No** — deliberately, per its docblock |
| 6 | SSR render-cache closure | `SsrServiceProvider.php:198` via `HttpKernel:236-239` | **No** |
| 7 | `EntityEmbeddingListener` | `HttpKernel.php:244`, conditional on `class_exists` | Partial — same `find()` gap as search |
| 8 | Broadcast listener | `EventListenerRegistrar.php:44`, per-request | Yes |

`EntityWriteAuditListener` is registered **first**, before any provider, and is
unguarded. If it throws, every listener below it is skipped for that save — audit
logging, search indexing, messaging bootstrap, workflow republish, render-cache
invalidation and embedding indexing — and, via T-8, the caller receives a 500 for
a committed row.

HYPOTHESIS on the ordering *among* providers (rows 2-5): it follows
`$this->providers` iteration from manifest discovery, and no explicit sort was
found. Rows 1, 6, 7 and 8 are CONFIRMED by their fixed registration sites.

Two corrections to a naive listener inventory, both confirmed: `MediaVersionStorageDriver`
is not wired (`MediaServiceProvider::boot()` returns unconditionally, parked per
#1742), and `EntityCacheSubscriber` has no production call site — consistent with
the cache lane's finding and open #1861.

## Corrected and disproved leads

- **The savepoint error (T-5)** — the lane owner's own, corrected above.
- **Early flush on nesting** — the defect #2734 described is absent at this
  commit; do not carry it forward from the issue title.
- **`DBALConsistentReadTransaction` does not perturb commit ordering** — it
  implements only `TransactionInterface`, has no `afterCommit()`, and throws if
  started inside an active transaction (`:24-26`). It is a mutually exclusive
  read-only sibling, not a participant.
- **The `status:in-progress` label on closed #2734** is stale bookkeeping, not
  evidence of an incomplete fix.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#2734** | closed/COMPLETED (label `status:in-progress` stale) | **T-1.** Verified genuinely fixed by `4c0562470`, an ancestor of HEAD. No action beyond the label. |
| **#2733** | open, p1, `status:needs-design`, beta-blocker | **Verified accurate.** `RelationshipDeleteGuardListener:85-90` throws a bare `\RuntimeException`; `JsonApiController::destroy()` catches only `EntityMutationConflictException`, so a referential-integrity refusal becomes a generic 500. Distinct from T-8: here the write genuinely fails and rolls back — PRE_DELETE is a guard event, dispatched immediately, never wrapped in `TransactionCompletionException` — so the defect is exception *typing*, not commit ambiguity. T-8 is its post-commit sibling and the two should be repaired together. |
| **#2670** | open, `status:needs-design`, beta-blocker | Driver identity contract. Its own body states the divergence is "not reachable through `EntityRepository` today". Not independently verified here. |
| **#2819** | open, p1, beta-blocker | Transaction-joined audit records — the natural successor question to T-4 and T-7, and already assigned to the same inventory lane. |
| **#2996**, **#2997** | open | Filed from the derived-store lane; both instructed to coordinate with this audit before settling event timing. T-1/T-2/T-5 supply that: notifications follow the physical outermost commit and are discarded on rollback, so a repair may rely on that ordering. |
| **#2985** | open | Anchor. `docs/audits/FW-IMPLEMENTATION-AUTHORITY-2026-09/issue-coverage.md` assigns #2670, #2733 and #2819 to the `entity-database-search-cache` lane, marked "Unassigned / inventory-only-not-dispatched". |

**T-8 and T-9 have no tracking issue and warrant one** — jointly, since both are
the same root cause (the `TransactionCompletionException` contract is honoured
only inside the two core packages) and a repair should settle the caller contract
once. #2733 is the guard-side sibling and should be cross-referenced, not merged.

The remaining findings are favourable (T-1, T-2, T-3, T-5, T-10's isolation
mechanics) or documentation defects (T-5.1, T-6, the event-timing gap), which are
small enough for a docs sweep.

## Compatibility constraints on any repair

- `TransactionCompletionInterface` is load-bearing: `UnitOfWork` refuses any
  transaction lacking it. Any new transaction type must implement it or be
  excluded from mutation paths.
- The guard/notification split is decided solely by whether a call site passes
  `$unitOfWork` to `dispatchEvent()`. Adding a notification event means passing
  it; forgetting to is how an event ends up dispatching inside the transaction.
- LIFO completion order is enforced; a repair that closes frames out of order
  gets a `LogicException`.
- The constructor invariants in T-3 are what make the guarantee unconditional —
  relaxing either would reopen #2734's shape on the fallback path.

## Focused acceptance criteria

For T-8 / T-9:

1. A caller that receives `TransactionCompletionException` can distinguish a
   committed-with-failed-effect write from a failed write, and the JSON:API layer
   does not return 500 for a durable row. Whether the right answer is a 2xx with a
   warning, a 207, or a typed error is a design decision this lane does not make.
2. `IdempotencyStore::execute()` no longer calls `rollBack()` on an already-committed
   transaction; the original exception survives. A regression test should assert the
   exception type that escapes `ContentPublisher::publish()` when a POST_SAVE
   listener throws.
3. `ContentPublisher.php:325-327`'s "never blocking it" comment is corrected or made
   true.
4. An explicit decision on whether an unguarded first-registered listener may skip
   every later listener on the same event (T-10) — either guard
   `EntityWriteAuditListener`, or state that the skip is acceptable.

For the documentation items:

5. `StrictAuditLedgerInterface.php:33`'s savepoint claim is corrected (T-5.1).
6. `docs/specs/field-storage-backends.md` states that the multi-backend
   coordinator is not currently wired, so its non-transactional contract does not
   describe live behaviour (T-6).
7. `docs/specs/entity-system.md` states event-dispatch timing for the
   no-mutation-authority configuration, or states that the configuration is
   unreachable for DBAL-backed repositories per the constructor invariant (T-3).
8. `docs/upgrade-notes/pre-delete-event-timing.md`'s "documented rather than
   fixed" section header is corrected — its own body describes the fix.

## Residual work not covered

- #2670 was not verified against source; its own body states the divergence is
  not reachable through `EntityRepository` today.
- Whether any of the other transaction-opening sites outside the core packages
  (`AttachmentRepository`, `MigrationIdMap`, `CommunityTranslationPeerRepairer`,
  `Fts5SearchIndexer`, `ScheduleStateRepository`, and the schema installers)
  enclose a repository mutation and therefore share T-9's exposure. Only
  `IdempotencyStore` was traced to an entity save; the others open transactions
  for schema or infrastructure work that appears not to nest one, but this was
  not established individually.
- Inter-provider listener ordering (T-10, rows 2-5) is HYPOTHESIS.
- The registration-order consequence identified by the derived-store lane
  (an unguarded first-registered listener skipping later listeners on the same
  event name) is *not* rescued by `UnitOfWork`'s per-event isolation, which wraps
  each event **name**, not each listener. That interaction stays with the
  derived-store lane's D-10.
- No executed proof for any finding here.

## Recommended next disjoint lane

**Audit-ledger transactional joining (#2819).** It is the direct successor to
T-4 and T-7: this lane established that derived-store effects are at-most-once
with no retry and that a completion failure surfaces after a durable commit, and
#2819 asks precisely how an application mutation can join a *strict* audit
boundary under those semantics. It also owns the stale comment in T-5.1. Disjoint
from all nine lanes to date.
