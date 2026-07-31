# Listing Pipeline v1 — Views-equivalent surface for entity listings

<!-- Spec reviewed 2026-07-26 — bundle-attached fields in the query plan (downstream defect, rhtcircle). `ListingResolver::buildQueryPlan()` previously pushed EVERY scalar EQ filter (and every sort field) into the base storage `findBy()` criteria/order-by. Bundle-attached fields (FieldDefinitionRegistry `registerBundleFields()`) are persisted in per-bundle subtables that `findBy()` never joins; the pushed entry degrades to `json_extract(_data, ...)`, which can never match the partitioned value → bundle-filtered listings returned zero rows. The resolver now takes an optional `?FieldDefinitionRegistryInterface` (wired from the kernel bus by `Listing\ServiceProvider`) and demotes filters/sorts on bundle-attached fields (`bundlesDefiningField()`; bundle-scoped listings check their own bundle, unscoped listings demote on any bundle) to the in-PHP paths: filters join `$remaining` (refined via `matchesAll()` over hydrated rows — hydration merges subtable values back), a demoted sort re-applies the COMPLETE sort chain in-PHP post-fetch (stable usort + FR-014 id tie-break), and `canPushPagination()` additionally requires no deferred sorts so FR-031 totals stay refined. Base-table fields (real columns or `_data` blob) keep the native pushdown. No registry wired → legacy all-native plan. Acceptance: BundleFieldQueryPlanningTest. -->
<!-- Spec reviewed 2026-06-22 — WP07 (alpha245 security, audit #28): FR-024 corrected. The resolver previously bound `user.roles` into the cache contexts only when `$accessOps` was non-default, but the DEFAULT `view` path also runs the per-row access gate (unless a policy opts into the FR-032 fast path), producing an account-dependent result that was then cached under a key shared by every user → cross-user role/owner data leak. `ListingResolver::computeCacheContexts()` now binds BOTH `user.id` and `user.roles` whenever the per-row gate runs (i.e. `!canUseAccessFastPath()`), keying the cache per acting account. `ListingDefinition::effectiveContexts()` is unchanged (it remains a pure function of the definition; the user-context decision needs gate/fast-path knowledge the definition lacks). Fast-path listings (SUPPORTS_LISTING_FAST_PATH) remain user-independent and shareable. Acceptance: CacheIntegrationTest::defaultViewCacheKeyIsPerUserWhenAccessGateRuns. -->
<!-- Spec reviewed 2026-05-16 — WP12 mission close-out; charter §5.6 + §5.9 ratified; doctrine spec frozen pending squash-merge SHA stamp. -->

**Status:** **SHIPPED** at mission close (2026-05-16). Charter §5.6 (listing surface) and §5.9 (cache tag-aware ops + context registry) ratified by WP12 — see [`stability-charter.md`](stability-charter.md).
**Post-mortem stamp:**
- **Mission close date:** 2026-05-16
- **Squash-merge SHA:** `<SHA TBD at merge>` (M-007 squash to `main`; filled in by the mission close-out commit after the lane-a → main merge lands)
- **Shipped:** WP01..WP12 — all 63 FRs + 5 NFRs satisfied. New package `packages/listing/` at Layer 3. Cache package gains `TaggedCacheInterface` + `ContextRegistry` + `ContextResolver` + `ContextNames`. Foundation gains `Http\RequestContext`. Reference consumer (`EventEntity` + `upcoming_events` listing) exercises the full pipeline end-to-end against `InMemoryEntityStorage` + `MemoryBackend`.
- **Open follow-ups:** beta-gate criterion 7 in [`stability-charter.md`](stability-charter.md) §3.2 (production-consumer demonstration); M-004 (translatable revisions) is now unblocked — its WP07 consumes FR-046..FR-049 from this mission.
- **No retracted artifacts.** All public surface listed in §6 is enumerated in [`public-surface-map.md`](public-surface-map.md) and [`../public-surface-map.php`](../public-surface-map.php).

**Audience:** framework maintainers; input for Spec Kitty `specify` → `plan` → `tasks` flow
**Mission ID:** M-007 (display) / `01KRMN0B4FWX9PK80RPSYDX1QM` (Spec Kitty)
**Mission slug:** `listing-pipeline-v1-01KRMN0B`
**Origin:** [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) "Listing pipeline (Views equivalent, integrate-not-build)" (Accepted 2026-05-11).

**Governing ADRs:** [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) — listing pipeline contract (`ListingDefinition`, `HasListingsInterface`, `ListingResolver`, `ListingResult`, cache tag/context contract). [ADR 010](../adr/010-storage-backends-gate-query-support.md) — `supportsQuery()` contract that filters/sorts honor. [ADR 011](../adr/011-lifecycle-events.md) — `AfterSaveEvent`/`AfterDeleteEvent` that drive cache invalidation. [ADR 013](../adr/013-display-stays-in-app-land.md) — display rendering is app concern. [ADR 014](../adr/014-themes-can-ship-listing-templates.md) — theme-shippable listing partials.

**Charter linkage:**
- [`stability-charter.md`](stability-charter.md) §3.2 beta entry criteria gain a new bullet (per ADR 015 §Consequences): *"ListingDefinition contract is stable and at least one consumer app uses it for production listings."* The charter amendment lands in this mission's documentation WP. **BETA-GATE.**
- A new charter §5.X (number to be assigned at amendment time, in line with §5.8 for migration) governs the `listing` package public surface. The `cache` package gains a new §5.Y stable-surface section for tag-aware operations and the context registry.

**Sibling missions:**
- [`entity-storage-v2.md`](entity-storage-v2.md) (M-001) — **shipped 2026-05-11**. Provides `EntityQuery::supportsQuery()` and `UnsupportedQueryException` that listing pipeline consumes for definition validation.
- [`entity-storage-translations-v1.md`](entity-storage-translations-v1.md) (M-006) — **shipped 2026-05-13**. C-002 explicitly assigns per-langcode filters and langcode-in-cache-tags to this mission's surface. M-006 ships `TranslatableInterface` + `SaveContext::langcode`; this mission ships the listing-layer integration.
- [`entity-storage-translatable-revisions.md`](entity-storage-translatable-revisions.md) (M-004) — **BLOCKED on this mission.** Composes single-axis translation with single-axis revisions. M-004 WP07 (per-langcode listing filters, langcode cache tags) is the downstream consumer of FR-019..FR-022 below.
- [`migration-platform-v1.md`](migration-platform-v1.md) (M-002) — independent. Migrations do not write listings; listings are read paths.
- [`config-management-v1.md`](config-management-v1.md) (M-003) — independent. Config entities are not listing subjects.

**Comparable mission:** [`entity-storage-translations-v1.md`](entity-storage-translations-v1.md) — shape and rigor template (14 WPs, contract-suite + backend-conformance + integration tests, charter amendment, beta-gate).

---

## 0. Origin

ADR 015 made the largest single product decision in the post-charter framework roadmap: ship a Views-equivalent **listing pipeline** as stable v0.x surface, with admin-composability deferred to v1.x. The matrix at `docs/specs/drupal-comparison-matrix.md` §6.6 calls listings *"the killer feature that has been the difference between Drupal and 'Symfony plus an ORM'"* — 60–80% of pages on a community CMS are listings, and `EntityStorageInterface::getQuery()` alone (the v0 substrate) leaves filter UI, paginated rendering, cache-tag invalidation, and multi-display all in app code.

The ADR pinned the contract: `ListingDefinition` value objects, registered via a `HasListingsInterface` provider capability parallel to `HasNativeCommandsInterface` and `HasMigrationsInterface`; `ListingResolver` builds an `EntityQuery`, applies access policies, returns a typed `ListingResult` with rows + pagination metadata + cache tags + cache contexts. Display stays in app land per ADR 013. The cache backend in `packages/cache` gains tag-aware invalidation as part of this work — ADR 015 §Consequences flags this as *"the right forcing function"* for committing the framework to a cache-tags-and-contexts architecture.

Today's substrate (post M-006 close-out on 2026-05-14):
- `EntityQuery` exists with `supportsQuery()` gating (M-001) and per-langcode predicates declared out-of-scope for M-006 (C-002).
- `AfterSaveEvent` / `AfterDeleteEvent` fire on every entity write (ADR 011 substrate landed pre-charter).
- `packages/cache` has only key-value Set/Get/Delete; no tags, no contexts.
- No `Listing*` symbol exists anywhere in `packages/`.
- `docs/adr/015-listing-pipeline-views-equivalent.md` exists; no implementation.

This mission ships the listing pipeline and the cache tag/context substrate it requires.

---

## 1. Goals / non-goals

### 1.1 Goals

1. Define `ListingDefinition` + `FilterDefinition` + `SortDefinition` as immutable value objects with the operator vocabulary ADR 015 implies.
2. Define `HasListingsInterface` provider capability; integrate with `PackageManifestCompiler` attribute/interface discovery.
3. Ship `ListingResolver` that builds an `EntityQuery` from a `ListingDefinition`, applies access policies per-row post-query, and returns a typed `ListingResult`.
4. Extend `packages/cache` with **tag-aware** operations: `setWithTags(string $key, mixed $value, array $tags, ?int $ttl)`, `invalidateByTag(string $tag)`, plus the **context registry** that resolves varying axes (`user.roles`, `url.query.<param>`, etc.) into deterministic cache-key segments.
5. Wire `AfterSaveEvent` / `AfterDeleteEvent` listeners that compute changed-entity tags and invalidate cache entries by tag.
6. Ship the exposed-filter URL parser: `FilterDefinition::exposed(string $param)` declarations parse and type-coerce `$_GET` values, applying only validated values; invalid input is dropped silently with an event for observability.
7. Ship **langcode awareness** as M-006's C-002 obligation: `FilterDefinition::langcode(string $code)` predicate, langcode included in default cache tags for translatable entity types, langcode in `cacheContexts()` when relevant.
8. Validate `ListingDefinition` instances at boot via `PackageManifestCompiler`: raise `UnsupportedListingException` when any filter or sort targets a field whose backend reports `supportsQuery() === false`.
9. Land the **charter amendment**: §3.2 beta entry criteria gain the listing-pipeline bullet (per ADR 015); a new §5.X section governs the `listing` package public surface; cache §5.Y section governs tag-aware operations and context names.
10. Land documentation: `docs/specs/listing-pipeline.md` (canonical, post-mission update), `docs/cookbook/listing-first-cut.md` (how an app registers a listing), and `docs/conventions/cache-tags-and-contexts.md` (the tag/context vocabulary).

### 1.2 Non-goals

1. **Admin UI / browser-based listing builder.** Deferred to v1.x per ADR 015 §Admin-composability.
2. **Cursor-based pagination.** Offset+limit only in v0.x; cursor deferred to v1.x (see §3.7).
3. **Pre-filtered access via SQL.** Access policies are applied row-by-row post-query (see §3.8); a `node_access_records`-style SQL-pre-filter is a separate future ADR.
4. **Cross-backend joins.** A listing of one entity type cannot filter/sort by a remote-backed field on a different entity type. Single-backend single-type listings only in v0.x.
5. **Filter UI rendering.** Apps render exposed-filter widgets; framework provides parsing, type coercion, and validation.
6. **Block / feed / REST display plugins.** Apps consume `ListingResult` in their controllers and render via Twig partials per ADR 013.
7. **Saved listings / per-user customisation.** v1.x admin UI concern.
8. **Cardinality estimation / query planning.** v0.x runs the query as written; future perf work may add hints.

---

## 2. Scope summary

### 2.1 In scope

- New package: `packages/listing/` at Layer 3 (services), namespace `Waaseyaa\Listing\`. Composer manifest, ServiceProvider, autoload.
- `ListingDefinition`, `FilterDefinition`, `SortDefinition`, `ListingResult`, `Pagination`, `Operator` (enum) — immutable value objects in `Waaseyaa\Listing\` root.
- `HasListingsInterface` capability — apps and packages declare it on their `ServiceProvider`-equivalent to expose listings.
- `ListingResolver` service — single public method `resolve(ListingDefinition, ?ExposedFilterValues): ListingResult` plus an internal helper for cache-aware re-resolution.
- `ExposedFilterValues` — typed wrapper around the parsed `$_GET` slice that a controller passes to `resolve()`.
- `ListingDefinitionRegistry` — read-only registry populated at boot from `HasListingsInterface` discovery; resolves `id` → `ListingDefinition`.
- `UnsupportedListingException` — definition-time error; carries `listingId`, `fieldName`, `reason` (`supportsQuery=false` / `unknown-field` / `langcode-on-non-translatable` / etc.).
- Cache package additions: `TaggedCacheInterface` (`setWithTags`/`invalidateByTag`), `ContextResolver`, `ContextRegistry`, `ListingCacheKeyBuilder`.
- Event listener: `ListingCacheInvalidator` subscribing to `AfterSaveEvent` + `AfterDeleteEvent`, computes affected tags, calls `invalidateByTag`.
- Exposed-filter URL parser: `ExposedFilterParser` reading PSR-7-style query params, returning typed `ExposedFilterValues`.
- Langcode-aware integration: filter operator + tag contribution + context contribution for translatable entity types.
- Boot-time definition validator: `ListingDefinitionValidator` invoked by `PackageManifestCompiler` after manifest registration; throws `UnsupportedListingException` on any invalid binding.
- Charter amendments: §3.2 beta criteria, §5.X listing surface, §5.Y cache tag/context surface.
- Documentation: cookbook, conventions doc, mission post-mortem stamp on `docs/specs/listing-pipeline.md`.
- One reference consumer wired in the test/fixture surface (a single fixture listing exercised end-to-end against `InMemoryEntityStorage` + `MemoryBackend` cache).

### 2.2 Out of scope

- Existing `EntityQuery` non-listing predicates (already shipped by M-001 — listing pipeline consumes them, does not redefine them).
- Persistent storage of listings (config-entity-shape "saved listings" — v1.x admin UI concern).
- Cross-entity-type filters / joins.
- Async / queue-driven listing resolution (all `resolve()` calls are synchronous in v0.x).
- Per-row attribute access (different from per-entity access; field-level access is a separate read-side concern on `EntityRepository`).

---

## 3. Functional requirements

### 3.1 ListingDefinition value object

- **FR-001** `ListingDefinition` MUST be a `final readonly class` with constructor parameters: `string $id`, `string $entityType`, `?string $bundle = null`, `array $filters = []`, `array $sorts = []`, `?int $pageSize = null`, `array $accessOps = ['view']`. `$id` is the registry key; MUST be unique across all registered listings, MUST match `[a-z][a-z0-9_]*`.
- **FR-002** `$filters` MUST be `FilterDefinition[]`; `$sorts` MUST be `SortDefinition[]`; both arrays are positional but order-significant for SQL emission (filters AND-composed; sorts applied left-to-right).
- **FR-003** `$pageSize` of `null` means "no paging" — the listing returns all matching rows in a single page. Apps SHOULD set `$pageSize` explicitly; null is for fixture / small-domain listings only.
- **FR-004** `$accessOps` declares which gate operations all returned rows MUST be allowed for; default `['view']`. Multi-op lists are AND-composed (every op must be allowed). Empty array is forbidden; throws at definition validation.
- **FR-005** `ListingDefinition` MUST be hash-stable: two definitions with identical field values produce the same `cacheKey()` digest. Implementation uses canonical JSON serialisation, not `serialize()`.

### 3.2 FilterDefinition + operators

- **FR-006** `FilterDefinition` MUST be a `final readonly class` with constructor: `string $field`, `Operator $op`, `mixed $value`, `?string $exposedParam = null`. `$exposedParam` MUST match `[a-z][a-z0-9_]*` if set.
- **FR-007** `Operator` MUST be a backed enum with values: `EQ`, `NEQ`, `LT`, `LTE`, `GT`, `GTE`, `IN`, `NOT_IN`, `IS_NULL`, `IS_NOT_NULL`, `BETWEEN`, `STARTS_WITH`, `CONTAINS`. `STARTS_WITH` and `CONTAINS` are case-insensitive LIKE-shape predicates with escaped `%`/`_` per the entity-storage gotcha in CLAUDE.md.
- **FR-008** Static factories on `Filter` (sibling sugar class) MUST exist for every operator: `Filter::eq('field', value)`, `Filter::gte('field', value)`, `Filter::in('field', [a, b])`, `Filter::isNull('field')`, `Filter::langcode('en')`, etc.
- **FR-009** `Filter::exposed(FilterDefinition $base, string $param): FilterDefinition` MUST clone the base with `$exposedParam` set. This is the only path that creates an exposed filter; constructor parameter is package-private convention.
- **FR-010** `IN` / `NOT_IN` with empty arrays MUST throw `InvalidArgumentException` at definition construction (consistent with the `DBALSelect` empty-IN gotcha).
- **FR-011** Operator-to-value-type contracts MUST be enforced at construction: `EQ`/`NEQ` accept scalar; `IN`/`NOT_IN` accept non-empty array; `BETWEEN` accepts `[low, high]` tuple; `IS_NULL`/`IS_NOT_NULL` accept `null`; `STARTS_WITH`/`CONTAINS` accept string. Mismatches throw `InvalidArgumentException` with explicit message.

### 3.3 SortDefinition

- **FR-012** `SortDefinition` MUST be a `final readonly class` with constructor: `string $field`, `SortDirection $direction = SortDirection::ASC`. `SortDirection` is an enum (`ASC` | `DESC`).
- **FR-013** Static factories on `Sort`: `Sort::asc('field')`, `Sort::desc('field')`.
- **FR-014** Listing resolution MUST always append a final implicit `Sort::asc($entityType->getKey('id'))` to user-declared sorts for stable pagination, even when the user has supplied sorts (so two queries with the same filters return rows in a deterministic order even when user sort values tie).

### 3.4 HasListingsInterface provider capability

- **FR-015** `HasListingsInterface` MUST declare a single method: `public function listings(): array` returning `ListingDefinition[]`. Mirrors `HasNativeCommandsInterface` / `HasMigrationsInterface` exactly.
- **FR-016** `PackageManifestCompiler` MUST discover implementors via `instanceof HasListingsInterface` check on registered `ServiceProvider`s, the same path used for `HasNativeCommandsInterface`. Discovery happens at manifest-build time; results cached in `var/manifest.php`.
- **FR-017** `ListingDefinitionRegistry::get(string $id): ListingDefinition` MUST throw `UnknownListingException` with the requested id on miss.

### 3.5 ListingResolver service

- **FR-018** `ListingResolver` MUST be a `final class` with single public method `resolve(ListingDefinition $def, ?ExposedFilterValues $exposed = null): ListingResult` plus a service-locator constructor injecting `EntityRepositoryRegistry`, `GateInterface`, `TaggedCacheInterface`, `ContextResolver`.
- **FR-019** Resolution algorithm (normative — see §7.1): cache-key build → cache lookup → on miss: EntityQuery build → execute → access-policy filter per row → pagination + cache-tag/context attach → cache store with tags → return.
- **FR-020** Resolution MUST be deterministic: the same `(ListingDefinition, ExposedFilterValues, current request context)` MUST always produce the same `ListingResult` modulo cache state.
- **FR-021** Resolution MUST NOT throw on a single unauthorised row — rows that fail the access check are silently filtered. Resolution throws only on infrastructure failure (storage backend error, gate misconfiguration).

### 3.6 ListingResult shape

- **FR-022** `ListingResult` MUST be a `final readonly class` exposing: `iterable $rows` (iterable of entity instances), `Pagination $pagination`, `array $cacheTags` (`string[]`), `array $cacheContexts` (`string[]`). Internals are not stable surface; only the four accessors are.
- **FR-023** `cacheTags()` MUST include at minimum: `entity:<type>` for the listing's entity type, and `entity:<type>:<id>` for every entity present in the returned rows. Translatable entity types MUST also add `entity:<type>:<id>:<langcode>` per row.
- **FR-024** `cacheContexts()` MUST include BOTH `user.id` AND `user.roles` whenever the per-row access gate runs for the resolution — i.e. whenever the FR-032 fast path is NOT taken (`!canUseAccessFastPath()`), which includes the default `$accessOps === ['view']` path against any policy that has not opted into `SUPPORTS_LISTING_FAST_PATH`. This binds an account-dependent (role- or owner-filtered) result to the acting account so it is never served to a different user from cache (audit #28). It MUST include `url.query.page` whenever `$pageSize` is non-null; MUST include `url.query.<param>` for each `exposedParam` declared in filters; MUST include `language.content` whenever the entity type is translatable. Note: `ListingDefinition::effectiveContexts()` stays a pure function of the definition (it still adds `user.roles` only for non-default `$accessOps`); the gate-aware `user.id`/`user.roles` binding is applied by `ListingResolver::computeCacheContexts()`, which alone knows whether the fast path applies.
- **FR-025** `Pagination` MUST expose `int $page` (1-indexed), `int $pageSize`, `int $totalRows` (count of access-filtered rows across all pages — see §3.8 NFR-001 perf note), `int $totalPages` (ceil(totalRows/pageSize), 1 if pageSize is null), `bool $hasPrev`, `bool $hasNext`.

### 3.7 Pagination semantics

- **FR-026** Pagination is offset+limit. `?page=N` URL parameter (1-indexed) drives offset = `(N-1) * pageSize`; limit = `pageSize`.
- **FR-027** `?page=0` or `?page=` (empty) or `?page=N` for `N > totalPages` MUST clamp silently to a valid page (page 1 if `N ≤ 0`; last page if `N > totalPages`). The clamp behaviour MUST be observable via an `ExposedFilterValues` event so apps can surface a hint if desired.
- **FR-028** Cursor pagination is explicitly out of scope (§1.2 #2). No `cursor`-shaped public surface ships in v0.x.

### 3.8 Access policy application

- **FR-029** Access application is row-by-row post-query. For each row returned by the EntityQuery, the resolver MUST call `GateInterface::allows($entity, $op)` for each op in `$accessOps`; if any op returns `Forbidden`, the row is dropped from `$rows`. `Neutral` and `Allowed` both pass per the existing `AccessResult::isAllowed()` semantics.
- **FR-030** Resolved `$rows` MAY be shorter than `$pageSize` after access filtering. Pages are not backfilled; if 8 of 20 rows on a page are denied, the returned page has 12 rows. `Pagination::$totalRows` reflects the post-access count.
- **FR-031** `Pagination::$totalRows` MUST equal the count of access-filtered rows across all pages, UNLESS the listing declares `approximateTotal: true` (NFR-002 escape hatch), in which case `$totalRows` is `null`. The default computation runs the access-policy check over the full result set, not just the current page — costly for high-cardinality listings, which is why the escape hatch is opt-in. **Fast-path count source (perf):** when the FR-032 access fast-path applies AND no non-native operator is refined in-PHP post-fetch (i.e. every effective filter pushed to the storage driver as native criteria), no row is dropped between the raw query and the page, so the access-filtered count is *identical* to a storage `count()` of the same criteria. On that path the resolver MAY source `$totalRows` from `EntityRepositoryInterface::count($criteria)` and fetch only the requested page window via a driver-side `LIMIT`, hydrating one page instead of the whole result set. This is an equality-preserving optimisation, not a relaxation: the invariant "`$totalRows` = access-filtered count" still holds exactly. The full-hydrate-then-filter computation remains mandatory on every path that can drop rows post-fetch (access ops present without fast-path opt-in, in-PHP operator refinement, `approximateTotal`).
- **FR-032** When `$accessOps === ['view']` and no policy on the entity type opts into per-row decisions (i.e., all policies return `Neutral` or `Allowed` unconditionally), the resolver MAY skip the per-row loop and treat the raw query result as the access-filtered result. Detection is via a `public const bool SUPPORTS_LISTING_FAST_PATH = true` opt-in on the policy class, probed through the optional `Waaseyaa\Access\Gate\ListingFastPathProbeInterface` capability on the gate (a gate that does not implement the probe, or a policy that did not opt in, leaves the per-row loop running). Default `false` (safe). The fast-path also gates the FR-031 count-source optimisation above.

### 3.9 Cache tag/context architecture

- **FR-033** `TaggedCacheInterface` MUST extend `Waaseyaa\Cache\CacheInterface` with: `setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void`, `invalidateByTag(string $tag): int` (returns the number of evicted entries), `getTagsFor(string $key): array` (introspection for tests).
- **FR-034** Tag string format MUST be `[a-z][a-z0-9_:.-]*` enforced at `setWithTags()` time; invalid tags throw `InvalidArgumentException` (no silent normalisation). The canonical tag vocabulary is documented in `docs/conventions/cache-tags-and-contexts.md` (this mission ships the doc).
- **FR-035** `ContextRegistry` MUST hold the framework's whitelist of context names: `user.roles`, `user.id`, `url.query.<param>`, `language.content`, `language.interface`, plus any registered by extension packages. An unknown context name appearing in `ListingResult::cacheContexts()` MUST log a warning via `LoggerInterface` and cause the resolver to bypass the cache for that resolution (the listing resolves, the result is not stored, the next resolve incurs another miss). Throws are reserved for definition-time validation failures, not resolution-time anomalies.
- **FR-036** `ContextResolver::resolve(string $context, RequestContext $req): string` MUST return a deterministic short string for the current request. `user.roles` returns sorted role IDs joined with `,`; `url.query.<param>` returns the URL-decoded value or empty string; `language.content` returns the active langcode; `user.id` returns the integer id as string.
- **FR-037** `ListingCacheKeyBuilder::build(ListingDefinition $def, ExposedFilterValues $values, array $contextValues): string` MUST produce a deterministic key combining the definition's hash, the exposed values' hash, and the resolved context values' hash. Format: `listing:<def-hash>:<exposed-hash>:<ctx-hash>`. SHA-256 truncated to 16 hex chars per component.

### 3.10 Cache integration (AfterSaveEvent / AfterDeleteEvent)

- **FR-038** `ListingCacheInvalidator` MUST subscribe to `AfterSaveEvent` and `AfterDeleteEvent` via the event-dispatcher contract (existing in `packages/foundation`).
- **FR-039** On each event: compute affected tags as `[entity:<type>, entity:<type>:<id>]`. For translatable entities, also add `entity:<type>:<id>:<langcode>` for every langcode that was modified in this save (`AfterSaveEvent` SHOULD already carry `affected_langcodes`; if not, fall back to a single tag for the active langcode).
- **FR-040** Invalidator MUST call `$taggedCache->invalidateByTag($tag)` for each affected tag. Failures (cache backend down) MUST log via `LoggerInterface` at warning level but MUST NOT raise — invalidator is best-effort per the established "no logging dependency loops" gotcha.
- **FR-041** Invalidation is synchronous in v0.x. A future ADR may move it to a queue.

### 3.11 Exposed filters (URL parsing)

- **FR-042** `ExposedFilterParser::parse(array $queryParams, ListingDefinition $def): ExposedFilterValues` MUST extract only those params named by an `exposedParam` on a filter in `$def`. Other params are ignored.
- **FR-043** Type coercion per operator: scalar operators (`EQ`/`NEQ`/comparisons) coerce per the field's typed-data type; `IN`/`NOT_IN` accept comma-separated values; `BETWEEN` accepts `<low>~<high>`; `IS_NULL`/`IS_NOT_NULL` accept any non-empty value as "true"; `STARTS_WITH`/`CONTAINS` accept raw string.
- **FR-044** Coercion failures (e.g. `?published=banana` on a boolean field) MUST drop the filter silently and log a debug-level event. The listing resolves without that filter — never throws on user input.
- **FR-045** Parsed values are URL-decoded once; the `%`/`_` escaping for LIKE-shape ops happens inside the operator's SQL emission, not in the parser.

### 3.12 Langcode-aware filtering

- **FR-046** `Filter::langcode(string $code): FilterDefinition` MUST emit a filter that restricts results to rows whose translation exists for `$code`. For sql-blob backend, this reads from the `_data` JSON's translation map; for sql-column backend, this joins to the `<table>__translation` sibling table.
- **FR-047** When a listing's entity type is translatable AND no explicit `langcode` filter is declared, the resolver MUST add an implicit filter for the current request's active langcode (resolved via `ContextResolver::resolve('language.content', $req)`).
- **FR-048** `cacheContexts()` MUST include `language.content` for listings of translatable entity types, regardless of whether the user declared a langcode filter.
- **FR-049** Per-langcode access policy (per `TranslatableInterface` + the `'translate'` op shipped by M-006) is applied as part of the normal `$accessOps` loop in FR-029. No new policy plumbing is needed for translatable listings — they're listings where `$accessOps` may include `'translate'`.

### 3.13 Definition validation at boot

- **FR-050** After manifest registration, `ListingDefinitionValidator::validate(ListingDefinitionRegistry $reg, EntityTypeManager $etm): void` MUST run, raising `UnsupportedListingException` on the first failure with full context (listing id, field name, reason).
- **FR-051** Validation rules: (a) entity type must exist in `EntityTypeManager`; (b) bundle (if set) must be a valid bundle for that type; (c) every filter/sort field must be a field on the entity type or a derived attribute; (d) every filter/sort field must report `supportsQuery: true` on its bound storage backend; (e) every operator must be compatible with the field's typed-data type (e.g., `BETWEEN` requires comparable type); (f) langcode filters/contexts may only appear on translatable types.
- **FR-052** Validation runs in `PackageManifestCompiler::warm()` after entity-type registration but before route dispatch. In dev, it runs on every request; in prod, only when `var/manifest.php` is rebuilt.
- **FR-053** Validation failures MUST be fail-fast: the kernel refuses to boot, with the exception's full message in the error log. No silent "broken listing" state.

#### What FR-051(d) does and does not promise (#2157)

Rule (d) — "Rule G" in `ListingDefinitionValidator` — checks the field **declaration**, not the physical table. Three properties are easy to conflate, and conflating them produced a silent failure before #2157:

| Property | Means | Established by |
|---|---|---|
| **Queryable** | The field may be filtered and sorted on. **This is all Rule G checks.** | `FieldStorage::Column` on the field definition |
| **Physically materialised** | The field is a real database column. | The *entity type's* primary storage backend being `sql-column` — never the field alone |
| **Physically indexed** | That column additionally carries a B-tree index. | `indexed: true` **and** the `sql-column` backend |

A field declared `FieldStorage::Column` on an entity type whose backend resolves to `sql-blob` (the default) passes Rule G, lives in the `_data` JSON blob, and is filtered over the blob with no index. That is supported and remains the behaviour of every shipped listing — `node`'s article listings work exactly this way.

A facet that must be *physically indexed* requires its entity type to declare `storageBackend: PrimaryStorageBackend::SQL_COLUMN` on `#[ContentEntityType]` and `indexed: true` on the `#[Field]`. Declaring `indexed: true` on a type whose backend cannot materialise it raises `UnmaterializableIndexException` at schema-sync time rather than failing silently — see [`entity-system.md`](entity-system.md) §"Selecting a backend from the attribute (#2157)".

### 3.14 Error model

- **FR-054** `UnsupportedListingException extends RuntimeException`. Carries `string $listingId`, `?string $fieldName`, `string $reason`.
- **FR-055** `UnknownListingException extends RuntimeException`. Carries `string $listingId`.
- **FR-056** All operator-mismatch / invalid-config errors at definition construction throw `InvalidArgumentException` (PHP convention).
- **FR-057** Resolution-time storage backend errors propagate as-is (they're already typed by the backend).
- **FR-058** Cache-backend errors are caught and logged inside the resolver; resolution continues without caching.

### 3.15 Documentation

- **FR-059** `docs/specs/listing-pipeline.md` MUST exist at mission close as the canonical post-mission doctrine spec (this filing-time doc gets a post-mortem stamp; the canonical spec lives at the same path with squash-merge SHA stamped in).
- **FR-060** `docs/cookbook/listing-first-cut.md` MUST be authored: a fully-worked example app registering a single listing (e.g., "upcoming events"), declaring exposed filters, and rendering via a Twig partial. Includes a snippet of the controller, the service-provider registration, the partial, and a unit/integration test.
- **FR-061** `docs/conventions/cache-tags-and-contexts.md` MUST be authored: documents the canonical tag vocabulary (entity:type, entity:type:id, entity:type:id:langcode), the context registry, and the resolver semantics. Stable surface — every consumer relies on these strings.
- **FR-062** `docs/specs/stability-charter.md` §3.2 amendment MUST be authored as part of this mission's WP11 (charter amendment + docs WP). Adds: *"Criterion 10 (post-charter): `ListingDefinition` contract is stable and at least one consumer app uses it for production listings."*
- **FR-063** `CLAUDE.md` orchestration table MUST be updated to add `packages/listing/*` → `docs/specs/listing-pipeline.md`. Layer 3 services row updated to include `listing`. `CHANGELOG.md` `[Unreleased]` Added bullet for M-007.

---

## 4. Non-functional requirements

- **NFR-001** Per-row access check overhead MUST be < 1 ms p95 per row on a Sonnet-class workstation (target: 50ms for 50-row page). Measured via the contract suite's `ListingResolverContract::accessFastPathBenchmark`.
- **NFR-002** `Pagination::$totalRows` accuracy SHOULD be exact, but the resolver MAY accept a per-listing opt-in `approximateTotal: true` flag (default `false`) that skips the full-access-scan and returns `null` for `$totalRows`. v0.x ships `approximateTotal` as a non-default escape hatch; v1.x may move it default-on for large listings.
- **NFR-003** Cache hit overhead MUST be < 0.5 ms p95: tag lookup + key build + cache fetch. Cache miss overhead matches the underlying EntityQuery + N access checks.
- **NFR-004** Zero new PHPStan or PHPUnit warnings introduced (level 5, baseline-grown line-by-line as in M-006 / M-002).
- **NFR-005** The mission's reference consumer (the fixture listing under `packages/listing/tests/Fixtures/`) MUST resolve end-to-end against both `InMemoryEntityStorage` and `DBALDatabase::createSqlite()`, with cache miss + hit + invalidation traces verified.

---

## 5. Constraints

- **C-001** This mission MUST NOT introduce any new layer-graph edge beyond `listing` (L3) → `entity-storage` (L1) → `cache` (L0) → `foundation` (L0). No upward edge from any package; the cache enhancements live in `cache` (L0) and are consumed by `listing` (L3). The event-listener wiring (`ListingCacheInvalidator`) lives in the `listing` package and subscribes via the foundation event dispatcher — it does not require foundation to import listing.
- **C-002** This mission MUST NOT extend `EntityQuery` with listing-specific predicates that have no other consumer. `Filter::langcode` translates to existing `EntityQuery` predicates added by M-006 (translation join / sql-blob translation-map probe). If a listing operator requires a new `EntityQuery` predicate, it MUST land in `entity-storage` first via a small follow-up surface addition, with its own contract test.
- **C-003** This mission MUST NOT define `display` or `theme` surfaces — ADR 013 stands. Apps render. The mission ships zero Twig templates beyond the cookbook example.
- **C-004** This mission MUST NOT change existing `EntityRepository::findBy` / `EntityQuery::execute` semantics. Listings build queries; they don't replace them.
- **C-005** This mission MUST NOT introduce a circular dependency between `cache` and any higher layer. `ContextResolver` reads request state via the foundation `RequestContext` interface — never via direct `$_GET` / `$_SESSION` access. (Foundation already exposes `RequestContext`.)
- **C-006** `ListingDefinition` instances MUST be safely shareable across requests: immutable, no closures, no resources. Discovery yields ready-to-use objects that the manifest can serialise to `var/manifest.php` cache.

---

## 6. Stable surface deliverables

The following symbols become charter §5.X (listing) and §5.Y (cache) stable surface at mission close. Any non-additive change post-mission requires a deprecation + supersede cycle per charter §4.

**Listing package (`Waaseyaa\Listing\` — new §5.X):**

| Symbol | Kind | Stability note |
|---|---|---|
| `ListingDefinition` | value object | Constructor signature, all accessors |
| `FilterDefinition`, `Filter` | value object + factory | All factory methods + constructor |
| `SortDefinition`, `Sort`, `SortDirection` | value object + enum | All factories + enum cases |
| `Operator` | enum | All cases — future additions are additive only |
| `HasListingsInterface` | capability interface | Single method signature |
| `ListingResolver` | service | `resolve()` signature |
| `ListingResult`, `Pagination` | value object | All accessors |
| `ExposedFilterValues`, `ExposedFilterParser` | value object + service | Parse signature + accessor shape |
| `ListingDefinitionRegistry` | registry | `get()` signature |
| `UnsupportedListingException`, `UnknownListingException` | exception | FQCN + carried context |

**Cache package additions (`Waaseyaa\Cache\` — new §5.Y):**

| Symbol | Kind | Stability note |
|---|---|---|
| `TaggedCacheInterface` | interface | `setWithTags` + `invalidateByTag` + `getTagsFor` |
| `ContextResolver` | service | `resolve(string, RequestContext): string` |
| `ContextRegistry` | registry | `register(string)` + `has(string)` + canonical names |
| Canonical context names | string constants | `user.roles`, `user.id`, `url.query.*`, `language.content`, `language.interface` |
| Tag-string format regex | doc-level rule | `[a-z][a-z0-9_:.-]*` |
| Canonical tag vocabulary | doc-level rule | `entity:<type>`, `entity:<type>:<id>`, `entity:<type>:<id>:<langcode>` |

INTERNAL (NOT stable surface):
- `ListingCacheKeyBuilder` — implementation detail; key format may change.
- `ListingCacheInvalidator` — event-listener wiring; subject to refactor.
- `ListingDefinitionValidator` — boot-time helper; refactorable.

---

## 7. Behavior specs (normative)

### 7.1 Resolution algorithm

1. `$def = $registry->get($id)` — throws `UnknownListingException` on miss.
2. `$exposed = $parser->parse($req->getQueryParams(), $def)` — never throws on user input.
3. `$contextValues = []`; for each `$ctx` in `$def->effectiveContexts()` (declared + implicit per FR-024): `$contextValues[$ctx] = $resolver->resolve($ctx, $req)`.
4. `$key = $keyBuilder->build($def, $exposed, $contextValues)`.
5. `$cached = $cache->get($key)` — if non-null, return as `ListingResult`.
6. Build `$query = $queryBuilder->fromListing($def, $exposed, $contextValues)` — applies filters, sorts, implicit langcode (FR-047), implicit `id` tie-break sort (FR-014), `?page=N` → offset/limit. **Bundle-attached fields** (registered via `FieldDefinitionRegistry::registerBundleFields()`) are never pushed as storage-native criteria or order-by — they live in per-bundle subtables the base query cannot see; their filters are refined in-PHP post-fetch and a sort on one re-applies the complete sort chain in-PHP (see the 2026-07-26 spec-review note).
7. `$rawRows = $query->execute()`.
8. `$accessRows = []`; for each `$row` in `$rawRows`: if all `$op` in `$def->accessOps` return non-`Forbidden` from gate, append to `$accessRows`. (FR-032 fast-path opt-in short-circuits the loop.)
9. Build `$pagination` per FR-025 — `$totalRows` computed by running steps 7–8 over the full result set sans paging (or `null` if FR-027 fast-path / `approximateTotal`).
10. Build `$tags` per FR-023 + `$contexts` per FR-024; instantiate `ListingResult($accessRows, $pagination, $tags, $contexts)`.
11. `$cache->setWithTags($key, $result, $tags, $ttl=null)`.
12. Return `$result`.

### 7.2 Cache lookup + miss

`$cache->get($key)` returns `null` on miss. The resolver MUST handle `null` as "miss"; non-null but malformed values (e.g. wrong class after a deserialisation hiccup) MUST be discarded with a warning and a fresh miss-path resolution. Symmetrical with the foundation's atomic-file-write + corrupt-file-recovery patterns from CLAUDE.md.

### 7.3 Tag invalidation flow

1. `AfterSaveEvent` or `AfterDeleteEvent` dispatched.
2. `ListingCacheInvalidator::on*(EntityEvent $e)` reads `$e->entity` (public readonly per CLAUDE.md gotcha).
3. Compute `$tags = ["entity:{$e->entity->getEntityTypeId()}", "entity:{$e->entity->getEntityTypeId()}:{$e->entity->id()}"]`.
4. If translatable: for each `$lc` in `$e->affectedLangcodes ?? [$e->entity->activeLangcode()]`, append `"entity:{$e->entity->getEntityTypeId()}:{$e->entity->id()}:{$lc}"`.
5. For each `$tag`: `try { $cache->invalidateByTag($tag) } catch (\Throwable $t) { $logger->warning(...) }`.

### 7.4 Exposed-filter parsing

1. For each `$filter` in `$def->filters` with non-null `$filter->exposedParam`:
   1. `$raw = $queryParams[$filter->exposedParam] ?? null`.
   2. If `$raw === null` or `$raw === ''`: skip (filter is not applied; default behaviour governs).
   3. Try `$coerced = $coercer->coerce($raw, $filter->op, $field->getTypedDataType())`.
   4. On `CoercionException`: log debug, skip filter.
   5. On success: `$values->set($filter->exposedParam, $coerced)`.
2. Return `$values`.

---

## 8. Test surface

### 8.1 Contract suite

`packages/listing/tests/Contract/ListingResolverContract.php` — abstract `TestCase` with `#[CoversNothing]`. Subclassed by:
- `InMemoryListingResolverTest` — runs against `InMemoryEntityStorage` + `MemoryBackend` cache.
- `SqliteListingResolverTest` — runs against `DBALDatabase::createSqlite()` + `MemoryBackend` cache.

Cases (`#[Test]`):
- `resolveReturnsRowsMatchingFilters` (FR-019)
- `resolveReturnsEmptyOnNoMatch`
- `resolveRespectsPageSize` (FR-026)
- `resolveAppliesAccessPolicyPerRow` (FR-029)
- `resolveProducesShortPagesAfterAccessFilter` (FR-030)
- `totalRowsReflectsAccessFilteredCount` (FR-031)
- `accessFastPathOptInSkipsPolicyLoop` (FR-032)
- `accessFastPathBenchmark` (NFR-001 — sentinel, not assertion)
- `cacheTagsIncludeEntityRows` (FR-023)
- `cacheContextsIncludeLanguageOnTranslatable` (FR-048)
- `cacheKeyIsDeterministic` (FR-037)
- `cacheHitSecondResolveReturnsSameResult` (NFR-003)
- `invalidationOnAfterSaveDropsTaggedEntries` (FR-039)
- `unsupportedFilterRaisesAtValidation` (FR-050)
- `exposedFilterCoercesIntFromString` (FR-043)
- `exposedFilterDropsOnCoercionFailure` (FR-044)
- `pageClampsBelowOne` + `pageClampsAboveTotal` (FR-027)
- `implicitLangcodeFilterAppliedOnTranslatable` (FR-047)
- `langcodeTagAddedPerRowOnTranslatable` (FR-023 translatable case)

### 8.2 Backend conformance

`packages/listing/tests/Backend/` mirrors `packages/entity-storage/tests/Backend/`:
- `SqlColumnTranslatableListingTest` — translatable sql-column listings + langcode filter join.
- `SqlBlobTranslatableListingTest` — sql-blob translation map probe.
- `NonTranslatableListingTest` — language.content context NOT added.

### 8.3 Integration tests

`tests/Integration/Phase14/` (next phase number; verify against current tree at plan time):
- `ListingPipelineIntegrationTest` — full HTTP flow: register listing via service provider → request with `?page=2&status=published` → assert response shape + cache headers (Surrogate-Control if applicable later).
- `ListingCacheInvalidationIntegrationTest` — register listing → resolve → modify entity → re-resolve → assert cache miss + new content.
- `BootValidationFailureTest` — register a listing referencing a `supportsQuery=false` field; assert kernel boot fails with `UnsupportedListingException`.

---

## 9. Work package decomposition (sketch)

| WP | Title | FRs | Depends on |
|---|---|---|---|
| **WP01** | Listing package scaffold + value objects | FR-001..FR-014, FR-054..FR-058 | — |
| **WP02** | `HasListingsInterface` + `PackageManifestCompiler` discovery | FR-015..FR-017 | WP01 |
| **WP03** | Cache package: `TaggedCacheInterface` + `MemoryBackend` impl + tag indexing | FR-033..FR-034 | — (parallel to WP01) |
| **WP04** | Cache package: `ContextRegistry` + `ContextResolver` + canonical names | FR-035..FR-036 | WP03 |
| **WP05** | `ListingResolver` core: EntityQuery build + paginate (no access, no cache) | FR-018..FR-021 partial, FR-026 | WP01, WP02 |
| **WP06** | Row-by-row access policy application + fast-path opt-in | FR-029..FR-032 | WP05 |
| **WP07** | `ListingCacheKeyBuilder` + cache integration in resolver | FR-037, FR-019 final | WP04, WP05 |
| **WP08** | `ListingCacheInvalidator` event listener + tag computation | FR-038..FR-041 | WP07 |
| **WP09** | `ExposedFilterParser` + coercer | FR-042..FR-045 | WP05 |
| **WP10** | Langcode-aware filters + langcode tags + implicit langcode context | FR-046..FR-049, FR-023 translatable case | WP07, M-006 (shipped) |
| **WP11** | Boot-time definition validator + `UnsupportedListingException` raise path | FR-050..FR-053 | WP02, WP05 |
| **WP12** | Charter §3.2 + §5.X + §5.Y amendments, cookbook, conventions doc, CLAUDE.md row, CHANGELOG entry, reference consumer fixture | FR-059..FR-063 | All prior |

### 9.1 Parallelizable lanes

- WP01 + WP03 are independent — different packages, no shared symbols.
- After WP01 + WP02 + WP03 + WP04: WP05 starts. WP09 (exposed-filter parser) can run in parallel with WP05 once value objects exist.
- WP10 (langcode) requires WP07's cache integration.
- WP12 is the closer and must follow all implementation WPs.

### 9.2 Validation gate

After WP11, run the contract suite + backend conformance + integration tests. WP12 documentation work is gated on a green test suite.

---

## 10. Acceptance criteria

1. All FR-001..FR-063 covered by tests in §8 with traceability (FR-id → test name).
2. All NFRs measurable; NFR-001 / NFR-003 sentinels run in CI but do not fail the build (perf is a regression watch, not a gate).
3. The reference consumer fixture (a single fixture listing) resolves end-to-end through `InMemoryEntityStorage` + `MemoryBackend`, demonstrating cache miss → cache hit → invalidation on save.
4. M-004's WP07 prerequisite is satisfied: per-langcode filters (FR-046), langcode in cache tags (FR-023 translatable case), langcode context (FR-048).
5. Charter §3.2 gains criterion 10; §5.X (listing) and §5.Y (cache) sections are filed.
6. `composer cs-check` + `composer phpstan` + `bin/check-composer-policy` + `bin/check-package-layers` + the full PHPUnit suite are green.
7. M-004 BLOCKED stamps in `kitty-specs/entity-storage-translatable-revisions-01KRCDEE/spec.md` and `docs/specs/entity-storage-translatable-revisions.md` are updated at this mission's close to remove the second-prerequisite line — M-004 becomes plannable.

---

## 11. Open questions

These items are deliberately not pinned in §3; they're for the `plan` phase to resolve:

1. **Default page size cap.** Should the framework enforce a max `pageSize` (e.g., 1000) to prevent accidental full-table scans, or trust the app? *Recommend: cap at 1000 with a `ListingDefinition::allowUnbounded(): self` opt-out.*
2. **Boot-time vs first-resolve validation cost.** Validating every listing at boot in dev mode adds startup cost. Acceptable, or move to first-resolve with a warm path? *Recommend: boot-time; dev startup cost is acceptable and prod is cached.*
3. **`approximateTotal` opt-in shape.** Per-listing flag on `ListingDefinition`, or a resolver-level option carried in `ExposedFilterValues`? *Recommend: per-listing flag (declarative).*
4. **Cache TTL policy.** Default infinite (until invalidated) vs default 1h vs configurable per listing? *Recommend: infinite default with optional per-listing `cacheTtl` override.*
5. **Event subscription ordering for `ListingCacheInvalidator`.** Should it run before or after other `AfterSaveEvent` listeners? Cache invalidation has ordering implications if another listener triggers a re-resolve. *Recommend: priority=100 (run early, so re-resolves see clean cache).*
6. **Filter UI parsing — strict mode for tests.** Should there be a "strict mode" that throws on coercion failures, for test-environment use, instead of the FR-044 silent-drop? *Recommend: yes, opt-in via `ExposedFilterParser::strict()`.*
7. **Tag invalidation under translatable saves.** `AfterSaveEvent` does not currently carry `affected_langcodes` (M-006 didn't add it). Either M-007 patches that event surface (additive), or the invalidator falls back to active-langcode-only tag invalidation. *Recommend: patch `AfterSaveEvent` with an optional `affected_langcodes` array property in WP08 of this mission (minor surface addition; consistent with M-006's translation work).*

---

## 12. References

- [ADR 015](../adr/015-listing-pipeline-views-equivalent.md) — the governing decision.
- [ADR 010](../adr/010-storage-backends-gate-query-support.md), [ADR 011](../adr/011-lifecycle-events.md), [ADR 013](../adr/013-display-stays-in-app-land.md), [ADR 014](../adr/014-themes-can-ship-listing-templates.md).
- [`entity-storage-v2.md`](entity-storage-v2.md) — M-001, provides `supportsQuery()` and `EntityQuery` substrate.
- [`entity-storage-translations-v1.md`](entity-storage-translations-v1.md) — M-006, provides `TranslatableInterface` + `SaveContext::langcode`; C-002 carved langcode-in-listing-pipeline as this mission's surface.
- [`entity-storage-translatable-revisions.md`](entity-storage-translatable-revisions.md) — M-004, the downstream consumer (WP07 specifically) that unblocks fully when this mission ships.
- [`stability-charter.md`](stability-charter.md) §3.2 (beta entry criteria — to be amended), §5 (stable surface — new §5.X + §5.Y).
- [`drupal-comparison-matrix.md`](drupal-comparison-matrix.md) §1.4, §3.4, §6.6 — origin of the gap.
- [`public-surface-map.md`](public-surface-map.md) — adds `Waaseyaa\Listing\*` + new `Waaseyaa\Cache\TaggedCacheInterface` + `ContextResolver` + `ContextRegistry` at mission close.
