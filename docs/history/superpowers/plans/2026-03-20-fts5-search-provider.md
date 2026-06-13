# SQLite FTS5 Search Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement a concrete `SearchProviderInterface` backed by SQLite FTS5 with entity-agnostic indexing, faceted search, and a CLI reindex command.

**Architecture:** Entity classes opt into search by implementing `SearchIndexableInterface`. An event subscriber indexes documents on entity save/delete (sync by default, async opt-in). A companion metadata table enables filtering and faceting. A CLI command rebuilds the full index.

**Tech Stack:** PHP 8.3+, SQLite FTS5 (porter unicode61 tokenizer), Symfony Console/EventDispatcher, PHPUnit 10.5

**Spec:** `docs/history/superpowers/specs/2026-03-20-fts5-search-provider-design.md`

**Issues:** #507 (implement), #508 (design), #509 (test)

---

## File Structure

| File | Responsibility |
|------|----------------|
| `packages/search/src/SearchIndexableInterface.php` | Opt-in interface for indexable entities |
| `packages/search/src/SearchIndexerInterface.php` | Write-side indexing contract |
| `packages/search/src/Fts5/Fts5SearchIndexer.php` | FTS5 + metadata table writes, schema creation, upsert |
| `packages/search/src/Fts5/Fts5SearchProvider.php` | Query execution, filter mapping, facet aggregation |
| `packages/search/src/EventSubscriber/SearchIndexSubscriber.php` | Entity event listener, sync/async bridge |
| `packages/search/src/SearchIndexJob.php` | Queue message for async indexing |
| `packages/search/src/SearchServiceProvider.php` | Service provider wiring |
| `packages/cli/src/Command/SearchReindexCommand.php` | `search:reindex` CLI command |
| `packages/search/tests/Unit/SearchIndexableInterfaceTest.php` | Contract tests for indexable interface |
| `packages/search/tests/Unit/Fts5SearchIndexerTest.php` | Indexer unit tests |
| `packages/search/tests/Unit/Fts5SearchProviderTest.php` | Provider unit tests |
| `packages/search/tests/Unit/SearchIndexSubscriberTest.php` | Subscriber unit tests |
| `tests/Integration/Search/Fts5SearchIntegrationTest.php` | Full-stack integration tests |

---

### Task 1: SearchIndexableInterface and SearchIndexerInterface

**Files:**
- Create: `packages/search/src/SearchIndexableInterface.php`
- Create: `packages/search/src/SearchIndexerInterface.php`
- Create: `packages/search/tests/Unit/SearchIndexableInterfaceTest.php`

- [ ] **Step 1: Write test for SearchIndexableInterface contract**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\SearchIndexableInterface;

#[CoversNothing]
final class SearchIndexableInterfaceTest extends TestCase
{
    #[Test]
    public function implementor_returns_document_id(): void
    {
        $item = $this->createIndexable('node:42', ['title' => 'Hello'], ['entity_type' => 'node']);

        $this->assertSame('node:42', $item->getSearchDocumentId());
    }

    #[Test]
    public function implementor_returns_search_document(): void
    {
        $item = $this->createIndexable('node:42', ['title' => 'Hello', 'body' => 'World'], []);

        $this->assertSame(['title' => 'Hello', 'body' => 'World'], $item->toSearchDocument());
    }

    #[Test]
    public function implementor_returns_search_metadata(): void
    {
        $metadata = ['entity_type' => 'node', 'content_type' => 'article', 'topics' => ['php']];
        $item = $this->createIndexable('node:42', [], $metadata);

        $this->assertSame($metadata, $item->toSearchMetadata());
    }

    private function createIndexable(string $id, array $document, array $metadata): SearchIndexableInterface
    {
        return new class ($id, $document, $metadata) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly array $document,
                private readonly array $metadata,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return $this->document; }
            public function toSearchMetadata(): array { return $this->metadata; }
        };
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/SearchIndexableInterfaceTest.php`
Expected: FAIL — `SearchIndexableInterface` not found

- [ ] **Step 3: Create SearchIndexableInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

interface SearchIndexableInterface
{
    /**
     * Unique document ID across all entity types (e.g., "node:42", "user:7").
     */
    public function getSearchDocumentId(): string;

    /**
     * Searchable text fields — keys are field names, values are text content.
     *
     * @return array<string, string> e.g. ['title' => '...', 'body' => '...']
     */
    public function toSearchDocument(): array;

    /**
     * Structured metadata for filtering and faceting.
     *
     * @return array<string, mixed> e.g. ['entity_type' => 'node', 'topics' => ['php']]
     */
    public function toSearchMetadata(): array;
}
```

- [ ] **Step 4: Create SearchIndexerInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

interface SearchIndexerInterface
{
    /**
     * Index a single item. Upserts — replaces existing document with same ID.
     */
    public function index(SearchIndexableInterface $item): void;

    /**
     * Remove a single document by ID.
     */
    public function remove(string $documentId): void;

    /**
     * Remove all documents from the index.
     */
    public function removeAll(): void;

