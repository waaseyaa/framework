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

## Search projection schema

FW-SEARCH-PERSIST-01 (#3146). The projection is five objects: the FTS5 table
`search_index` (with the shadow tables FTS5 creates for it), `search_metadata`,
and the indexes `idx_search_meta_entity_type`, `idx_search_meta_content_type`
and `idx_search_meta_source`. `Fts5SearchSchema` is their one owner.

- **Application database (no `search.database`):** the `waaseyaa/search`
  package migration `2026_09_24_000001_search_projection_schema` owns the
  projection. It runs through the schema coordinator like every package
  migration (`migrate`, `db:init`, `install:init`).
- **Dedicated `search.database` file:** the file is outside schema authority
  and has no manifest. Only `search:reindex` provisions its schema, through
  `Fts5SearchIndexer::removeAll()`, in the same transaction as the row deletes,
  so an interrupted rebuild leaves the file as it was. Its `[SEARCH-DB001]`
  refusal says to move the file aside and reindex. The migration still creates
  the (unused) projection on the application database, so the recorded schema
  does not depend on configuration.
- **A `search.database` that resolves to the application database file** is
  not a dedicated file. The provider shares the application connection, and
  the projection there stays migration-owned.
- **Serving paths perform no DDL.** Lifecycle indexing, removal, batch
  reindexing, search and the content catalogue never create, alter or drop
  schema on any connection. When the projection is absent, writes log a warning
  and do nothing, and reads return no results. On the application database,
  `removeAll()` refuses a missing projection with `[SEARCH-DB002]` and empties
  an existing one with row deletes only.

The migration handles the live state as follows:

| Live state | Result |
| --- | --- |
| An object is absent | Created. The DDL text is the text the pre-migration runtime code used, so a migrated and a runtime-created projection have the same logical schema fingerprint. |
| An object has the expected definition (whitespace-insensitive) | Adopted in place with every row. |
| `search_index` uses the retired `porter unicode61` tokenizer (framework versions before 0.1.0-alpha.263) | Rebuilt with the current tokenizer inside the transition. Every row is carried across and re-tokenized. |
| Any other definition (object names compare case-insensitively, as SQLite does), a `search_index_retired_porter` leftover, or an FTS5 shadow table without `search_index` | Refused with `[SEARCH-DB001]`, naming each difference. The transition rolls back and nothing changes. |

Every statement the migration runs is inside the coordinated transition. A
failure later in the same transition rolls back a created projection or a
tokenizer rebuild completely.

### Adopting a runtime-created search projection

Before the migration existed, the indexer created the projection at runtime,
on the first indexed save or delete. If that happened after the schema
manifest was recorded, `migrate --verify` reports `schema_drift` and every
coordinated transition, including this migration, refuses with `[S1-DB109]`.
For the case where the projection is the only drift, use the S1 spec's
governed re-adoption with these proofs. General adoption tooling belongs to
#3110.

1. Back up the database (for SQLite: `.backup`, then check the backup's
   integrity).
2. Prove the projection is the only drift. On a scratch copy (for example
   `VACUUM INTO`), drop `search_index` and `search_metadata` (dropping them
   removes the shadow tables and indexes too) and run `migrate --verify`
   against the copy. In its `[authority:…]` line, the recorded and live values
   of both `schema=` and `ledger=` must be equal. The kind must be `match`, or
   `source_catalog_mismatch` when the release you're running brings pending
   migrations such as this one; in that case `STATUS` still reads FAIL, which
   is expected. Any other kind, or any unequal pair, means something else
   drifted too: stop and don't re-adopt.
3. Record the row counts of `search_index` and `search_metadata`, then apply
   the governed re-adoption from `docs/specs/s1-schema-authority.md`
   ("Governed re-adoption"), which clears the recorded fingerprints.
4. Run `migrate`. The search migration adopts the projection in place, or
   refuses with `[SEARCH-DB001]` and changes nothing. Confirm `migrate --verify`
   reports `STATUS: OK` and the row counts are unchanged.

If the migration refuses, the projection is rebuildable: after a backup, move
the listed objects aside, re-adopt, run `migrate`, then `search:reindex`.

`packages/search/tests/Integration/SearchProjectionSchemaMigrationTest.php`
runs this procedure on a drifted SQLite database, along with creation, in-place
adoption, the tokenizer rebuild, rollback after the migration's DDL and every
refusal case.
`SearchServingPathSchemaAuthorityTest` proves the serving paths leave the
recorded manifest valid.

## Index contract

SQLite FTS5 uses Unicode word boundaries without English stemming or diacritic
folding. ASCII apostrophe, U+2019, and U+02BC remain token characters so the
index preserves Indigenous orthographies. SQLite cannot alter an FTS5 tokenizer
in place, so a tokenizer change is a new migration that rebuilds `search_index`
inside the transition, as the retired Porter upgrade does.
FTS5 operator characters must be stripped before terms are quoted.
