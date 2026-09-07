# #2985 audit lane — cache invalidation and tag propagation

**Anchor:** #2985 (framework-wide competing-implementation audit)
**Lane:** cache invalidation, tag propagation, cache-key context completeness
**Pinned commit:** `529f1f0f5b8eb88ee1aacdfe03e7fb0c0a84f919` (current main at lane start)
**Branch / worktree:** `audit/2985-cache-invalidation` — `fw-2985-cache-audit`
**Mode:** read-only. No runtime file modified; no test added; no repair implemented.

## Method and evidence standard

Four research lanes traced the surface; every load-bearing claim below was
re-verified directly against source by the lane owner before being recorded.
Claims resting only on a research lane's report are marked *(reported)*.
Findings are labelled CONFIRMED (read in the code) or HYPOTHESIS (inference).
Two framings the lane owner formed early and then **disproved** are recorded in
"Corrected and disproved leads" rather than quietly dropped.

### Coverage matrix

| Area | Reviewed | Depth |
|---|---|---|
| `packages/cache` interfaces, backends, invalidator | Yes | Full source read |
| `packages/cache/src/Listener/*` (4 classes) | Yes | Full source read + registration census |
| Kernel wiring (`HttpKernel`, `EventListenerRegistrar`) | Yes | Full read of the invalidation path |
| SSR render cache (`RenderCache`, `SsrPageHandler`) | Yes | Key builder, tag builder, both call-site guards |
| Discovery + mcp_read caches | Partial | Wiring and guards read; payload tagging not traced |
| Listing pipeline cache | Yes | Invalidator, resolver context computation |
| Bespoke caches elsewhere in `packages/` | Yes | Enumerated; see C-5 |
| Entity lifecycle dispatch (`EntityRepository`) | Yes | Complete dispatch-site map |
| `docs/conventions/`, `docs/specs/`, ADRs, issue history | Yes | Contract vs. code |
| Every `AccessPolicyInterface` fast-path declaration | **No** | See "Residual" — belongs to an access lane |
| Runtime/empirical probes | **No** | Nothing executed; all findings are source-level |

## The intended contract

`docs/conventions/cache-tags-and-contexts.md` governs `TaggedCacheInterface`
specifically, and is ratified as stable surface by stability-charter §5.9 (Q&A
item 8, RESOLVED 2026-05-11) and ADR 015. Canonical tag vocabulary is exactly
three shapes — `entity:<type>`, `entity:<type>:<id>`,
`entity:<type>:<id>:<langcode>` — with a strict regex and "no silent
normalisation… valid tag in, or no tag at all". Canonical contexts are
`user.roles`, `user.id`, `language.content`, `language.interface`, and the
`url.query.` prefix family. Invalidation listeners are specified as best-effort:
"failures log via `LoggerInterface` at warning level and never raise."
*(reported by the contract lane; quotes verified against the doc.)*

`docs/specs/infrastructure.md` separately documents an older architecture around
`TagAwareCacheInterface`, `CacheTagsInvalidator`, and a listener table naming
`EntityCacheInvalidator` / `ConfigCacheInvalidator` / `TranslationCacheInvalidator`
as active. Neither document cross-references the other.

## C-1 — CONFIRMED (serious): a revision pointer move invalidates no cache entry

**Publishing, reverting, or rolling back a revision leaves stale content served
to anonymous visitors.**

Complete dispatch-site map of `packages/entity-storage/src/EntityRepository.php`:

| Path | Events dispatched |
|---|---|
| `doSave` (`:1407`) | `POST_SAVE` |
| `clearPublishedRevision` (`:2058`) | `POST_SAVE` |
| `rollback` (`:1743`) | `REVISION_REVERTED`, `REVISION_CREATED` |
| `setCurrentRevision` (`:1883-1895`) | `REVISION_REVERTED`, `RevisionPointerMovedEvent` |
| `doSetPublishedRevision` (`:2220-2255`) | `REVISION_REVERTED`, `RevisionPointerMovedEvent` |

Every cache listener that is actually registered subscribes to `POST_SAVE` and
`POST_DELETE` only:

