# #2985 audit lane — search indexing and derived-store consistency

**Anchor:** #2985 (framework-wide competing-implementation audit)
**Lane:** search index write path, reindex recovery, derived-store agreement
**Pinned commit:** `280b160dd7bd2e3744cb68ea32b9d66c16d5331b`
**Branch / worktree:** `audit/2985-derived-store-consistency` — `fw-2985-derived-store-audit`
**Mode:** read-only. No runtime file modified; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim was re-verified
against source by the lane owner before being recorded. Findings are labelled
CONFIRMED (read in the code) or HYPOTHESIS (inference).

**All findings in this report are CODE EVIDENCE. Nothing was executed.** No
probe, no test, no reproduction. Where a consequence depends on timing or
concurrency it is stated as a structural property of the code, not as an
observed behaviour.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| `SearchIndexSubscriber` + registration | Yes | Full source read |
| `Fts5SearchIndexer` write/remove/removeAll | Yes | Full read of the cited methods |
| `Fts5SearchProvider` query path | Yes | Candidate window, stale detection, counts |
| `EntitySearchCandidateResolver` | Yes | Full read — the severity hinge |
| `search:reindex` handler + enumeration | Yes | Full read |
| Other derived stores (embeddings, media, messaging, workflows, audit, listing) | Yes | Per-listener read: events, registration, re-source discipline, failure semantics |
| Documented contracts + issue history | Yes | Specs, ADRs, prior audits, scoped issue search |
| Executed proof of any kind | **No** | Explicitly out of scope |
| MCP/AI-tools catalogue consumers | Partial | Identified, not audited |

## The documented contract

`docs/specs/content-publishing.md:204` is the only explicit promise found:
*"Listings/search/render-cache update via the existing POST_SAVE listeners
(best-effort, outside the write transaction — publish never blocks on
ingestion)."*

`packages/search/README.md` and `docs/specs/search.md:25-27` add that
`EntitySearchProjectionRegistry` is "the single resolution point used by all four
surfaces: `search:reindex`, the save/pointer-move lifecycle, lifecycle deletion,
and query-time candidate re-projection… index-time and query-time semantics
cannot drift apart."

`docs/specs/search.md:52` records that asynchronous indexing "still requires a
production queue consumer before a job is introduced"; the unused `SearchIndexJob`
was deleted rather than left publishing to an undrained queue.

**No document promises anything stronger.** There is no documented retry,
dirty-marker, reconciliation job, or staleness SLA for any derived store.
ADR-011 mentions search reindex and cache invalidation only as a DX consequence
("Audit logging, search reindex, cache invalidation become declarative"), not as
a consistency guarantee. *(reported by the contract lane; quotes verified.)*

## D-1 — CONFIRMED (serious): index-write failures are entirely silent

`SearchServiceProvider::boot()` constructs the subscriber with named arguments
that **omit `logger:`**:

```php
$subscriber = new SearchIndexSubscriber(
    $indexer,
    entityTypeManager: …,
    projectionRegistry: $this->projectionRegistry(),
);
```
(`packages/search/src/SearchServiceProvider.php:116-120`)

The constructor defaults `?LoggerInterface $logger = null` to
`$logger ?? new NullLogger()`
(`packages/search/src/EventSubscriber/SearchIndexSubscriber.php:60,66`).

Both failure handlers therefore write to nowhere:
- indexing failure — `$this->logger->error('Search indexing failed for a %s entity…')`
  (`SearchIndexSubscriber.php:155-157`)
- delete-removal failure — `$this->logger->error('Search index removal failed…')`
  (`:95-100`)

Both are `catch (\Throwable)`, so the exception is swallowed and the entity save
still succeeds. Net effect: an index write can fail and produce **no exception,
no log, and no durable record**. The content is saved and is simply never
searchable. A failed delete-removal leaves an orphan row just as silently.

