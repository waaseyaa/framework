# SQLite FTS5 Search Provider Design

**Issue:** #508 (design), #507 (implement), #509 (test)
**Milestone:** v1.6 — Search Provider
**Date:** 2026-03-20

## Overview

Deliver the first concrete search backend for Waaseyaa using SQLite FTS5, implementing the existing `SearchProviderInterface` contract. The design is entity-agnostic — any entity type can opt into search by implementing a new `SearchIndexableInterface`. Indexing is synchronous by default with an opt-in async mode for apps with a queue backend.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Indexing scope | Entity-agnostic via `SearchIndexableInterface` | Keeps search decoupled from entity internals; consuming apps control what gets indexed |
| Indexing timing | Hybrid (sync default, async opt-in) | Works out of the box with zero config; apps with heavy writes can switch to async |
| Faceting strategy | Companion metadata table with SQL aggregation | FTS5 has no native faceting; auxiliary queries on a regular table are fast and standard |
| Rebuild strategy | CLI command + schema version tracking | Operators control when rebuilds happen; system warns when index is stale |
| Table architecture | Single shared FTS5 table + one metadata table | Simplest architecture; per-type tables add complexity without clear benefit at this stage |

## Indexable Contract

### SearchIndexableInterface

```php
namespace Waaseyaa\Search;

interface SearchIndexableInterface
{
    /** Unique document ID (e.g., "node:42", "user:7") */
    public function getSearchDocumentId(): string;

    /** Searchable text fields — keys are field names, values are text content */
    public function toSearchDocument(): array;
    // Returns: ['title' => '...', 'body' => '...']

    /** Structured metadata for filtering and faceting */
    public function toSearchMetadata(): array;
    // Returns: ['entity_type' => 'node', 'content_type' => 'article', 'topics' => ['php'], ...]
}
```

Entity classes opt in by implementing this interface. The search provider has no dependency on the entity system — it indexes documents.

### SearchIndexerInterface

```php
namespace Waaseyaa\Search;

interface SearchIndexerInterface
{
    public function index(SearchIndexableInterface $item): void;
    public function remove(string $documentId): void;
    public function removeAll(): void;
    public function getSchemaVersion(): string;
}
```

Write-side contract. The FTS5 implementation manages both the FTS5 virtual table and the companion metadata table.

**FTS5 upsert semantics:** FTS5 virtual tables do not support `INSERT OR REPLACE`. `Fts5SearchIndexer::index()` must delete any existing row with the same `document_id` before inserting the new one. Both the FTS5 delete+insert and the metadata upsert must be wrapped in a single transaction.

## Database Schema

### search_index (FTS5 virtual table)

```sql
CREATE VIRTUAL TABLE search_index USING fts5(
    document_id UNINDEXED,
    title,
    body,
    tokenize='porter unicode61'
);
```

- `document_id` is stored but not searchable — used for joins with metadata
- Porter stemming + unicode61 tokenizer handles multilingual basics
- FTS5 `rank` function provides BM25 relevance scoring

### search_metadata (regular table)

```sql
CREATE TABLE search_metadata (
    document_id TEXT PRIMARY KEY,
    entity_type TEXT NOT NULL,
    content_type TEXT NOT NULL DEFAULT '',
    source_name TEXT NOT NULL DEFAULT '',
    quality_score INTEGER NOT NULL DEFAULT 0,
    topics TEXT NOT NULL DEFAULT '[]',
    url TEXT NOT NULL DEFAULT '',
    og_image TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL,
    schema_version TEXT NOT NULL
);
CREATE INDEX idx_search_meta_entity_type ON search_metadata(entity_type);
CREATE INDEX idx_search_meta_content_type ON search_metadata(content_type);
CREATE INDEX idx_search_meta_source ON search_metadata(source_name);
```

- `topics` stored as JSON array, exploded for faceting via `json_each()`
- `schema_version` per-row enables detecting stale documents after contract changes
- Facet queries: `SELECT entity_type, COUNT(*) FROM search_metadata WHERE document_id IN (...) GROUP BY entity_type`

## Query Execution

`Fts5SearchProvider` implements `SearchProviderInterface::search(SearchRequest): SearchResult`.

### Query flow

1. Parse `SearchRequest::$query` — FTS5 match syntax with automatic phrase quoting for safety
2. Join `search_index` with `search_metadata` for filtering
3. Apply `SearchFilters` as WHERE clauses on metadata columns
4. Paginate with `LIMIT/OFFSET` derived from `$request->page` and `$request->pageSize`
5. Run facet queries on the filtered result set
6. Map rows to `SearchHit` objects, timing the whole operation for `$tookMs`

### Generated SQL (example)

```sql
SELECT m.*, si.rank
FROM search_index si
JOIN search_metadata m ON m.document_id = si.document_id
WHERE search_index MATCH :query
  AND m.content_type = :contentType
  AND m.quality_score >= :minQuality
ORDER BY si.rank
LIMIT :limit OFFSET :offset
```

### Filter mapping

