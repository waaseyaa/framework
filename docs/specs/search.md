# Search

<!-- Spec reviewed 2026-09-05 - #2636: SearchContentCatalogueInterface.list()
returns SearchCataloguePage with optional SearchCatalogueScanPosition resume;
FTS5 still bounds each page to 500 scanned / 50 visible. Wire pagination and
AEAD cursors live in mcp-endpoint.md / packages/search/README.md — this file
keeps only cross-cutting invariants. -->

Canonical contract documentation lives in [`packages/search/README.md`](../../packages/search/README.md)
(the orchestration table's spec target for `packages/search/*`). This file
keeps only the cross-cutting invariants.

## Scope

`waaseyaa/search` is a Layer 3 service package for full-text and structured
entity search. The principal-safe read surface is consumed by the published
`/api/content/search` endpoint and MCP `content.search` (#2268). The
write-side indexer serves both self-indexable entities and — since #2270 —
ordinary content entities through the search-owned entity projection
contract (`Projection\EntitySearchProjectorInterface`, resolved through one
shared `Projection\EntitySearchProjectionRegistry`).

## Entity projection invariant (#2270)

Full `search:reindex`, the save/delete/revision-pointer lifecycle, and
query-time candidate resolution all resolve entities through the same
projection registry — never through divergent per-surface logic. The
built-in `NodeSearchProjector` keys off the `node` entity type id via the
generic entity contract (no `Waaseyaa\Node` import; search must not gain a
composer edge to `waaseyaa/node` — they sit in different metapackages).
Projection reads only guarded field accessors, so index-time projection
(unscoped) can only capture Public-classified fields, and query-time
re-projection runs inside the acting principal's field-read scope after the
entity `view` check. Applications contribute or override projectors by
binding `ProvidesEntitySearchProjectorsInterface`; app projectors precede
the built-in default and a supporting projector's null decline is final.

## Read-surface access boundary

Count, facets, page selection, and rank order must share one ordered pointer
basis fetched by a single bounded statement. The provider projects at most
1,000 candidates and uses one extra pointer as a truncation sentinel; when it
is present, every public adapter must expose `isComplete: false` and treat the
reported totals, pages, and facets as lower bounds. Filters and non-relevance
sorts apply only to principal-safe matches inside that raw top-1,000 relevance
window; they do not widen or reorder the candidate scan itself. Completeness is
the raw pointer-window signal and is determined before authorization, so an
all-denied or fully filtered window may correctly return no visible data with
`isComplete: false`. That flag does not identify or count denied candidates.
Titles and snippets derive only from principal-safe canonical projections (see the README's
"Principal-safe read surface"). Asynchronous
indexing still requires a production queue consumer before a job is
introduced.

## S1 projection storage topology

The optional SQLite search projection is rebuildable, but its connection still
uses the canonical environment-aware S1 topology authority. Its configured
path is validated before connection and relative paths resolve against the
application project root, never process CWD. Production, staging, and unknown
environments reject in-memory projection databases; only the explicit
local/development/testing allowlist may use them. Search may not open SQLite
through an environment-blind DBAL, PDO, or SQLite3 construction path.

## Index contract

SQLite FTS5 uses Unicode word boundaries without English stemming or diacritic
folding. ASCII apostrophe, U+2019, and U+02BC remain token characters so the
index preserves Indigenous orthographies. Changing the tokenizer requires a
full `search:reindex` because SQLite cannot alter an FTS5 tokenizer in place.
FTS5 operator characters must be stripped before terms are quoted.