This is exactly what open issue **#2763** documents — see the issue mapping. It
is not a new finding; this lane confirms it is still live at `280b160dd` and
supplies the precise call-site evidence.

## D-2 — CONFIRMED: the only recovery mechanism clears the index first, then repopulates unsafely

`search:reindex` (`packages/cli/src/Handler/SearchReindexHandler.php`) is the
sole repair path. There is no scheduled reconciliation, queue worker, or
consistency check — bounded to `packages/*/src/Schedule/`, `packages/queue/src`,
`packages/scheduler/src`, which a lane searched and found nothing. Two structural
properties:

**D-2.1 — it commits an empty index.** `Fts5SearchIndexer::removeAll()`
(`:172-193`) executes `DROP TABLE search_index` and empties `search_metadata`
inside a transaction that **commits**, then recreates empty schema. Each
subsequent `reindexBatch()` is its own separate transaction. From that commit
until the whole multi-entity-type loop finishes, queries see an empty or partial
index and return zero/undercounted hits — silently, not as an error.

The DROP is deliberate and defensible: the method's own comment explains "FTS5
tokenizers cannot be altered in place. A full reindex is therefore also the
upgrade boundary from the retired Porter schema", corroborated by
`docs/specs/search.md:70`. The cost is that there is no shadow table or atomic
swap, so the upgrade path and the repair path share one destructive mechanism.

**D-2.2 — it repopulates with offset pagination over an unordered set, unlocked.**
`SearchReindexHandler.php:75-78` pages via
`getQuery()->accessCheck(false)->range($offset, $batchSize)` and never calls
`sort()`. `SqlEntityQuery` emits `ORDER BY` only for explicitly requested sorts
(`packages/entity-storage/src/SqlEntityQuery.php:1371-1377`), applying `range()`
regardless. No lock, mutex, quiesce, or maintenance gate appears in the handler
or the indexer.

Stated precisely: **the dominant hazard is offset pagination over a mutating
set** — an entity removed earlier in the scan shifts later pages back by one and
a row is skipped entirely. That hazard is inherent to offset paging and is not
caused by the missing sort; the absent `ORDER BY` aggravates it by removing any
ordering guarantee between successive queries. This is a structural property of
the code; no skip was reproduced.

`accessCheck(false)` here is correct and documented — the search provider gates
reads, so building the index without a request account is right.

**Why the combination is worse than either half:** a skipped row is silently
unindexed, D-1 means the original failure was silent too, and D-3 means nothing
can detect a missing row afterwards. The remedy is another reindex, which
carries the same empty-index window and the same skip hazard.

## D-3 — CONFIRMED: the staleness signal cannot see a missing row

Each `search_metadata` row stores `schema_version`. At query time
`Fts5SearchProvider::search()` compares the stored value against
`$this->indexer->getSchemaVersion()` and, on mismatch, logs *"Search index
contains stale accessible documents. Run search:reindex to rebuild."*
(`packages/search/src/Fts5/Fts5SearchProvider.php:71,104-106,110-111`).

Three limits, all confirmed:
1. It detects **schema drift only**, not content drift. A row whose entity has
   since changed still matches the current schema version.
2. It only fires when a query actually **matches** the stale row. A stale row
   nobody searches for produces no signal.
3. It is structurally blind to a **missing** row — there is nothing to compare.

There is no dirty flag, watermark, last-indexed timestamp, or generation counter
recording that an index write failed or that content changed since last index.
Combined with D-1, an operator has no way to learn that a reindex is needed.

Content drift being undetected is largely benign because of D-5. A missing row
is not.

## D-4 — CONFIRMED: the candidate window is bounded, and orphans consume it

`Fts5SearchProvider` scans at most `MAX_CANDIDATE_SCAN = 1_000` rows plus a
truncation sentinel (`:31,67,74-82`), documented as giving "a stable pointer
snapshot: no OFFSET race across concurrent lifecycle writes, no quadratic
rescans, and no unbounded projection/access-check amplification" — a deliberate
and well-reasoned bound. On truncation it warns that "result totals and facets
are lower bounds."

