# waaseyaa/search

**Layer 3 — Services**

Full-text and structured search for Waaseyaa applications.

Provides a search index abstraction and query builder for finding entities
across types. The FTS5 implementation indexes a fixed title/body document,
supports faceting and relevance ranking, preserves Indigenous orthography in
tokenization, and performs bounded access-filtered pagination. No first-party
API endpoint currently calls this read surface.

Key classes: `SearchRequest`, `SearchResult`, `SearchProviderInterface`.

## Parked read surface (R19)

The FTS5 read provider, request/result DTOs, access checker, and Twig helper are
`@internal`: the framework has no first-party HTTP, CLI, SSR, or admin caller.
The write-side indexer remains live for existing consumers. Reactivate the read
surface only when a first-party endpoint adopts it with an acting-account
access boundary and boundary tests covering access-filtered pagination. The
unused `SearchIndexJob` was deleted; asynchronous indexing must wait for a
production queue consumer rather than publishing an undrained message.

## Protected index trust boundary

`search_index` and `search_metadata` are a protected, derived datastore, not a
public content cache. An application-supplied search projection can contain
protected titles, bodies, URLs, topics, or other metadata, so the index inherits
the strongest classification of any indexed value. Protect its database file,
backups, replicas, diagnostics, and operator access to the same standard as
canonical entity storage.

Only `Fts5SearchIndexer` writes the raw tables and only the parked
`Fts5SearchProvider` reads them. A repository architecture test enforces that
inventory across first-party production PHP. Alternate providers,
autocomplete/suggestion features, diagnostics, and ad-hoc readers are
prohibited from querying the tables directly; they must enter through the
access-checked search contract once #2192 promotes it. The static inventory
cannot recognize a deliberately obscured dynamic table name or a non-PHP
operator script, so code review remains responsible for those evasions. Do not
log raw candidates or pre-access counts. Search result caches must be scoped to
the immutable acting principal and its claims generation; a shared result cache
is a cross-principal disclosure.

The provider cannot be constructed without an access checker. Unknown entity
types and non-entity sources are denied by default; public files or synchronized
corpora require an explicit source resolver under #2192. Indexed metadata is
never authority for declaring a source public. The existing boolean checker
does not yet make indexed field projections safe: the read surface remains
parked, and #2192 is a blocker to promoting it for any first-party caller.

## Implementation gotchas

- **Indigenous orthography is the tokenizer acceptance bar (#2010, R21):** `search_index` uses `tokenize="unicode61 remove_diacritics 0 tokenchars '''’ʼ'"` — Unicode word boundaries, no English Porter stemmer, no diacritic folding, and ASCII apostrophe/U+2019/U+02BC retained inside tokens. Round-trip coverage pins Anishinaabemowin double vowels, apostrophe/glottal forms, macrons/acute diacritics, and Canadian syllabics. Because SQLite cannot alter an FTS5 tokenizer in place, `search:reindex` recreates the virtual table before repopulating it; upgraded indexes must be fully reindexed.
- **FTS5 `SELECT m.*` misses FTS5 columns**: When joining `search_index` (FTS5) with `search_metadata`, `m.*` only selects metadata columns. To get FTS5 content columns (title, body), select them explicitly: `si.title`, `si.body`. The `snippet()` function also requires column index references into the FTS5 table.
- **FTS5 query escaping must strip special chars**: FTS5 treats `*`, `^`, `{}`, `:`, `"` as operators in addition to `AND/OR/NOT/NEAR`. Quoting terms with `"..."` is not sufficient — strip special characters before quoting to prevent query injection.
- **Access-filtered pagination must share ONE ordered basis for count and fetch** (#1915, R16; #2010, R21): a bounded, ordered first phase selects only IDs, rank, and access/facet metadata, then derives `totalHits`, facets, and the requested access-approved page IDs from that single sequence. A second targeted `IN` query computes titles/snippets only for those page IDs; denied and off-page rows never pay snippet/body materialization cost. The selected ID order is re-applied after fetch so totals, facets, pagination, rank order, and access behavior remain aligned. See `Fts5SearchProviderPaginationAccessFilteredTest` and `Fts5SearchProviderTwoPhaseFetchTest`.
