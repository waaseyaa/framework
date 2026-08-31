# Upgrade Notes — `EntityEvents::PRE_DELETE` Dispatch Timing (#2728)

**Introduced in:** the alpha train shipping #2728.
**Canonical doctrine:** [`../specs/entity-system.md`](../specs/entity-system.md) (Event dispatch semantics under `UnitOfWork`), [`../specs/relationship-modeling.md`](../specs/relationship-modeling.md) (Referential-Integrity Delete Guard).

---

## Summary

`EntityRepository::doDelete()` no longer hands `EntityEvents::PRE_DELETE` to the
`UnitOfWork` buffer. It is a **guard** event and now dispatches **immediately**,
inside the open delete transaction — exactly as `doSave()` has dispatched
`PRE_SAVE` and `BeforeSaveEvent` since the 2026-07-02 remediation.

`EntityEvents::POST_DELETE` is unchanged: it is a **notification** event, still
buffered by the `UnitOfWork` and dispatched only after a successful commit,
discarded entirely on rollback. Nothing in `UnitOfWork` changed.

No public signature was widened. Event timing is nevertheless observable, so the
compatibility impact is enumerated below.

### Correcting the premise

Prior documentation (in `EntityRepository`, `RelationshipDeleteGuardListener`,
`entity-system.md`, `relationship-modeling.md` and `ocap-audit-log.md`) said the
buffering was batch-only and that "only the single-`delete()` path is guarded".
That was wrong in **both** directions. `delete()` opens its own `UnitOfWork`
whenever a mutation authority and a database are wired — which is every
repository `EntityTypeManagerFactory` builds — so single deletes buffered
`PRE_DELETE` past the commit too. Pre-delete guards were ineffective on both
paths in every DBAL-backed production composition.

---

## Compatibility impact for subscribers

1. **`PRE_DELETE` now fires synchronously inside the delete transaction** on both
   `delete()` and `deleteMany()`. Subscribers see the row still present and the
   mutation-authority row tombstoned but uncommitted.
2. **A throwing `PRE_DELETE` subscriber now actually prevents the deletion.**
   Callers that compensated for the old post-commit semantics (for example by
   re-creating a row after catching the refusal) will find the row intact.
3. **A `PRE_DELETE` subscriber's own writes on the framework connection now join
   the delete transaction** and roll back with a refusal. Writes issued on a
   *second* connection do not, and may block on SQLite — such subscribers should
   move to `POST_DELETE`.