`$nHits = count($safeMatches)` is computed from the **filtered** set, so counts
never overstate accessible content. The consequence worth naming: orphaned and
inaccessible rows consume the 1,000-row budget, so enough of them can push a
query into lower-bound totals and drop real results. HYPOTHESIS as to whether any
deployment reaches that threshold; the mechanism is confirmed.

## D-5 — CONFIRMED (favourable): stale and orphaned rows are not a disclosure risk

This is the severity hinge, and it cuts the opposite way from the cache lane.
`Fts5SearchProvider::search()` selects only `document_id`, `entity_type`,
`schema_version` from the index — never content — and resolves every row through
`EntitySearchCandidateResolver::resolve()`
(`packages/search/src/Access/EntitySearchCandidateResolver.php:37-112`), which:

1. re-reads the entity live — `getRepository(...)->find($entityId)`, returning
   `null` if it no longer exists (`:56-59`);
2. re-checks access live — `accessHandler->check($entity, 'view', $principal)->isAllowed()`
   (`:69`);
3. re-projects the document inside the requesting principal's field-read scope
   (`fieldReadScope->run(...)`, `:76-89`).

The index is a **candidate generator, not a content source**. A deleted,
unpublished, reclassified, or content-stale row is filtered or re-projected at
read time. The in-code comment states the intent: "so query-time semantics cannot
drift from index-time semantics. An unsupported entity fails closed."

Individual candidate failures are caught and the candidate omitted with a warning
(`Fts5SearchProvider.php:100-102`) — a failure cannot fail the whole query.

**Recorded as a disproved lead**: the hypothesis that a surviving index row for
deleted content is a disclosure risk does not hold on this code. It is a false
negative and wasted work, not a leak.

## D-6 — CONFIRMED: registration silently no-ops on a dispatcher type mismatch

`SearchServiceProvider` imports the **component**
`Symfony\Component\EventDispatcher\EventDispatcherInterface` (`:7`, the interface
carrying `addSubscriber`), resolves the **contracts** FQCN
(`\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class`), then
guards `if ($dispatcher instanceof EventDispatcherInterface)` with **no else and
no log** (`:123-126`).

**Weaker than it first appears, and recorded at its true strength.** The lane
owner initially flagged this as a live fragility. On verification the bound
dispatcher is `Waaseyaa\Foundation\Event\SymfonyEventDispatcherAdapter`, which
declares `implements EventDispatcherInterface, SymfonyComponentEventDispatcherInterface`
(`packages/foundation/src/Event/SymfonyEventDispatcherAdapter.php:24-27`), so the
check passes by construction, not by luck. The residual point is only that the
guard has no `else` and no log, in a subsystem where D-1 and D-3 mean a silent
skip would be undetectable. HYPOTHESIS that a contracts-only dispatcher is ever
bound — and there is affirmative evidence the project guards against exactly this
class of drift: `MediaServiceProvider`'s parking comment (D-10) notes its fix was
written so "a future dispatcher-key-serving change cannot silently re-activate
this again."

Recorded as a **latent robustness gap, not a live defect.**

## D-7 — CONFIRMED: a spec sentence describes a mechanism that does not fire on publish

`docs/specs/content-publishing.md:204` states that "listings/search/render-cache
update via the existing **POST_SAVE listeners**". A sibling lane established (and
this lane takes as given) that publish/revert/rollback dispatch only
`RevisionPointerMovedEvent` and `EntityEvents::REVISION_REVERTED` — not
`POST_SAVE`.

