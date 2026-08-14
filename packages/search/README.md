# waaseyaa/search

**Layer 3 — Services**

Full-text and structured search for Waaseyaa applications.

Provides a search index abstraction and query builder for finding entities
across types. The FTS5 implementation indexes a fixed title/body document,
supports faceting and relevance ranking, preserves Indigenous orthography in
tokenization, and performs batched access-filtered pagination. Entities become
documents either by implementing `SearchIndexableInterface` themselves or —
for ordinary content such as `node` — through the search-owned entity
projection contract (see below). The principal-safe read surface is consumed
by `/api/content/search` and MCP `content.search`.

Key classes: `SearchRequest`, `SearchResult`, `SearchProviderInterface`.

## Principal-safe read surface

The provider, request/result DTOs, and candidate projection contracts are a
public Layer-3 service surface. Every read requires an explicit immutable
`AuthorizationPrincipalInterface`; adapters cannot rely on ambient request
state or omit the actor. The default resolver reloads the canonical served
entity, proves entity `view`, and regenerates its search projection inside the
principal's field-read scope before the candidate can affect a hit, count,
facet, rank, snippet, filter, or page boundary.

FTS5 is only a batched candidate generator. Returned values and aggregates are
derived exclusively from the principal-safe projection. Raw pointers are read
in fixed batches of 200 until the ordered match set is exhausted, so protected
or stale candidates cannot dilute accessible results or make totals, facets,
and pagination silently incomplete. The entity resolver safely truncates
authorized title and body text to 512 characters and 64 KiB respectively.
`SearchResult` deliberately omits server
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

`SearchContentCatalogueInterface` is the companion resource-discovery/read
contract. Its FTS5 implementation scans at most 500 protected pointers and
returns at most 50 principal-safe projections in one deterministic window; it
publishes no raw count, position, cursor, or hidden path. Direct public-path
lookup treats the indexed URL only as a candidate and returns it only when the
canonical principal-safe projection byte-matches that path. Exhaustive
pagination is deferred until the framework owns an AEAD-sealed cursor primitive
(#2220). This is discovery, not a complete inventory: a resource omitted from
the window remains directly readable by canonical URI. More than 50 stale/raw
pointers sharing one public path conservatively makes that path unreadable if
the valid candidate falls outside the direct-read bound.

The Twig helper remains `@internal` because no first-party Twig environment uses
it; even that adapter requires an explicit principal. The write-side indexer
remains live for existing consumers. The unused `SearchIndexJob` was deleted;
asynchronous indexing must wait for a production queue consumer rather than
publishing an undrained message.

## Entity search projection (#2270)

Core content entities cannot depend upward on `waaseyaa/search`, so `node`
never implements `SearchIndexableInterface`. The projection contract closes
that gap from the search side:

- `Projection\EntitySearchProjectorInterface` — `supports()` / `project()`;
  turns a normal entity into an indexable document (`Document\SearchDocument`).
- `Projection\EntitySearchProjectionRegistry` — the **single resolution
  point** used by all four surfaces: `search:reindex`, the save/pointer-move
  lifecycle, lifecycle deletion, and query-time candidate re-projection. A
  self-indexable entity is its own document view; otherwise the first
  supporting projector owns the entity and its result (including a null
  decline) is final. Because every surface resolves through the same
  registry, index-time and query-time semantics cannot drift apart.
- `Projection\NodeSearchProjector` — the built-in default, registered last.
  It keys off the `node` entity type id through the generic entity contract
  (no `Waaseyaa\Node` import, no composer edge, metapackage-clean) and reads
  every value through the guarded accessor: label-key title, the declared
  `body` field, the public `slug` (projected as the root-relative canonical
  URL `/{slug}` after conservative segment validation), the bundle as
  `content_type`, and `created` as `created_at`. Document ids are the stable
  canonical entity form `node:{id}` (`Projection\EntitySearchDocumentId`) —
  the same form the entity candidate resolver parses and the deletion path
  derives without projecting.

**Read-level mechanics are the indexing filter.** Index-time projection runs
without an account scope, so only `FieldReadLevel::Public` fields release
values; a Protected or unclassified (Internal-defaulted) field throws on
read, the default projector omits that field's text, and protected content
never enters the index file at all. At query time the same projector runs
inside the acting principal's field-read scope after the entity `view` check,
so per-principal field grants apply and the re-generated document id must
round-trip the index pointer. A projector may instead let the denial
propagate — the resolver then drops the whole candidate fail-closed (the
strict variant); the default node projector uses per-field omission,
mirroring `ResourceSerializer`. Practical consequence: a bundle's `body`
must be classified `read: public` in its field definition to be searchable;
unclassified bundle fields fail closed to Internal and are silently omitted.

Unpublished content is indexed (the index is a protected datastore; reads are
gated per principal at query time, and a draft resolves to nothing for
principals without view access — indistinguishable from absence). The
lifecycle subscriber still re-sources saves from the served base row, so
forward-draft revisions never enter the index, and deletion removes the
projected document even though the entity is not `SearchIndexableInterface`.

Field text is untrusted CMS data: `Projection\SearchTextNormalizer` drops
script/style/template payloads and comments with their content, strips
remaining markup with word boundaries preserved, decodes entities, scrubs
re-introduced angle brackets, repairs invalid UTF-8, and collapses
whitespace. Search text is data, never agent instructions.

**Application override.** Bind `ProvidesEntitySearchProjectorsInterface` in a
service provider's `register()` (normal container contribution — no config
closures, no service location):