4. **`deleteMany()` interleaving changed** from `pre1, post1, pre2, post2` to
   `pre1, pre2, post1, post2`. Subscribers that pair PRE→POST per entity through
   an object-keyed map (as the audit listeners do, #1856) are unaffected; one
   that assumes PRE/POST adjacency is not.
5. **Intra-batch visibility.** Because a guard's queries run on the transaction's
   own connection, guard *N* observes entities *1..N-1* already removed within
   the uncommitted transaction. `deleteMany([$edge, $endpoint])` and
   `deleteMany([$endpoint, $edge])` can therefore now differ. This is a
   documented consequence of same-connection uncommitted reads; it is
   deliberately **not** ratified as a contract by a test, so a future
   order-insensitive guard is not a breaking change.
6. **A previously silent failure disappears.** `UnitOfWork`'s buffered-dispatch
   loop has no per-event `try`/`catch`, so a throwing *buffered* `PRE_DELETE`
   also suppressed that entity's `POST_DELETE` — cache/SSR invalidation, search
   de-indexing, `ai-vector` embedding cleanup and both audit listeners never
   observed deletes that *had* committed. Those listeners now fire correctly.
7. **`POST_DELETE`, `POST_SAVE`, `REVISION_CREATED` and `AfterSaveEvent`
   semantics are byte-identical.**
8. **`EntityRepository::__construct()` gains one new refusal.** A repository
   carrying a mutation authority with no database now throws `\LogicException`
   at construction (the symmetric partner of the pre-existing "a DBAL-backed
   repository requires the universal entity mutation authority" invariant). No
   in-repo construction site produces that shape; a downstream consumer that
   does now fails fast instead of tombstoning outside a transaction.

---

## Ordering decision (deliberate, pinned by test)

The mutation-authority **tombstone stays ahead of the guard dispatch**, mirroring
`doSave()` where `claim()` precedes `PRE_SAVE`. Consequence: a stale-mutation-token
delete is refused by the authority compare-and-swap *before any `PRE_DELETE`
listener runs* (`EntityMutationConflictException`, not a guard refusal). This is
safe because the tombstone is an `UPDATE` on the same Doctrine connection the
transaction is open on, so a guard refusal rolls it back. Pinned by
`EntityRepositoryAggregateConcurrencyTest::staleMutationTokenIsRefusedByTheAuthorityBeforeAnyPreDeleteListenerRuns`.

## Known hazards, documented rather than fixed

- **Cascading guards.** A `PRE_DELETE` listener that itself calls `save()` or
  `delete()` builds a *fresh* `UnitOfWork`, so its "commit" is only a savepoint
  release — yet it still runs its `afterCommit()` token installs and flushes its
  buffered POST events before the outer commit. If the outer transaction rolls
  back, those writes vanish while the notifications and in-memory tokens do not.
  This hazard exists identically on the save path today (`PRE_SAVE` has been
  immediate under `saveMany()` since 2026-07-02); this change only makes it
  reachable from a delete guard. No re-entrancy guard was added. Tracked in
  **#2734**.
- **`UnitOfWork`'s buffered-dispatch loop still has no per-event isolation**, so a
  throwing post-commit listener still suppresses every later buffered event. This
  change removes the only known `PRE_DELETE` trigger, not the mechanism. Tracked
  in **#2734**; a blanket `catch` there would swallow subscriber errors and is
  the wrong fix.

---

## Action required

### For `PRE_DELETE` *subscribers*

None in-framework. `RelationshipDeleteGuardListener` is the only production
`PRE_DELETE` subscriber and it is the intended beneficiary. Downstream
extensions that subscribe to `EntityEvents::PRE_DELETE` should check points 2,
3 and 4 above.

### For `delete()` *callers* — a behaviour reversal they must handle

This is the part with real first-party impact. Making the guard effective
converts "delete succeeds, edge silently orphaned" into "delete refused", and
`RelationshipDeleteGuardListener` signals that refusal with a bare
`\RuntimeException` (`RelationshipDeleteGuardListener.php:85`). Every caller
below currently catches only `EntityMutationConflictException`, so the guard
refusal propagates uncaught:

| Caller | Previous behaviour | Behaviour now |
|---|---|---|
| `packages/api/src/JsonApiController.php:1464` | `204 No Content` | uncaught `\RuntimeException` → **500**, not a clean `409`/`422` |
| `packages/graphql/src/Resolver/EntityResolver.php:381` | delete succeeds | uncaught `\RuntimeException` out of the resolver |
| `packages/ai-tools/src/Entity/EntityDeleteTool.php:100` | delete succeeds | uncaught `\RuntimeException` out of the tool call |
| `packages/field/src/Classification/Job/PurgeJob.php:168` | entity purged | entity **retained past its retention deadline** |

The retention case deserves explicit attention because it is
compliance-relevant. `PurgeJob`'s only `try`/`catch` is **per policy**
(`PurgeJob.php:73-88`, "one bad policy must not abort the whole sweep"), not per
entity. A single relationship-linked entity therefore aborts the remaining
entities *of that policy* and logs `classification.retention.purge_failed`; the
sweep continues with the next policy. Since
[`../specs/relationship-modeling.md`](../specs/relationship-modeling.md)
deliberately rejects cascade semantics, such an entity has no automatic
repoint-or-delete path: an operator must remove or repoint the edge before it
can ever be purged.

Deciding the correct refusal surface for these four callers — a typed exception,
a `409` mapping, and a retention story for permanently blocked entities — is
**out of scope for #2728**, which fixes only the dispatch timing. It is tracked
in **#2733**; this table is the disclosure, not the remedy.
