# waaseyaa/search

**Layer 3 — Services**

Full-text and structured search for Waaseyaa applications.

Provides a search index abstraction and query builder for finding entities
across types. The FTS5 implementation indexes a fixed title/body document,
supports faceting and relevance ranking, preserves Indigenous orthography in
tokenization, and performs bounded access-filtered pagination. No first-party
API endpoint currently calls this read surface.

Key classes: `SearchRequest`, `SearchResult`, `SearchProviderInterface`.

## Principal-safe read surface

The provider, request/result DTOs, and candidate projection contracts are a
public Layer-3 service surface. Every read requires an explicit immutable
`AuthorizationPrincipalInterface`; adapters cannot rely on ambient request
state or omit the actor. The default resolver reloads the canonical served
entity, proves entity `view`, and regenerates its search projection inside the
principal's field-read scope before the candidate can affect a hit, count,
facet, rank, snippet, filter, or page boundary.

FTS5 is only a bounded candidate generator. Returned values and aggregates are
derived exclusively from the principal-safe projection. A request resolves at
most 200 candidates; the entity resolver safely truncates authorized title
and body text to 512 characters and 64 KiB respectively. If
inaccessible or non-matching canonical projections consume that window,
results conservatively under-count; the provider never exposes a raw count,
`hasMore`, or cap-exhaustion flag. `SearchResult` deliberately omits server
duration so the API does not amplify protected-index cardinality as response
data. Ordinary end-to-end latency remains a weak residual channel and must not
be logged or exposed at per-query granularity on a public adapter.

Non-entity content is default-denied. Applications may contribute a resolver
for an exact document-id namespace; that resolver must load its own canonical
source and return a principal-safe projection. Source namespaces cannot collide
with registered entity type IDs, including after registry construction. Entity
projections normalize and bound authorized strings, topics, and quality scores
so benign source variance cannot make otherwise viewable content disappear. The indexed `entity_type`,
`source_name`, or other metadata never grants publicness.

A malformed index pointer or thrown application projection failure drops only
that candidate and emits a content-free warning; it cannot fail the whole query
or place exception messages, document IDs, or raw index values in logs.
Resolvers return `null` for ordinary access denial and deliberately malformed
shapes without logging, so logs cannot become an authorization oracle.

The Twig helper remains `@internal` because no first-party Twig environment uses
it; even that adapter requires an explicit principal. The write-side indexer
remains live for existing consumers. The unused `SearchIndexJob` was deleted;
asynchronous indexing must wait for a production queue consumer rather than
publishing an undrained message.

## Protected index trust boundary

`search_index` and `search_metadata` are a protected, derived datastore, not a
public content cache. An application-supplied search projection can contain
protected titles, bodies, URLs, topics, or other metadata, so the index inherits
the strongest classification of any indexed value. Protect its database file,
backups, replicas, diagnostics, and operator access to the same standard as
canonical entity storage.

Only `Fts5SearchIndexer` writes the raw tables and only the access-checked
`Fts5SearchProvider` reads them. A repository architecture test enforces that
inventory across first-party production PHP. Alternate providers,
autocomplete/suggestion features, diagnostics, and ad-hoc readers are
prohibited from querying the tables directly; they must enter through the
access-checked search contract. The static inventory
cannot recognize a deliberately obscured dynamic table name or a non-PHP
operator script, so code review remains responsible for those evasions. Do not
log raw candidates or pre-access counts. Search result caches must be scoped to
the immutable acting principal and its claims generation; a shared result cache
is a cross-principal disclosure.

The provider cannot be constructed without a candidate resolver. Unknown entity
types and non-entity sources are denied by default; public files or synchronized
corpora require an explicit, exact-namespace source resolver. Indexed metadata
is never authority for declaring a source public.

## Implementation gotchas

- **Indigenous orthography is the tokenizer acceptance bar (#2010, R21):** `search_index` uses `tokenize="unicode61 remove_diacritics 0 tokenchars '''’ʼ'"` — Unicode word boundaries, no English Porter stemmer, no diacritic folding, and ASCII apostrophe/U+2019/U+02BC retained inside tokens. Round-trip coverage pins Anishinaabemowin double vowels, apostrophe/glottal forms, macrons/acute diacritics, and Canadian syllabics. Because SQLite cannot alter an FTS5 tokenizer in place, `search:reindex` recreates the virtual table before repopulating it; upgraded indexes must be fully reindexed.
- **FTS5 `SELECT m.*` misses FTS5 columns**: When joining `search_index` (FTS5) with `search_metadata`, `m.*` only selects metadata columns. To get FTS5 content columns (title, body), select them explicitly: `si.title`, `si.body`. The `snippet()` function also requires column index references into the FTS5 table.
- **FTS5 query syntax is never accepted from callers**: the provider extracts Unicode word/apostrophe tokens from plain text and submits every term individually quoted, including literal words such as `and`, `or`, `not`, and `near`. Punctuation such as `full-text`, `node.js`, and `C++` becomes the same token sequence used by SQLite's tokenizer; operators and wildcards cannot enter the query grammar.
- **Every observable uses one safe bounded basis** (#1915, R16; #2010, R21): a pointer-only raw scan selects document ID, entity type, schema version, and raw rank for no more than 200 candidates. The resolver canonically reloads and authorizes each candidate; safe projections then determine token matching, filters, rank, sort, totals, facets, pagination, and snippets. Alternate sort fields operate within this conservative candidate window, not across the entire protected index. See `Fts5SearchProviderPaginationAccessFilteredTest`, `Fts5SearchProviderAccessFilteredCountTest`, and `Fts5SearchProviderTwoPhaseFetchTest`.
