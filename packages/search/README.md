# waaseyaa/search

**Layer 3 — Services**

Full-text and structured search for Waaseyaa applications.

Provides a search index abstraction and query builder for finding entities across types. Supports indexed field selection, faceting, and relevance ranking. Integrates with the API layer for search endpoints.

Key classes: `SearchRequest`, `SearchResult`, `SearchProviderInterface`.

## Parked read surface (R19)

The FTS5 read provider, request/result DTOs, access checker, and Twig helper are
`@internal`: the framework has no first-party HTTP, CLI, SSR, or admin caller.
The write-side indexer remains live for existing consumers. Reactivate the read
surface only when a first-party endpoint adopts it with an acting-account
access boundary and boundary tests covering access-filtered pagination. The
unused `SearchIndexJob` was deleted; asynchronous indexing must wait for a
production queue consumer rather than publishing an undrained message.

## Implementation gotchas

- **FTS5 `SELECT m.*` misses FTS5 columns**: When joining `search_index` (FTS5) with `search_metadata`, `m.*` only selects metadata columns. To get FTS5 content columns (title, body), select them explicitly: `si.title`, `si.body`. The `snippet()` function also requires column index references into the FTS5 table.
- **FTS5 query escaping must strip special chars**: FTS5 treats `*`, `^`, `{}`, `:`, `"` as operators in addition to `AND/OR/NOT/NEAR`. Quoting terms with `"..."` is not sufficient — strip special characters before quoting to prevent query injection.
- **Access-filtered pagination must share ONE ordered basis for count and fetch** (#1915, R16): when an access checker is wired, `Fts5SearchProvider::search()` used to derive `totalHits`/facets from a bounded access-filtered PHP scan but fetch the page from an *independent* unfiltered `ORDER BY ... LIMIT/OFFSET` SQL query. A fixed-size OFFSET then walked the wrong sequence (forbidden rows included), so an accessible document sharing a page window with a forbidden one was silently dropped from every page while `totalPages` promised a page that could never fully materialize. Fixed by `accessFilteredSearch()`: ONE bounded, ordered, access-filtered scan derives `totalHits`, the requested page's rows, and facets together, so paging always agrees with the total it's measured against. See `Fts5SearchProviderPaginationAccessFilteredTest`.