| SearchFilters field | SQL clause |
|---------------------|------------|
| `contentType` | `m.content_type = ?` |
| `topics` | `EXISTS (SELECT 1 FROM json_each(m.topics) WHERE value IN (?...))` |
| `sourceNames` | `m.source_name IN (?...)` |
| `minQuality` | `m.quality_score >= ?` |
| `sortField = 'relevance'` | `ORDER BY si.rank` |
| `sortField` (other) | `ORDER BY m.<field>` with validation against allowed columns |

### Input safety

User queries are passed through FTS5 query escaping — double-quote terms, strip FTS5 operators (`AND`, `OR`, `NOT`, `NEAR`) from raw input to prevent query injection. An opt-in advanced mode could allow raw FTS5 syntax later.

## Indexing Lifecycle

### Synchronous path (default)

An event subscriber listens for entity lifecycle events:

```
waaseyaa.entity.post_save   → if entity implements SearchIndexableInterface → indexer->index($entity)
waaseyaa.entity.post_delete → if entity implements SearchIndexableInterface → indexer->remove($documentId)
```

The system uses a single `POST_SAVE` event for both creates and updates (use `$event->entity->isNew()` to distinguish if needed). Event constants are defined in `EntityEvents`.

The subscriber lives in the search package, registered via service provider. It checks `instanceof SearchIndexableInterface` — entities that don't implement it are ignored with zero overhead.

### Async opt-in

When a queue backend is available, the subscriber dispatches a `SearchIndexJob` message instead of calling the indexer directly. Configuration flag: `search.async: true` in `config/waaseyaa.php`. The job carries the document ID and entity type — it reloads the entity from storage to get fresh data (avoids serializing full entities into the queue).

### Full rebuild

- CLI command: `bin/waaseyaa search:reindex`
- Drops and recreates both tables
- Iterates all registered entity types, loads entities in batches
- Skips entities not implementing `SearchIndexableInterface`
- Sets `schema_version` on every row from the provider's current version
- Progress output: entity type, batch number, total indexed

### Version tracking

- `Fts5SearchIndexer::getSchemaVersion(): string` returns a version derived from the indexable contract (e.g., hash of field names returned by `toSearchDocument()`)
- On `search()`, if any returned document's `schema_version` doesn't match current, log a warning via `error_log()`: `"Search index contains stale documents. Run search:reindex to rebuild."`
- `bin/waaseyaa health:check` includes index staleness in its diagnostic output

## Wiring & Integration

### SearchServiceProvider

- Registers `SearchIndexerInterface` → `Fts5SearchIndexer`
- Registers `SearchProviderInterface` → `Fts5SearchProvider`
- Both receive `DatabaseInterface` via constructor injection
- Registers the entity event subscriber for sync indexing
- Reads `search.async` config to decide sync vs queued subscriber

### Database connection

- Default: uses the application's existing `DatabaseInterface` (same SQLite file, separate tables)
- Optional: `search.database` config key for a dedicated SQLite path — useful if the app uses MySQL/Postgres for entities but wants FTS5 for search

### Twig integration

The existing `SearchTwigExtension` already works — it takes `SearchProviderInterface` and calls `search()`. No changes needed. The FTS5 provider is a drop-in replacement.

### SearchHit mapping

| SearchHit field | Source |
|-----------------|--------|
| `id` | `document_id` |
| `title` | FTS5 `title` column |
| `score` | FTS5 `rank` (normalized) |
| `highlight` | FTS5 `snippet(search_index, 2, '<b>', '</b>', '…', 32)` — excerpts from `body` (column index 2) |
| `url` | metadata `url` column, empty string default |
| `ogImage` | metadata `og_image` column, empty string default |
| `crawledAt` | `created_at` from metadata |
| `contentType` | metadata `content_type` |
| `topics` | metadata `topics` (JSON decoded) |
| `qualityScore` | metadata `quality_score` |
| `sourceName` | metadata `source_name` |

## File Inventory

New files in `packages/search/src/`:

| File | Purpose |
|------|---------|
| `SearchIndexableInterface.php` | Opt-in interface for indexable entities |
| `SearchIndexerInterface.php` | Write-side indexing contract |
| `Fts5SearchProvider.php` | `SearchProviderInterface` implementation — query execution |
| `Fts5SearchIndexer.php` | `SearchIndexerInterface` implementation — FTS5 + metadata writes |
| `SearchIndexSubscriber.php` | Entity event subscriber — sync/async indexing bridge |
| `SearchIndexJob.php` | Queue message for async indexing |
| `SearchServiceProvider.php` | Service provider wiring |

New files in `packages/cli/src/Command/`:

| File | Purpose |
|------|---------|
| `SearchReindexCommand.php` | `search:reindex` CLI command |

## Out of Scope

- Advanced FTS5 query syntax (raw operator passthrough)
- Per-entity-type tokenizer configuration
- Search analytics or query logging
- Auto-rebuild on schema version mismatch (operators run `search:reindex` manually)
- Custom ranking algorithms beyond BM25
