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

- **Indigenous orthography is the tokenizer acceptance bar (#2010, R21):** `search_index` uses `tokenize="unicode61 remove_diacritics 0 tokenchars '''’ʼ'"` — Unicode word boundaries, no English Porter stemmer, no diacritic folding, and ASCII apostrophe/U+2019/U+02BC retained inside tokens. Round-trip coverage pins Anishinaabemowin double vowels, apostrophe/glottal forms, macrons/acute diacritics, and Canadian syllabics. Because SQLite cannot alter an FTS5 tokenizer in place, `search:reindex` recreates the virtual table before repopulating it; upgraded indexes must be fully reindexed.
- **FTS5 `SELECT m.*` misses FTS5 columns**: When joining `search_index` (FTS5) with `search_metadata`, `m.*` only selects metadata columns. To get FTS5 content columns (title, body), select them explicitly: `si.title`, `si.body`. The `snippet()` function also requires column index references into the FTS5 table.
- **FTS5 query escaping must strip special chars**: FTS5 treats `*`, `^`, `{}`, `:`, `"` as operators in addition to `AND/OR/NOT/NEAR`. Quoting terms with `"..."` is not sufficient — strip special characters before quoting to prevent query injection.
- **Access-filtered pagination must share ONE ordered basis for count and fetch** (#1915, R16; #2010, R21): a bounded, ordered first phase selects only IDs, rank, and access/facet metadata, then derives `totalHits`, facets, and the requested access-approved page IDs from that single sequence. A second targeted `IN` query computes titles/snippets only for those page IDs; denied and off-page rows never pay snippet/body materialization cost. The selected ID order is re-applied after fetch so totals, facets, pagination, rank order, and access behavior remain aligned. See `Fts5SearchProviderPaginationAccessFilteredTest` and `Fts5SearchProviderTwoPhaseFetchTest`.