- SSR render cache — `packages/ssr/src/SsrServiceProvider.php:162-200`, wired at
  `HttpKernel.php:240`.
- Discovery cache and mcp_read cache —
  `EventListenerRegistrar.php:102-142`, wired at `HttpKernel.php:242-243`.

Nothing under `packages/ssr/src` subscribes to `RevisionPointerMovedEvent` at
all; the only kernel subscriber to that event is the ai-vector embedding
listener (`EventListenerRegistrar.php:172-176`). So a pointer move reaches no
cache bin.

The asymmetry is the tell: **un**publishing (`clearPublishedRevision`) dispatches
`POST_SAVE` and does invalidate. Publishing does not.

### C-1.1 — the fix for this landed in a listener nothing registers

CW-v1 WP-2 task 2.5 (#1920) added `onPointerMoved()` and `onRevisionReverted()`
to `Waaseyaa\Cache\Listener\EntityCacheInvalidator`, with an explicit rationale
in the class docblock: *"before this, revision POINTER moves … invalidated NO
cache tags at all, so a published/reverted view could keep serving stale cached
content."* The CHANGELOG records the gap as closed.

`EntityCacheSubscriber` — the registrar that binds those handlers — is never
called in production. Verified directly: no file under `packages/*/src` or
`public/` constructs or registers `EntityCacheInvalidator` or
`EntityCacheSubscriber`. The four matches outside `packages/cache/src/Listener/`
are **doc comments citing the pattern**, in `packages/ai-vector`,
`packages/search`, `packages/workflows`, and `EventListenerRegistrar.php:170`.
`CacheServiceProvider::register()` is an empty body. `CacheTagsInvalidator` — the
sole implementation of the interface all four listeners require — is never
constructed, and `registerBin(` occurs exactly once in the repository: its own
definition.

What makes this decisive rather than circumstantial: **the same CW-v1 task wired
pointer-move handling into two other derived stores, and both took.**
`SearchIndexSubscriber` subscribes to `POST_SAVE`, `RevisionPointerMovedEvent`
and `REVISION_REVERTED` (`packages/search/src/EventSubscriber/SearchIndexSubscriber.php:72-75`)
and **is** registered (`SearchServiceProvider.php:116`); its docblock says it
"mirrors" the cache subscriber's pattern. The ai-vector embedding listener got
the same treatment and is registered at `EventListenerRegistrar.php:172-176`.
Search and embeddings are correct; the cache is not, because its half of the
change went into an unregistered class.

### C-1.2 — bounds, stated so the finding is not over-read

- The `render` bin is wired unconditionally at boot (`HttpKernel.php:186-215`).
- Staleness is capped by the render TTL: default **300s**
  (`packages/routing/src/CacheConfigResolver.php:32`;
  `config/waaseyaa.php:148`, `WAASEYAA_SSR_CACHE_MAX_AGE`). A raised TTL scales
  the exposure proportionally.
- Editorial flows that call `save()` alongside the pointer move are unaffected.
  The CW-v1 comment scopes the problem to "a standalone pointer move
  (rollback/revert/promote with no accompanying `save()`)". This lane did **not**
  enumerate which editorial flows are standalone.
- **No incident evidence.** This is a source-level finding; nothing was executed.
- **Not an authorization failure** — see D-2.

### C-1.3 — the same gap is latent in the listing cache

`ListingCacheInvalidator` subscribes only to `AfterSaveEvent`/`AfterDeleteEvent`
(`packages/listing/src/ListingCacheInvalidator.php:64,74`) — no pointer-move
handling. It is latent rather than live here because `TaggedCacheInterface` has
no binding in this repository, so the listing pipeline degrades to uncached
operation by design (FR-058; `packages/listing/src/ServiceProvider.php:352-357`).
A host that binds a tagged cache would activate C-1 for listings too.

## C-2 — CONFIRMED: a complete invalidation abstraction with zero production callers

`CacheTagsInvalidator` + `CacheTagsInvalidatorInterface` +
`Listener/{EntityCacheInvalidator,ConfigCacheInvalidator,TranslationCacheInvalidator,EntityCacheSubscriber}`
form a tested, documented, `@api`-marked mechanism that nothing wires. In its
place, three independently hand-written closures do the same job:

| Live implementation | Tag scheme | Location |
|---|---|---|
| render cache | `render`, `render:entity:<type>[:<id>]` | `SsrServiceProvider.php:162-200` |
| discovery cache | `discovery:entity:<type>[:<id>]` + surface tags | `EventListenerRegistrar.php:102-142` |
| mcp_read cache | `mcp_read:entity:<type>[:<id>]` | same method |

Consequence worth naming: `RenderCache::buildTags()` stamps **five** tags
including the bare `entity:<type>` and `entity:<type>:<id>`
(`packages/ssr/src/RenderCache.php:109-118`), but `RenderCache::invalidateEntity()`
invalidates only `render:entity:…` (`:88-99`). The bare `entity:` tags are
write-only — the mechanism that would consume them is the unwired one. Every
render-cache entry carries dead tag data.

`ConfigEvents::POST_SAVE` is likewise dispatched
(`packages/config/src/EventAwareStorage.php:48-51`) with no production receiver;
`TranslationCacheInvalidator` has no event-shaped method at all and is wired to
nothing. *(reported by the listener lane; registration census independently
re-verified by the lane owner.)*

## C-3 — CONFIRMED: two unrelated classes named `ConfigCacheInvalidator`

`Waaseyaa\Cache\Listener\ConfigCacheInvalidator` (tag invalidation) and
`Waaseyaa\Config\Listener\ConfigCacheInvalidator` (`unlink()`s a file cache path).
Different namespaces, different mechanisms, neither registered in production.
Git history places the config one in early pre-rename scaffolding. *(reported.)*

## C-4 — CONFIRMED: unguarded invalidation in a live listener

`EventListenerRegistrar::registerEntityCacheInvalidationListeners` wraps its
invalidation in `try { … } catch (\Throwable) { $logger->warning(…) }`
(`:109-134`) — the CLAUDE.md best-effort shape. The **live** SSR render-cache
closure (`SsrServiceProvider.php:170-193`) has no try/catch at all. Because
`POST_SAVE` is a post-commit notification (see C-6), an exception thrown there
propagates out of the dispatch *after* the row is durably written — the save
would surface a failure to the caller despite having succeeded.
**HYPOTHESIS** that this has occurred: not traced to any incident. The three
unregistered `packages/cache` listeners share the same unguarded shape, but are
moot while unwired.

## C-5 — CONFIRMED: two hand-copied PHP-file-cache implementations

`PackageManifestCompiler::compileAndCache()/stampKnownMissing()`
(`packages/foundation/src/Discovery/PackageManifestCompiler.php:400-414,558-575`)
and `ConfigCacheCompiler::compileAndCache()`
(`packages/config/src/Cache/ConfigCacheCompiler.php:47-73`) are structurally
identical — mkdir, `var_export` payload, write to `path.tmp.<pid>`, `rename()`,
cleanup on failure — written twice with no shared helper. Each has its own
corruption-recovery and its own staleness scheme (content fingerprint vs.
generation/activation-sequence metadata). Not a correctness bug; precisely the
duplication #2985 exists to enumerate. Other atomic-write call sites in
`entity`, `foundation`, and `config` use the same idiom but write sources of
truth, not derived caches, and are excluded from this count. *(reported.)*

## C-6 — CONFIRMED: dispatch is post-commit; the prompted race does not exist there

Both entity and config dispatch happen **after** the write commits — entity at
`EntityRepository.php:1384` (commit) then `:1407` (dispatch), with NOTIFICATION
events explicitly buffered until after a successful commit and discarded on
rollback (`:1499-1519`); config at `EventAwareStorage.php:44-51`, gated on a
successful write. The "invalidate before commit, concurrent reader repopulates
from stale state" window this lane went looking for is **not** present at these
dispatch sites, and is recorded as disproved.

A different, genuine cache-aside race remains reachable in principle: a reader
already holding pre-write data can `set()` after the invalidation `UPDATE` lands,
and nothing sequences the two (no optimistic-concurrency guard on `set()`).
`DatabaseBackend::invalidateByTags()` is itself a single parameterized `UPDATE`
(`packages/cache/src/Backend/DatabaseBackend.php:196-239`), so no invalidation is
lost mid-flight. **HYPOTHESIS**, source-level only; not probed. *(reported.)*

## C-7 — noted: an unconfigured bin silently gets process-local caching

`HttpKernel::finalizeBoot()` explicitly overrides `render`, `discovery`, and
`mcp_read` with `DatabaseBackend`. Any other bin name falls through to
`CacheFactory`'s default of `MemoryBackend`
(`packages/cache/src/CacheFactory.php:19-28`,
`CacheConfiguration.php:38`) — per-process, no cross-request persistence under
PHP-FPM. Nothing warns. *(reported.)*

## Corrected and disproved leads

- **D-1 — the two tag interfaces are not accidental duplication.** The lane owner
  initially inferred from git history (`TagAwareCacheInterface` from the original
  cache implementation, untouched since the Aurora→Waaseyaa rename;
  `TaggedCacheInterface` introduced later by the listing-pipeline mission) that
  these were unreconciled rivals. **Wrong on intent**: `TaggedCacheInterface.php:16-24`
  documents the split deliberately — different shape, different vocabulary,
  different consumer. Method sets genuinely differ
  (`invalidateByTags(array): void` vs `setWithTags`/`invalidateByTag(string): int`/
  `getTagsFor`), and `DatabaseBackend` implements only the former. Both are
  declared `public` in `packages/cache/public-surface.php:22-23` — verified at the
  package-local authority, not the derived map. What remains true is narrower:
  no document cross-references the other, and their declared purposes read as
  near-synonyms.
- **D-2 — no cross-user cache leak.** `RenderCache::buildKey()` carries no account
  dimension (`render:<schema>:<type>:<id>:<viewMode>:<langcode>`), which looks
  alarming in isolation. Both the read and the write are gated on
  `!$account->isAuthenticated()` (`SsrPageHandler.php:379-385`, `:427-434`), and
  `DiscoveryApiHandler.php:107` short-circuits identically. The cache's domain
  contains exactly one principal, so the absent dimension is correct by
  construction. Recorded as **fragile but correct**: the invariant lives at the
  call site, not in the key, so a future caller omitting the guard would convert
  this into a real leak with no signal from the key builder.
- **D-3 — `ProtectedCacheDimensions` is not a silent gap.** It has no production
  constructor, but `docs/specs/entity-field-read-boundary.md:265-279` documents it
  as WP4 scaffolding — "hard rejection remains WP4". Acknowledged future phase,
  not abandoned safety machinery. Absence of callers is reported as exactly that.
- **D-4 — `EntityCacheSubscriber` and `EntityCacheInvalidator` are not competing
  authorities.** The former is the latter's registrar; it holds no invalidation
  logic. The lane owner's initial "two entity listeners" framing was wrong.

## Existing issue mapping

| Issue | State | Relation |
|---|---|---|
| **#1861** | open, `needs-decision`, `status:needs-design`, milestone "Decisions & Accepted Risks" | **Directly this lane's C-1.1/C-2.** Filed 2026-07-02 as "orphaned entity-cache invalidation helper". Still accurate at `529f1f0f5`, and materially more serious than when filed: eight days after it was opened, CW-v1 (#1920, 2026-07-10) added a real staleness fix *into* the orphaned class, so the issue is no longer only about dead code — it now carries an inert bug fix. **Do not re-file; update #1861.** |
| **#1920** | closed | Delivered the pointer-move cache fix into the unregistered listener. Its CHANGELOG claim that the gap is closed does not hold for caches (it does hold for search and embeddings). |
| **#2064** | closed | WP4 persistent-worker review that deliberately admitted the entity-layout and JSON:API structural route caches as reviewed exclusions — evidence that those two are intentional, not findings. |
| **#611** | closed | "auto-wire EntityCacheInvalidator in cache service provider" — closed as done, yet the wiring is absent at this commit. Fixed-then-regressed, or never load-bearing; worth a line in #1861. *(reported; the absence is independently confirmed, the history is not.)* |
| **#2167**, **#2150**, **#2778**, **#2734**, **#830** | closed | Adjacent cache history (query-context binding, Cache-Control headers, manifest cache, post-commit effects, registrar layering). Background only. |
| **#2985** | open | This lane's anchor. |

No new issue is filed by this lane. C-1 belongs on **#1861**, whose scope already
covers the orphaned listener; the recommendation is to re-scope and re-prioritise
that issue rather than open a duplicate. C-5 and C-2's duplication are audit
observations for the anchor, not defects.

## Compatibility constraints on any repair

- Both `TagAwareCacheInterface` and `TaggedCacheInterface` are declared `public`
  in `packages/cache/public-surface.php`. Neither may be silently removed;
  charter §4's deprecation cycle applies to either, and §5.9 additionally forbids
  silent removal of `setWithTags`/`invalidateByTag`/`getTagsFor` or changes to the
  tag regex.
- Wiring the dormant `CacheTagsInvalidator` path is not a drop-in: it would put a
  second invalidation authority alongside three live closures, and
  `RenderCache`'s bare `entity:` tags would suddenly become load-bearing. Whether
  to converge on the dormant abstraction or delete it in favour of the live
  closures is a design decision, not a bug fix.
- Adding pointer-move subscriptions to the live listeners is the narrower change
  and does not disturb the public surface. It must cover `RevisionPointerMovedEvent`
  **and** `EntityEvents::REVISION_REVERTED`, since `rollback()` dispatches only
  the latter (`EntityRepository.php:1743`).
- Listener callbacks must not raise: `POST_SAVE` fires post-commit, so an
  exception surfaces a failure for an already-durable write (C-4).

## Focused acceptance criteria

1. A publish (`setPublishedRevision`), a revert (`setCurrentRevision`), and a
   `rollback()` each invalidate the render cache for the affected entity — three
   separate cases, since they dispatch different event combinations.
2. A regression proving an anonymous SSR request after a publish returns the new
   render, not the cached previous one, without waiting out the TTL.
3. Unpublish continues to invalidate (it works today via `POST_SAVE`; do not
   regress it).
4. Invalidation failure is logged and does not propagate, matching
   `EventListenerRegistrar`'s existing shape.
5. An explicit disposition for the dormant `CacheTagsInvalidator` mechanism:
   wired, or deprecated on the documented cycle. Leaving it inert is what allowed
   C-1.1.
6. If listing gains a bound tagged cache, C-1.3 is covered by the same tests.

## Residual work not covered

- Every `AccessPolicyInterface` implementor's `SUPPORTS_LISTING_FAST_PATH`
  declaration. `ListingResolver::computeCacheContexts()` (`:189-203`) force-adds
  `user.id`/`user.roles` **unless** a policy opts into the fast path, so a
  misdeclared policy would drop those dimensions from the listing cache key. This
  lane found no misdeclared policy but did not audit them; it belongs to an access
  lane. Note also that the auto-added dimensions are role and account id only —
  a classification/clearance dimension is not among them.
- Discovery/mcp_read payload tagging symmetry (`set()`-side tags vs. what the
  listeners invalidate).
- Whether the `mcp_read` bin has any reader in this repository.
- `SqlEntityQueryResultCache`: account-discriminated by design
  (`SqlEntityQuery.php:1064-1096`, C-010 note) but constructed with a `null`
  cache at its only production call site (`EntityRepository.php:580`, third
  positional argument — verified). Half-landed or deliberately dormant is a
  question for the entity-storage owner; absence of wiring is not evidence of
  removability.
- `ApiCacheMiddleware`: no production instantiation found. Stateless, so no
  staleness risk regardless. *(reported.)*
- No empirical probe was run for any finding in this report.

## Recommended next disjoint lane

**Search indexing and derived-store consistency.** This lane established that
`SearchIndexSubscriber` is a fourth independent "entity write → derived store"
listener that got pointer-move handling right where the cache did not — which
makes it the natural control for asking whether the *other* derived stores
(embeddings, listing, FTS) agree on what a write means, and whether reindexing
recovers from a missed event. It is disjoint from all seven lanes run so far.