Search is nonetheless correct, because `SearchIndexSubscriber` subscribes to all
four events (`:69-77`) and re-sources from `repository->find()` — the behaviour
`docs/specs/content-workflow.md:280` describes. So the spec's *outcome* holds for
search while its *stated mechanism* is incomplete. For the render cache the same
sentence is simply wrong (sibling lane, #1861). Recorded because a reader
repairing one subsystem from that sentence would reach the wrong conclusion.

## D-8 — CONFIRMED (serious): listing-cache delete invalidation is unreachable in production

`ListingCacheInvalidator` subscribes to `AfterSaveEvent` **and** `AfterDeleteEvent`
(`packages/listing/src/ServiceProvider.php:166-183`). Only one of those two is
dispatched by the real write path.

Complete production dispatch census (`packages/*/src`, tests excluded):

| Event | Dispatched from |
|---|---|
| `AfterSaveEvent` | `EntityRepository.php:1424` — the live save path — and `CoordinatorLifecycleDispatcher.php:180` |
| `AfterDeleteEvent` | `migration/.../EntityDestination.php:401` (migration import only) and `CoordinatorLifecycleDispatcher.php:276` — **nowhere in `EntityRepository`** |

`EntityRepository::doDelete()` dispatches `EntityEvents::PRE_DELETE` and
`EntityEvents::POST_DELETE` only. And `EntityStorageCoordinator`, which owns
`CoordinatorLifecycleDispatcher`, is instantiated in **four test files and zero
production files** (`rg -ln "new EntityStorageCoordinator" packages` → all under
`packages/entity-storage/tests/Integration/`).

So `ListingCacheInvalidator::onAfterSave()` fires correctly and
`onAfterDelete()` never fires for an ordinary delete. One class, one derived
store, two handlers with the same intended semantics — and only one is reachable.

**Bound, same as C-1.3 in the cache lane:** this is *latent* in this repository
because `TaggedCacheInterface` has no binding here, so the listing pipeline
degrades to uncached operation by design (FR-058). It becomes live for any host
that binds a tagged cache — at which point deleting a listed entity leaves its
listing-cache tags un-invalidated with no path to clear them short of expiry.

## D-9 — CONFIRMED: `saveTranslation()` reaches no derived store at all

`EntityRepository::saveTranslation()` (`:2518`) dispatches exactly two things: a
pre-event before the peer-row upsert, and `EntityEvents::REVISION_CREATED` after
commit. It dispatches **no** `POST_SAVE`, **no** `AfterSaveEvent`, and **no**
pointer event.

Nothing subscribes to `REVISION_CREATED` in production — the only two matches
outside the dispatcher and the enum are doc comments
(`packages/audit/src/Listener/RollbackAuditListener.php:33`,
`packages/entity-storage/src/Event/RevisionPointerMovedEvent.php:26`).

Therefore content written through the standalone `saveTranslation()` peer-row path
is not seen by search, embeddings, the listing cache, the render cache, or either
audit trail. Ordinary saves are unaffected: `doSave()` dispatches `POST_SAVE`
unconditionally. Scope is the standalone translation-write path only.

## D-10 — CONFIRMED: two listeners are unguarded, and one of them runs first

CLAUDE.md's rule is that best-effort side effects wrap in try/catch and log.
Compliance is per-listener, with no framework enforcement:

- **Compliant:** `ThreadParticipantBootstrapSubscriber`, the three audit-package
  listeners, `ListingCacheInvalidator`, and `EntityEmbeddingListener` (partially —
  only the embed/store call is wrapped; its `find()` re-source and `delete()` are
  not).
- **Deliberately uncaught, documented:** `WorkflowRepublishListener` — its docblock
  states failure is "loud, never swallowed", so a failed promotion cannot leave a
  silently-orphaned tip. A reasoned exception, not an omission.
- **Uncaught with no stated rationale:** `EntityEmbeddingCleanupListener::onPostDelete`,
  and `EntityWriteAuditListener` — which contains **zero** `try`/`catch` tokens
  across all three handlers plus an unguarded `JSON_THROW_ON_ERROR` encode and raw
  `file_put_contents` in `EntityAuditLogger::append()`.

`EntityWriteAuditListener` is registered in `AbstractKernel::boot()` itself
(`packages/foundation/src/Kernel/AbstractKernel.php:222-228`) — **before**
provider discovery, so before every other listener in the table below. All these
listeners use default priority, and the adapter is a bare pass-through to stock
Symfony `EventDispatcher` with no per-listener isolation.

**HYPOTHESIS, flagged as reasoned inference and not executed:** if
`EntityWriteAuditListener` throws — a non-UTF8 field value reaching
`JSON_THROW_ON_ERROR`, or an unwritable audit path — then on that same `POST_SAVE`
dispatch the later listeners would not run, while the entity row is already
committed. `UnitOfWork` wraps each buffered dispatch and aggregates failures into
a `TransactionCompletionException` (`packages/entity-storage/src/UnitOfWork.php:84-126`),
so the row is not rolled back but the caller sees what looks like a failed write.
The registration order and the dispatcher mechanics are CONFIRMED; the
consequence is inferred, not reproduced.

## D-11 — CONFIRMED: three parallel event families for "an entity changed"

1. `Waaseyaa\Entity\Event\EntityEvents` — `PRE_SAVE`/`POST_SAVE`/`POST_DELETE`/
   `REVISION_REVERTED`. The workhorse; most consumers key off it.
2. `Waaseyaa\EntityStorage\Event\{Before,After}{Save,Delete}Event` — ADR-011's
   typed family. Dispatched **asymmetrically**: save yes, delete no (D-8).
3. `Waaseyaa\EntityStorage\Event\{RevisionPointerMovedEvent,BeforeRevisionPointerMoveEvent}`
   — pointer moves only.

With at least nine independently written consumer listeners across seven packages,
no shared base class, no shared safe-dispatch helper, and no shared policy on
whether to re-read from storage or trust the event payload. That divergence is
observable: `EntityEmbeddingListener` re-sources via `find()` and documents why,
while `EntityLifecycleAuditListener`, `EntityWriteAuditListener`, and
`ListingCacheInvalidator` all trust the in-memory just-written entity. Three of
them also independently reinvent the same `WeakMap` PRE_SAVE-capture /
POST_SAVE-consume trick to distinguish create from update, each citing #1856, two
wrapping it in try/catch and one not.

This is the enumerable competing-implementation finding for the lane.

## Comparative finding: derived stores have inconsistent maturity

The competing-implementation question for this lane resolves not as duplication
but as **uneven rigour across independently hand-rolled listeners**:

| Derived store | Pointer moves | Re-sources from storage | Failure signal |
|---|---|---|---|
| Search FTS | Yes (`:69-77`) | Yes (`find()`) | **None — NullLogger (D-1)** |
| Embeddings | Yes (`EventListenerRegistrar.php:147-176`) | Yes (`find()`, documented) | partial — embed/store wrapped, `find()`/`delete()` not |
| Audit (audit pkg) | Yes — but only via two *extra* purpose-built listeners | No, trusts payload | logged-and-continue |
| Audit (entity pkg, JSONL) | **No** | No, trusts payload | **unguarded (D-10)** |
| Render cache | **No** | n/a | logged (sibling lane) |
| Listing cache | **No** | No, trusts payload | best-effort, but delete handler unreachable (D-8) |
| Media versions | n/a | n/a | **deliberately parked (D-12)** |
| Messaging participants | n/a (non-revisionable) | n/a | logged-and-continue; no delete handler |

## D-12 — CONFIRMED: media cascade-delete is parked on purpose, and the retention is real

`MediaCascadeDeleteSubscriber` is not registered: `MediaServiceProvider::boot()`
carries an unconditional early `return;` before the wiring block
(`packages/media/src/MediaServiceProvider.php:104-118`). This is **a documented
decision, not an oversight** — the comment states that #1942 caused
`resolveOptional()` to start returning a real dispatcher and thereby
"LIVE-ACTIVATED" the subscriber "in every production kernel boot — an unintended
side effect… not a deliberate decision", that doing so "risks data-lossy cascade
deletes of media version blobs" ahead of the finish-or-park decision in **#1742**,
and closes: "Do NOT remove this early return without first reading #1742."

The consequence is nonetheless current: `media_version` rows for a deleted `Media`
entity are retained indefinitely. That is the accepted cost of parking, and it
belongs to #1742. Recorded here so it is not mistaken for an undiscovered gap.

Note the comment also documents the kernel-services dispatcher-FQCN hazard
directly, having been written so that "a future dispatcher-key-serving change
cannot silently re-activate this again" — corroborating D-6's class of risk while
showing the project already treats it as known.

Search is the reference implementation for *event coverage* and *read-side
safety*, and simultaneously the worst for *observability*. No shared abstraction
exists for "entity write → derived store must be told"; each listener is
hand-rolled. That is the enumerable duplication for the anchor.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#2763** | open, p2, `status:needs-design`, `program:architecture-integrity`, `release:beta-blocker` | **Canonical home for D-1 and D-3.** Already documents the `NullLogger` wiring, lazy FTS DDL on first post-save write, and that a projection failure "can therefore run DDL, fail, return the entity save as successful, leave search stale, and emit no operational signal." Its ratified disposition is "KEEP derived search…; REPLACE silent fire-and-forget lifecycle and wire the existing logger immediately", and its acceptance criteria already name "save, delete **and revision-pointer movement**". **Do not duplicate** — D-2's reindex findings are the part not yet covered there. |
| **#1861** | open, `needs-decision`, `status:needs-design` | Sibling cache lane's home; already carries a 2026-09-07 comment referencing that lane's checkpoint `2e64588eb` and states "Keep this issue as the tracking home; do not create a duplicate cache-staleness issue." D-7's cache half belongs there, not here. |
| **#1606** | open, `status:needs-design`, `release:beta-blocker` | ai-vector non-turnkey. Adjacent to the embeddings row of the maturity table. |
| **#1742** | referenced by the parking comment | Owns the finish-or-park decision for versioned-blob media; D-12's retained `media_version` rows belong there. |
| **#1856** | referenced by three listeners | Origin of the `WeakMap` batch-save isNew-capture pattern that D-11 finds reimplemented three times. |
| **#2122** | closed | Delivered a first-class quiesce primitive. Relevant to D-2: a quiesce mechanism exists and reindex does not use it. |
| **#2270**, **#2211**, **#2192**, **#2193**, **#2194**, **#2222** | closed | Origin of the projection registry, the FTS trust boundary, and the principal-safe read surface that D-5 confirms is working. |
| **#1920** | closed | Produced the pointer-move re-sourcing in search and embeddings (which took) and in the cache (which did not). |
| **#2985** | open | This lane's anchor. `docs/audits/FW-IMPLEMENTATION-AUTHORITY-2026-09/issue-coverage.md` already assigns #2763 and #1861 to its `entity-database-search-cache` lane. |

No new issue is filed. D-1/D-3 belong on #2763. D-2 (reindex clears first; unordered
offset repopulation; no quiesce) is **not** covered by #2763's current text and is
the one gap that may warrant either widening #2763 or a separate issue — an
anchor-owner decision, not this lane's.