```php
final class AppSearchProvider extends ServiceProvider implements ProvidesEntitySearchProjectorsInterface
{
    public function register(): void
    {
        $this->singleton(ProvidesEntitySearchProjectorsInterface::class, fn() => $this);
    }

    public function entitySearchProjectors(): array
    {
        // Consulted ahead of NodeSearchProjector: supports('node') here
        // replaces the default projection (site URL scheme, extra fields),
        // and project() may return null to keep content out of the index.
        return [new AppNodeSearchProjector()];
    }
}
```

## Protected index trust boundary

Under the [S1 SQLite topology](../../docs/specs/s1-sqlite-topology.md), a
separately configured search database is an optional, non-authoritative,
rebuildable projection. It inherits the authoritative connection's path and
PRAGMA refusal contract. Its file is protected and backed up until a verified
`search:reindex` can deterministically rebuild it; it never becomes content
authority merely because canonical content has not yet been reloaded.

`search_index` and `search_metadata` are a protected, derived datastore, not a
public content cache. An application-supplied search projection can contain
protected titles, bodies, URLs, topics, or other metadata, so the index inherits
the strongest classification of any indexed value. Protect its database file,
backups, replicas, diagnostics, and operator access to the same standard as
canonical entity storage.

Only `Fts5SearchIndexer` writes the raw tables and only the access-checked
`Fts5SearchProvider` and bounded `Fts5SearchContentCatalogue` read them. A repository architecture test enforces that
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
- **Every observable uses one bounded safe basis** (#1915, R16; #2010, R21; #2270): one pointer-only statement selects document ID, entity type, and schema version for at most 1,001 ranked rows, using the final row only as a truncation sentinel. There is no `OFFSET` loop, so concurrent lifecycle writes cannot duplicate or skip candidates between scan statements and work cannot grow quadratically. The resolver canonically reloads and authorizes at most 1,000 candidates; safe projections then determine token matching, filters, rank, sort, totals, facets, pagination, and snippets. When the sentinel is present, `SearchResult::isComplete` is false and exposed by the HTTP API and agent tool; totals, pages, and facets are explicitly lower bounds. See `Fts5SearchProviderPaginationAccessFilteredTest`, `Fts5SearchProviderAccessFilteredCountTest`, and `Fts5SearchProviderTwoPhaseFetchTest`.