    /**
     * Current schema version. Changes when the indexable contract evolves.
     */
    public function getSchemaVersion(): string;
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/SearchIndexableInterfaceTest.php`
Expected: 3 tests, 3 assertions, PASS

- [ ] **Step 6: Commit**

```bash
git add packages/search/src/SearchIndexableInterface.php packages/search/src/SearchIndexerInterface.php packages/search/tests/Unit/SearchIndexableInterfaceTest.php
git commit -m "feat(#507): add SearchIndexableInterface and SearchIndexerInterface contracts"
```

---

### Task 2: Fts5SearchIndexer — Schema and Indexing

**Files:**
- Create: `packages/search/src/Fts5/Fts5SearchIndexer.php`
- Create: `packages/search/tests/Unit/Fts5SearchIndexerTest.php`

**Reference:** `packages/database-legacy/src/DatabaseInterface.php` for query API, `DBALDatabase::createSqlite()` for test setup.

- [ ] **Step 1: Write failing tests for Fts5SearchIndexer**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\SearchIndexableInterface;

#[CoversClass(Fts5SearchIndexer::class)]
final class Fts5SearchIndexerTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();
    }

    #[Test]
    public function it_creates_fts5_and_metadata_tables(): void
    {
        // Tables created in setUp — verify they exist by querying
        $rows = iterator_to_array($this->database->query("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('search_index', 'search_metadata') ORDER BY name"));

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function it_indexes_a_document(): void
    {
        $item = $this->createIndexable('node:1', ['title' => 'Hello World', 'body' => 'Test content'], [
            'entity_type' => 'node',
            'content_type' => 'article',
            'source_name' => '',
            'quality_score' => 80,
            'topics' => ['php', 'testing'],
            'url' => '/node/1',
            'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $this->indexer->index($item);

        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(1, $rows);
        $this->assertSame('node', $rows[0]['entity_type']);
    }

    #[Test]
    public function it_upserts_existing_document(): void
    {
        $item1 = $this->createIndexable('node:1', ['title' => 'Original', 'body' => 'First'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => '',
            'quality_score' => 50, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $item2 = $this->createIndexable('node:1', ['title' => 'Updated', 'body' => 'Second'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => '',
            'quality_score' => 90, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $this->indexer->index($item1);
        $this->indexer->index($item2);

        // Should have one row, not two
        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(1, $rows);
        $this->assertSame(90, (int) $rows[0]['quality_score']);
    }

    #[Test]
    public function it_removes_a_document(): void
    {
        $item = $this->createIndexable('node:1', ['title' => 'Hello', 'body' => 'World'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexer->index($item);
        $this->indexer->remove('node:1');

        $rows = iterator_to_array($this->database->query("SELECT * FROM search_metadata WHERE document_id = 'node:1'"));
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function it_removes_all_documents(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->indexer->index($this->createIndexable("node:$i", ['title' => "T$i", 'body' => "B$i"], [
                'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
                'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
                'created_at' => '2026-03-20T00:00:00Z',
            ]));
        }

        $this->indexer->removeAll();

        $rows = iterator_to_array($this->database->query("SELECT COUNT(*) as cnt FROM search_metadata"));
        $this->assertSame(0, (int) $rows[0]['cnt']);
    }

    #[Test]
    public function it_returns_schema_version(): void
    {
        $version = $this->indexer->getSchemaVersion();

        $this->assertNotEmpty($version);
        $this->assertIsString($version);
    }

    private function createIndexable(string $id, array $document, array $metadata): SearchIndexableInterface
    {
        return new class ($id, $document, $metadata) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly array $document,
                private readonly array $metadata,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return $this->document; }
            public function toSearchMetadata(): array { return $this->metadata; }
        };
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/Fts5SearchIndexerTest.php`
Expected: FAIL — `Fts5SearchIndexer` class not found

- [ ] **Step 3: Implement Fts5SearchIndexer**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

final class Fts5SearchIndexer implements SearchIndexerInterface
{
    private const SCHEMA_VERSION = '1';

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public function ensureSchema(): void
    {
        $this->database->query(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                document_id UNINDEXED,
                title,
                body,
                tokenize='porter unicode61'
            )
        SQL);