## Compatibility constraints on any repair

- Wiring the kernel logger into `SearchIndexSubscriber` is additive and safe; the
  constructor parameter already exists and is optional. This is #2763's
  "immediately" item.
- Making reindex non-destructive requires either a shadow table with an atomic
  swap or an incremental mode. The DROP cannot simply be removed: it is the
  tokenizer-upgrade boundary (`Fts5SearchIndexer.php:178-181`,
  `docs/specs/search.md:70`). Any change must preserve a path that can replace
  the virtual table.
- Switching enumeration to keyset pagination requires a stable sort on a unique
  column; `SqlEntityQuery` supports `sort()` today, so this is additive.
- `SearchIndexerInterface` / `BatchSearchIndexerInterface` are extension points;
  check `packages/search/public-surface.php` before altering either signature.
- Any durable staleness marker is new persisted state and needs a migration.

## Focused acceptance criteria

1. An index-write failure produces an operator-visible signal — at minimum the
   kernel logger, per #2763.
2. A failure leaves a durable trace an operator can query, or reindex is made
   cheap and safe enough that "reindex on suspicion" is a real answer.
3. Search remains queryable throughout a reindex, or the destructive window is
   explicitly documented and gated behind quiesce (#2122).
4. Reindex enumeration is stable under concurrent writes — keyset pagination, a
   snapshot, or a quiesce — with a test that mutates the set mid-scan and asserts
   no row is skipped.
5. Reindex is resumable, or its non-resumability is documented; today the offset
   is a local loop variable and a killed process restarts from zero.
6. D-5's live re-resolution is preserved by any change — it is what makes stale
   rows safe, and a "trust the index" optimisation would convert every finding
   here into a disclosure risk.
7. For D-8: either `EntityRepository::doDelete()` dispatches `AfterDeleteEvent`
   symmetrically with `AfterSaveEvent`, or `ListingCacheInvalidator` subscribes to
   the event the delete path actually dispatches. A test must assert that deleting
   an entity invalidates its listing tags through the **production** repository
   path, not through `EntityStorageCoordinator` (which is test-only).
8. For D-9: an explicit decision on whether `saveTranslation()` should notify
   derived stores. If yes, it needs a dispatched event that existing subscribers
   receive; `REVISION_CREATED` alone is insufficient because nothing consumes it.
   If no, the exclusion should be documented, since `docs/specs/content-publishing.md:204`
   currently implies all writes reach these listeners.
9. Any repair should say whether the three event families in D-11 are converging
   or staying separate. Adding a fourth consumer to whichever family is convenient
   is what produced D-8.

## Residual work not covered

- `EntityEmbeddingListener`'s production registration is guarded by
  `class_exists(SqliteEmbeddingStorage::class)` (`HttpKernel.php:242-249`); this
  lane did not establish what happens on an install where that class is absent.
- Whether any production entity type's save routes exclusively through
  `EntityStorageCoordinator` — it is test-only today, so `CoordinatorLifecycleDispatcher`'s
  event family is effectively dead, but downstream consumers were not surveyed.
- Whether entity-type/bundle deregistration routes every row through
  `EntityRepository::delete()`; no automatic search-index cleanup path was found
  for deregistration, bounded to `packages/{search,entity-storage,entity,cli}/src`.
- Whether any deployment reaches the 1,000-candidate truncation threshold.
- The AI-tools MCP catalogue consumers of `SearchContentCatalogueInterface`.
- Whether `SearchReindexHandler`'s uncaught query/paging loop can abort mid-run
  for a repository whose `getQuery()` behaves differently — no such repository was
  found, so this stays HYPOTHESIS.
- No executed proof for any finding in this report.

## Recommended next disjoint lane

**Transaction and UnitOfWork boundaries.** Every lane so far has ended at the
same seam — post-commit notification dispatch, best-effort listeners, and the
`UnitOfWork` buffering rule — without auditing that machinery itself. This lane
sharpened the case: D-8 is an asymmetric dispatch inside `EntityRepository`, D-9
is a write path that dispatches nothing consumers hear, and D-10's
`TransactionCompletionException` behaviour means a listener fault can present as a
failed write for an already-committed row. Closed #2734 ("a repository mutation
nested in an outer transaction flushes post-commit effects early") is direct
evidence the seam has failed before, and open #2670 and #2733 sit on the same
surface. Disjoint from all eight lanes to date.