        $this->database->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS search_metadata (
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
            )
        SQL);

        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_entity_type ON search_metadata(entity_type)');
        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_content_type ON search_metadata(content_type)');
        $this->database->query('CREATE INDEX IF NOT EXISTS idx_search_meta_source ON search_metadata(source_name)');
    }

    public function index(SearchIndexableInterface $item): void
    {
        $documentId = $item->getSearchDocumentId();
        $document = $item->toSearchDocument();
        $metadata = $item->toSearchMetadata();

        $tx = $this->database->transaction('search_index');
        $tx->start();

        try {
            // FTS5 does not support INSERT OR REPLACE — delete first
            $this->deleteDocument($documentId);

            $this->database->query(
                'INSERT INTO search_index (document_id, title, body) VALUES (?, ?, ?)',
                [$documentId, $document['title'] ?? '', $document['body'] ?? '']
            );

            $this->database->insert('search_metadata')
                ->values([
                    'document_id' => $documentId,
                    'entity_type' => $metadata['entity_type'] ?? '',
                    'content_type' => $metadata['content_type'] ?? '',
                    'source_name' => $metadata['source_name'] ?? '',
                    'quality_score' => $metadata['quality_score'] ?? 0,
                    'topics' => json_encode($metadata['topics'] ?? [], JSON_THROW_ON_ERROR),
                    'url' => $metadata['url'] ?? '',
                    'og_image' => $metadata['og_image'] ?? '',
                    'created_at' => $metadata['created_at'] ?? date('c'),
                    'schema_version' => self::SCHEMA_VERSION,
                ])
                ->execute();

            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function remove(string $documentId): void
    {
        $tx = $this->database->transaction('search_remove');
        $tx->start();

        try {
            $this->deleteDocument($documentId);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function removeAll(): void
    {
        $tx = $this->database->transaction('search_clear');
        $tx->start();

        try {
            $this->database->query("DELETE FROM search_index");
            $this->database->delete('search_metadata')->execute();
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollback();
            throw $e;
        }
    }

    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    private function deleteDocument(string $documentId): void
    {
        $this->database->query('DELETE FROM search_index WHERE document_id = ?', [$documentId]);
        $this->database->delete('search_metadata')
            ->condition('document_id', $documentId)
            ->execute();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/Fts5SearchIndexerTest.php`
Expected: 6 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add packages/search/src/Fts5/Fts5SearchIndexer.php packages/search/tests/Unit/Fts5SearchIndexerTest.php
git commit -m "feat(#507): implement Fts5SearchIndexer with schema, upsert, and removal"
```

---

### Task 3: Fts5SearchProvider — Query Execution

**Files:**
- Create: `packages/search/src/Fts5/Fts5SearchProvider.php`
- Create: `packages/search/tests/Unit/Fts5SearchProviderTest.php`

**Reference:** `packages/search/src/SearchProviderInterface.php`, `SearchRequest.php`, `SearchResult.php`, `SearchHit.php`, `SearchFilters.php`, `SearchFacet.php`, `FacetBucket.php`

- [ ] **Step 1: Write failing tests for Fts5SearchProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();
        $this->provider = new Fts5SearchProvider($this->database, $this->indexer);
    }

    #[Test]
    public function it_returns_empty_result_for_no_matches(): void
    {
        $result = $this->provider->search(new SearchRequest('nonexistent'));

        $this->assertSame(0, $result->totalHits);
        $this->assertSame([], $result->hits);
    }

    #[Test]
    public function it_finds_indexed_documents(): void
    {
        $this->indexItem('node:1', ['title' => 'PHP Testing Guide', 'body' => 'Learn unit testing with PHPUnit'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'blog',
            'quality_score' => 80, 'topics' => ['php', 'testing'], 'url' => '/node/1',
            'og_image' => '', 'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('PHP'));

        $this->assertSame(1, $result->totalHits);
        $this->assertSame('node:1', $result->hits[0]->id);
        $this->assertSame('PHP Testing Guide', $result->hits[0]->title);
    }

    #[Test]
    public function it_filters_by_content_type(): void
    {
        $this->indexItem('node:1', ['title' => 'Article', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Page Content', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => 'page', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('Content', new SearchFilters(contentType: 'article')));

        $this->assertSame(1, $result->totalHits);
        $this->assertSame('node:1', $result->hits[0]->id);
    }

    #[Test]
    public function it_filters_by_minimum_quality(): void
    {
        $this->indexItem('node:1', ['title' => 'Low quality', 'body' => 'Test'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 20, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'High quality', 'body' => 'Test'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 90, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('Test', new SearchFilters(minQuality: 50)));

        $this->assertSame(1, $result->totalHits);
        $this->assertSame('node:2', $result->hits[0]->id);
    }

    #[Test]
    public function it_filters_by_topics(): void
    {
        $this->indexItem('node:1', ['title' => 'PHP Post', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => ['php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Go Post', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => ['go'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('Post', new SearchFilters(topics: ['php'])));

        $this->assertSame(1, $result->totalHits);
        $this->assertSame('node:1', $result->hits[0]->id);
    }

    #[Test]
    public function it_returns_facets(): void
    {
        $this->indexItem('node:1', ['title' => 'Article One', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'blog',
            'quality_score' => 0, 'topics' => ['php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Article Two', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'docs',
            'quality_score' => 0, 'topics' => ['php', 'testing'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('Content'));

        $contentTypeFacet = $result->getFacet('content_type');
        $this->assertNotNull($contentTypeFacet);
        $this->assertCount(1, $contentTypeFacet->buckets); // both are 'article'
        $this->assertSame('article', $contentTypeFacet->buckets[0]->key);
        $this->assertSame(2, $contentTypeFacet->buckets[0]->count);

        $topicsFacet = $result->getFacet('topics');
        $this->assertNotNull($topicsFacet);
        $this->assertGreaterThanOrEqual(1, count($topicsFacet->buckets));
    }

    #[Test]
    public function it_paginates_results(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->indexItem("node:$i", ['title' => "Item $i", 'body' => 'Searchable content'], [
                'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
                'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
                'created_at' => '2026-03-20T00:00:00Z',
            ]);
        }

        $result = $this->provider->search(new SearchRequest('Searchable', pageSize: 2));

        $this->assertSame(5, $result->totalHits);
        $this->assertSame(3, $result->totalPages);
        $this->assertSame(1, $result->currentPage);
        $this->assertCount(2, $result->hits);
    }

    #[Test]
    public function it_escapes_fts5_operators_in_query(): void
    {
        $this->indexItem('node:1', ['title' => 'AND OR NOT test', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        // Should not throw — operators are escaped
        $result = $this->provider->search(new SearchRequest('AND OR NOT'));

        $this->assertInstanceOf(\Waaseyaa\Search\SearchResult::class, $result);
    }

    private function indexItem(string $id, array $document, array $metadata): void
    {
        $this->indexer->index(new class ($id, $document, $metadata) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly array $document,
                private readonly array $metadata,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return $this->document; }
            public function toSearchMetadata(): array { return $this->metadata; }
        });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/Fts5SearchProviderTest.php`
Expected: FAIL — `Fts5SearchProvider` class not found

- [ ] **Step 3: Implement Fts5SearchProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Fts5;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchIndexerInterface;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Search\SearchRequest;
use Waaseyaa\Search\SearchResult;

final class Fts5SearchProvider implements SearchProviderInterface
{
    private const ALLOWED_SORT_COLUMNS = ['created_at', 'quality_score', 'entity_type', 'content_type'];

    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly SearchIndexerInterface $indexer,
    ) {}

    public function search(SearchRequest $request): SearchResult
    {
        $startTime = hrtime(true);

        $query = $this->escapeQuery($request->query);
        if ($query === '') {
            return SearchResult::empty();
        }

        $params = [];
        $whereClauses = ['search_index MATCH :query'];
        $params['query'] = $query;

        $this->applyFilters($request->filters, $whereClauses, $params);

        $whereSQL = implode(' AND ', $whereClauses);

        // Count total hits
        $countSQL = "SELECT COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL";
        $countRows = iterator_to_array($this->database->query($countSQL, $params));
        $totalHits = (int) ($countRows[0]['cnt'] ?? 0);

        if ($totalHits === 0) {
            $tookMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            return new SearchResult(
                totalHits: 0,
                totalPages: 0,
                currentPage: $request->page,
                pageSize: $request->pageSize,
                tookMs: $tookMs,
                hits: [],
            );
        }

        $totalPages = (int) ceil($totalHits / $request->pageSize);
        $offset = ($request->page - 1) * $request->pageSize;

        // Sort
        $orderBy = 'si.rank';
        if ($request->filters->sortField !== 'relevance' && in_array($request->filters->sortField, self::ALLOWED_SORT_COLUMNS, true)) {
            $direction = strtoupper($request->filters->sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = "m.{$request->filters->sortField} $direction";
        }

        // Fetch page
        $sql = "SELECT m.*, snippet(search_index, 2, '<b>', '</b>', '…', 32) as highlight, si.rank FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL ORDER BY $orderBy LIMIT :limit OFFSET :offset";
        $params['limit'] = $request->pageSize;
        $params['offset'] = $offset;

        $hits = [];
        $staleDetected = false;
        $currentVersion = $this->indexer->getSchemaVersion();

        foreach ($this->database->query($sql, $params) as $row) {
            if ($row['schema_version'] !== $currentVersion) {
                $staleDetected = true;
            }

            $topics = json_decode($row['topics'], true, 512, JSON_THROW_ON_ERROR);

            $hits[] = new SearchHit(
                id: $row['document_id'],
                title: $row['title'] ?? '',
                url: $row['url'] ?? '',
                sourceName: $row['source_name'] ?? '',
                crawledAt: $row['created_at'] ?? '',
                qualityScore: (int) ($row['quality_score'] ?? 0),
                contentType: $row['content_type'] ?? '',
                topics: $topics,
                score: abs((float) ($row['rank'] ?? 0.0)),
                ogImage: $row['og_image'] ?? '',
                highlight: $row['highlight'] ?? '',
            );
        }

        if ($staleDetected) {
            error_log('Search index contains stale documents. Run search:reindex to rebuild.');
        }

        // Facets — run on the full filtered result set (not just the page)
        $facets = $this->buildFacets($whereSQL, $params, $request);

        $tookMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        return new SearchResult(
            totalHits: $totalHits,
            totalPages: $totalPages,
            currentPage: $request->page,
            pageSize: $request->pageSize,
            tookMs: $tookMs,
            hits: $hits,
            facets: $facets,
        );
    }

    private function escapeQuery(string $query): string
    {
        // Remove FTS5 operators to prevent query injection
        $query = preg_replace('/\b(AND|OR|NOT|NEAR)\b/i', '', $query);
        $query = trim(preg_replace('/\s+/', ' ', $query));

        if ($query === '') {
            return '';
        }

        // Quote individual terms for safety
        $terms = explode(' ', $query);
        $quoted = array_map(fn(string $term): string => '"' . str_replace('"', '""', $term) . '"', $terms);

        return implode(' ', $quoted);
    }

    private function applyFilters(SearchFilters $filters, array &$whereClauses, array &$params): void
    {
        if ($filters->contentType !== '') {
            $whereClauses[] = 'm.content_type = :contentType';
            $params['contentType'] = $filters->contentType;
        }

        if ($filters->minQuality > 0) {
            $whereClauses[] = 'm.quality_score >= :minQuality';
            $params['minQuality'] = $filters->minQuality;
        }

        if ($filters->sourceNames !== []) {
            $placeholders = [];
            foreach ($filters->sourceNames as $i => $name) {
                $key = "source_$i";
                $placeholders[] = ":$key";
                $params[$key] = $name;
            }
            $whereClauses[] = 'm.source_name IN (' . implode(', ', $placeholders) . ')';
        }

        if ($filters->topics !== []) {
            $placeholders = [];
            foreach ($filters->topics as $i => $topic) {
                $key = "topic_$i";
                $placeholders[] = ":$key";
                $params[$key] = $topic;
            }
            $whereClauses[] = 'EXISTS (SELECT 1 FROM json_each(m.topics) WHERE value IN (' . implode(', ', $placeholders) . '))';
        }
    }

    /**
     * @return SearchFacet[]
     */
    private function buildFacets(string $whereSQL, array $params, SearchRequest $request): array
    {
        // Remove pagination params for facet queries
        unset($params['limit'], $params['offset']);

        $facets = [];

        // Content type facet
        $sql = "SELECT m.content_type as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL AND m.content_type != '' GROUP BY m.content_type ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('content_type', $buckets);
        }

        // Source name facet
        $sql = "SELECT m.source_name as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id WHERE $whereSQL AND m.source_name != '' GROUP BY m.source_name ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('source_name', $buckets);
        }

        // Topics facet
        $sql = "SELECT je.value as facet_key, COUNT(*) as cnt FROM search_index si JOIN search_metadata m ON m.document_id = si.document_id, json_each(m.topics) je WHERE $whereSQL GROUP BY je.value ORDER BY cnt DESC";
        $buckets = [];
        foreach ($this->database->query($sql, $params) as $row) {
            $buckets[] = new FacetBucket($row['facet_key'], (int) $row['cnt']);
        }
        if ($buckets !== []) {
            $facets[] = new SearchFacet('topics', $buckets);
        }

        return $facets;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/Fts5SearchProviderTest.php`
Expected: 8 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add packages/search/src/Fts5/Fts5SearchProvider.php packages/search/tests/Unit/Fts5SearchProviderTest.php
git commit -m "feat(#507): implement Fts5SearchProvider with filtering, faceting, and pagination"
```

---

### Task 4: SearchIndexSubscriber — Entity Event Listener

**Files:**
- Create: `packages/search/src/EventSubscriber/SearchIndexSubscriber.php`
- Create: `packages/search/tests/Unit/SearchIndexSubscriberTest.php`

**Reference:** `packages/entity/src/Audit/EntityWriteAuditListener.php` for subscriber pattern, `packages/entity/src/Event/EntityEvents.php` for event constants, `packages/entity/src/Event/EntityEvent.php` for event object.

- [ ] **Step 1: Write failing tests for SearchIndexSubscriber**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

#[CoversClass(SearchIndexSubscriber::class)]
final class SearchIndexSubscriberTest extends TestCase
{
    #[Test]
    public function it_subscribes_to_post_save_and_post_delete(): void
    {
        $events = SearchIndexSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(EntityEvents::POST_SAVE->value, $events);
        $this->assertArrayHasKey(EntityEvents::POST_DELETE->value, $events);
    }

    #[Test]
    public function it_indexes_on_post_save_when_entity_is_indexable(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('index')->with($entity);

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostSave($event);
    }

    #[Test]
    public function it_skips_non_indexable_entities_on_save(): void
    {
        $entity = new class (['id' => 1]) extends \Waaseyaa\Entity\EntityBase {
            public function __construct(array $values) {
                parent::__construct($values, 'dummy', ['id' => 'id']);
            }
        };
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->never())->method('index');

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostSave($event);
    }

    #[Test]
    public function it_removes_on_post_delete_when_entity_is_indexable(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->expects($this->once())->method('remove')->with('node:1');

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);
        $subscriber->onPostDelete($event);
    }

    #[Test]
    public function it_catches_indexing_errors_without_crashing(): void
    {
        $entity = $this->createIndexableEntity('node:1');
        $indexer = $this->createMockIndexer();
        $indexer->method('index')->willThrowException(new \RuntimeException('DB error'));

        $subscriber = new SearchIndexSubscriber($indexer);
        $event = new EntityEvent($entity);

        // Should not throw — best-effort side effect
        $subscriber->onPostSave($event);
        $this->assertTrue(true); // No exception = pass
    }

    private function createMockIndexer(): SearchIndexerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(SearchIndexerInterface::class);
    }

    private function createIndexableEntity(string $docId): SearchIndexableInterface&\Waaseyaa\Entity\EntityInterface
    {
        return new class ($docId) extends \Waaseyaa\Entity\EntityBase implements SearchIndexableInterface {
            private string $docId;

            public function __construct(string $docId) {
                parent::__construct(['id' => 1], 'node', ['id' => 'id']);
                $this->docId = $docId;
            }

            public function getSearchDocumentId(): string { return $this->docId; }
            public function toSearchDocument(): array { return ['title' => 'Test', 'body' => 'Content']; }
            public function toSearchMetadata(): array { return ['entity_type' => 'node']; }
        };
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/SearchIndexSubscriberTest.php`
Expected: FAIL — `SearchIndexSubscriber` class not found

- [ ] **Step 3: Implement SearchIndexSubscriber**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

final class SearchIndexSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SearchIndexerInterface $indexer,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_SAVE->value => 'onPostSave',
            EntityEvents::POST_DELETE->value => 'onPostDelete',
        ];
    }

    public function onPostSave(EntityEvent $event): void
    {
        $entity = $event->entity;

        if (!$entity instanceof SearchIndexableInterface) {
            return;
        }

        try {
            $this->indexer->index($entity);
        } catch (\Throwable $e) {
            error_log("Search indexing failed for {$entity->getSearchDocumentId()}: {$e->getMessage()}");
        }
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity = $event->entity;

        if (!$entity instanceof SearchIndexableInterface) {
            return;
        }

        try {
            $this->indexer->remove($entity->getSearchDocumentId());
        } catch (\Throwable $e) {
            error_log("Search index removal failed for {$entity->getSearchDocumentId()}: {$e->getMessage()}");
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit packages/search/tests/Unit/SearchIndexSubscriberTest.php`
Expected: 5 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add packages/search/src/EventSubscriber/SearchIndexSubscriber.php packages/search/tests/Unit/SearchIndexSubscriberTest.php
git commit -m "feat(#507): add SearchIndexSubscriber for entity lifecycle indexing"
```

---

### Task 5: SearchServiceProvider — Wiring

**Files:**
- Create: `packages/search/src/SearchServiceProvider.php`

**Reference:** `packages/note/src/NoteServiceProvider.php` for provider pattern, `packages/foundation/src/ServiceProvider/ServiceProvider.php` for base class.

- [ ] **Step 1: Implement SearchServiceProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Search\EventSubscriber\SearchIndexSubscriber;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;

final class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(SearchIndexerInterface::class, function (): SearchIndexerInterface {
            $database = $this->getSearchDatabase();
            $indexer = new Fts5SearchIndexer($database);
            $indexer->ensureSchema();
            return $indexer;
        });

        $this->singleton(SearchProviderInterface::class, function (): SearchProviderInterface {
            return new Fts5SearchProvider(
                $this->getSearchDatabase(),
                $this->resolve(SearchIndexerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $indexer = $this->resolve(SearchIndexerInterface::class);
        $subscriber = new SearchIndexSubscriber($indexer);

        $this->dispatcher->addSubscriber($subscriber);
    }

    private function getSearchDatabase(): DatabaseInterface
    {
        $searchDb = $this->config['search']['database'] ?? null;

        if ($searchDb !== null) {
            return DBALDatabase::createSqlite($searchDb);
        }

        return $this->resolve(DatabaseInterface::class);
    }
}
```

- [ ] **Step 2: Add provider to composer.json extra**

Add to `packages/search/composer.json`:
```json
"extra": {
    "waaseyaa": {
        "providers": [
            "Waaseyaa\\Search\\SearchServiceProvider"
        ]
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add packages/search/src/SearchServiceProvider.php packages/search/composer.json
git commit -m "feat(#507): add SearchServiceProvider wiring FTS5 indexer and provider"
```

---

### Task 6: SearchReindexCommand — CLI

**Files:**
- Create: `packages/cli/src/Command/SearchReindexCommand.php`

**Reference:** `packages/cli/src/Command/HealthCheckCommand.php` for command pattern, `packages/entity/src/EntityTypeManagerInterface.php` for entity type iteration.

- [ ] **Step 1: Implement SearchReindexCommand**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchIndexerInterface;

#[AsCommand(name: 'search:reindex', description: 'Rebuild the search index from all indexable entities')]
final class SearchReindexCommand extends Command
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly SearchIndexerInterface $indexer,
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Entities per batch', (string) self::BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        if ($batchSize < 1) {
            $batchSize = self::BATCH_SIZE;
        }

        $output->writeln('<info>Clearing search index...</info>');
        $this->indexer->removeAll();

        $totalIndexed = 0;

        foreach ($this->entityTypeManager->getDefinitions() as $entityType) {
            $storage = $this->entityTypeManager->getStorage($entityType->id());
            $entities = $storage->loadMultiple();
            $batchCount = 0;
            $typeIndexed = 0;

            foreach ($entities as $entity) {
                if (!$entity instanceof SearchIndexableInterface) {
                    continue;
                }

                $this->indexer->index($entity);
                $typeIndexed++;
                $totalIndexed++;
                $batchCount++;

                if ($batchCount >= $batchSize) {
                    $output->writeln("  [{$entityType->id()}] Indexed $typeIndexed entities...");
                    $batchCount = 0;
                }
            }

            if ($typeIndexed > 0) {
                $output->writeln("<comment>{$entityType->id()}</comment>: indexed $typeIndexed entities");
            }
        }

        $output->writeln("<info>Reindex complete. $totalIndexed documents indexed.</info>");
        $output->writeln('<info>Schema version: ' . $this->indexer->getSchemaVersion() . '</info>');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/cli/src/Command/SearchReindexCommand.php
git commit -m "feat(#507): add search:reindex CLI command for full index rebuilds"
```

---

### Task 7: Integration Tests

**Files:**
- Create: `tests/Integration/Search/Fts5SearchIntegrationTest.php`

**Reference:** Existing integration tests in `tests/Integration/` for setup patterns. Uses `DBALDatabase::createSqlite()` for in-memory testing.

- [ ] **Step 1: Write full-stack integration tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchFilters;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

#[CoversNothing]
final class Fts5SearchIntegrationTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;
    private Fts5SearchProvider $provider;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();
        $this->provider = new Fts5SearchProvider($this->database, $this->indexer);
    }

    #[Test]
    public function full_lifecycle_index_search_update_delete(): void
    {
        // Index
        $this->indexItem('node:1', ['title' => 'Waaseyaa Framework', 'body' => 'A PHP content framework'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'docs',
            'quality_score' => 90, 'topics' => ['php', 'framework'], 'url' => '/node/1',
            'og_image' => '/img/waaseyaa.png', 'created_at' => '2026-03-20T00:00:00Z',
        ]);

        // Search
        $result = $this->provider->search(new SearchRequest('Waaseyaa'));
        $this->assertSame(1, $result->totalHits);
        $hit = $result->hits[0];
        $this->assertSame('node:1', $hit->id);
        $this->assertSame('Waaseyaa Framework', $hit->title);
        $this->assertSame('/node/1', $hit->url);
        $this->assertSame(90, $hit->qualityScore);
        $this->assertSame(['php', 'framework'], $hit->topics);
        $this->assertGreaterThan(0.0, $hit->score);
        $this->assertNotEmpty($hit->highlight);

        // Update (upsert)
        $this->indexItem('node:1', ['title' => 'Waaseyaa CMS', 'body' => 'An updated PHP framework'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'docs',
            'quality_score' => 95, 'topics' => ['php', 'cms'], 'url' => '/node/1',
            'og_image' => '', 'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('Waaseyaa'));
        $this->assertSame(1, $result->totalHits);
        $this->assertSame('Waaseyaa CMS', $result->hits[0]->title);
        $this->assertSame(95, $result->hits[0]->qualityScore);

        // Delete
        $this->indexer->remove('node:1');
        $result = $this->provider->search(new SearchRequest('Waaseyaa'));
        $this->assertSame(0, $result->totalHits);
    }

    #[Test]
    public function multi_entity_type_search_with_facets(): void
    {
        $this->indexItem('node:1', ['title' => 'PHP Article', 'body' => 'Content about PHP'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'blog',
            'quality_score' => 80, 'topics' => ['php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('user:1', ['title' => 'PHP Developer', 'body' => 'A developer who writes PHP'], [
            'entity_type' => 'user', 'content_type' => 'profile', 'source_name' => '',
            'quality_score' => 70, 'topics' => ['php', 'developer'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Go Article', 'body' => 'Content about Go and PHP'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'blog',
            'quality_score' => 85, 'topics' => ['go', 'php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest('PHP'));
        $this->assertSame(3, $result->totalHits);

        // Content type facet
        $contentTypeFacet = $result->getFacet('content_type');
        $this->assertNotNull($contentTypeFacet);
        $bucketMap = $this->facetToMap($contentTypeFacet->buckets);
        $this->assertSame(2, $bucketMap['article']);
        $this->assertSame(1, $bucketMap['profile']);

        // Topics facet
        $topicsFacet = $result->getFacet('topics');
        $this->assertNotNull($topicsFacet);
        $topicMap = $this->facetToMap($topicsFacet->buckets);
        $this->assertSame(3, $topicMap['php']);
    }

    #[Test]
    public function combined_filters(): void
    {
        $this->indexItem('node:1', ['title' => 'Good Article', 'body' => 'Quality content'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'blog',
            'quality_score' => 90, 'topics' => ['php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Bad Article', 'body' => 'Low quality content'], [
            'entity_type' => 'node', 'content_type' => 'article', 'source_name' => 'spam',
            'quality_score' => 10, 'topics' => ['php'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);
        $this->indexItem('node:3', ['title' => 'Good Page', 'body' => 'Quality content page'], [
            'entity_type' => 'node', 'content_type' => 'page', 'source_name' => 'blog',
            'quality_score' => 85, 'topics' => ['go'], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        // Filter: article + quality >= 50 + source = blog + topic = php
        $filters = new SearchFilters(
            topics: ['php'],
            contentType: 'article',
            sourceNames: ['blog'],
            minQuality: 50,
        );

        $result = $this->provider->search(new SearchRequest('content', $filters));
        $this->assertSame(1, $result->totalHits);
        $this->assertSame('node:1', $result->hits[0]->id);
    }

    #[Test]
    public function reindex_clears_and_rebuilds(): void
    {
        $this->indexItem('node:1', ['title' => 'First', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $this->indexer->removeAll();

        $result = $this->provider->search(new SearchRequest('First'));
        $this->assertSame(0, $result->totalHits);
    }

    #[Test]
    public function porter_stemming_finds_word_variants(): void
    {
        $this->indexItem('node:1', ['title' => 'Running Tests', 'body' => 'Testing the application'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        // "test" should match "tests" and "testing" via porter stemmer
        $result = $this->provider->search(new SearchRequest('test'));
        $this->assertSame(1, $result->totalHits);
    }

    #[Test]
    public function empty_query_returns_empty_result(): void
    {
        $this->indexItem('node:1', ['title' => 'Something', 'body' => 'Content'], [
            'entity_type' => 'node', 'content_type' => '', 'source_name' => '',
            'quality_score' => 0, 'topics' => [], 'url' => '', 'og_image' => '',
            'created_at' => '2026-03-20T00:00:00Z',
        ]);

        $result = $this->provider->search(new SearchRequest(''));
        $this->assertSame(0, $result->totalHits);
    }

    private function indexItem(string $id, array $document, array $metadata): void
    {
        $this->indexer->index(new class ($id, $document, $metadata) implements SearchIndexableInterface {
            public function __construct(
                private readonly string $id,
                private readonly array $document,
                private readonly array $metadata,
            ) {}

            public function getSearchDocumentId(): string { return $this->id; }
            public function toSearchDocument(): array { return $this->document; }
            public function toSearchMetadata(): array { return $this->metadata; }
        });
    }

    /**
     * @param FacetBucket[] $buckets
     * @return array<string, int>
     */
    private function facetToMap(array $buckets): array
    {
        $map = [];
        foreach ($buckets as $bucket) {
            $map[$bucket->key] = $bucket->count;
        }
        return $map;
    }
}
```

- [ ] **Step 2: Run integration tests**

Run: `./vendor/bin/phpunit tests/Integration/Search/Fts5SearchIntegrationTest.php`
Expected: 6 tests, PASS (all indexer and provider code already implemented)

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Search/Fts5SearchIntegrationTest.php
git commit -m "test(#509): add FTS5 search integration tests — lifecycle, facets, filters, stemming"
```

---

### Task 8: Composer Autoload and Final Verification

**Files:**
- Modify: `packages/search/composer.json` (autoload for new namespaces)

- [ ] **Step 1: Add package dependencies to composer.json**

Add to `packages/search/composer.json` require section:
```json
"waaseyaa/database-legacy": "@dev",
"waaseyaa/entity": "@dev"
```

These are needed for `DatabaseInterface`/`DBALDatabase` and `EntityEvents`/`EntityEvent`.

- [ ] **Step 2: Verify PSR-4 autoload covers new subdirectories**

The existing `Waaseyaa\\Search\\` → `src/` mapping covers `Fts5/` and `EventSubscriber/` automatically.

- [ ] **Step 3: Run composer dump-autoload**

Run: `composer dump-autoload`
Expected: No errors

- [ ] **Step 3: Run all search tests**

Run: `./vendor/bin/phpunit packages/search/tests/ tests/Integration/Search/`
Expected: All tests pass (3 + 6 + 8 + 5 + 6 = 28 tests)

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit --configuration phpunit.xml.dist`
Expected: All existing tests still pass, new tests included

- [ ] **Step 5: Commit any remaining changes**

```bash
git add -A
git commit -m "chore(#507): update autoload and verify full test suite"
```

---

### Task 9: SearchIndexJob — Async Queue Message

**Files:**
- Create: `packages/search/src/SearchIndexJob.php`

- [ ] **Step 1: Create SearchIndexJob value object**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * Queue message for async search indexing.
 *
 * Carries only the document ID and entity type — the job handler
 * reloads the entity from storage to get fresh data.
 */
final readonly class SearchIndexJob
{
    public function __construct(
        public string $documentId,
        public string $entityTypeId,
        public string $operation = 'index',
    ) {}
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/search/src/SearchIndexJob.php
git commit -m "feat(#507): add SearchIndexJob message class for async indexing"
```

---

### Task 10: Health Check — Index Staleness Diagnostic

**Files:**
- Modify: `packages/cli/src/Command/HealthCheckCommand.php` (or relevant diagnostic registry)

**Reference:** Existing health check infrastructure in `packages/foundation/src/Diagnostic/`

- [ ] **Step 1: Add search index staleness check**

Add a search index diagnostic that:
- Queries `search_metadata` for any row where `schema_version != current version`
- Reports "healthy" if all documents match, "warning" if stale documents found
- Reports "N/A" if the search tables don't exist (search not configured)

The exact integration point depends on the existing health check architecture. Check `HealthCheckerInterface` and register a `SearchHealthCheck` class or add to existing diagnostics.

- [ ] **Step 2: Run health check to verify**

Run: `bin/waaseyaa health:check`
Expected: Search index staleness appears in output

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "feat(#507): add search index staleness diagnostic to health:check"
```

---

### Task 11: Close Design Issue

- [ ] **Step 1: Close #508 (design issue)**

```bash
gh issue close 508 -c "Design spec completed and reviewed: docs/history/superpowers/specs/2026-03-20-fts5-search-provider-design.md"
```

- [ ] **Step 2: Verify milestone progress**

```bash
gh issue list --milestone "v1.6 — Search Provider" --state all --json number,state,title
```
