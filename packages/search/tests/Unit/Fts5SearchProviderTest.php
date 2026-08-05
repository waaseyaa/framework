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

/**
 * @covers \Waaseyaa\Search\Fts5\Fts5SearchProvider
 */
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
        $this->provider = new Fts5SearchProvider($this->database, $this->indexer, new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver($this->database));
    }

    #[Test]
    public function it_returns_empty_result_for_no_matches(): void
    {
        $result = $this->search(new SearchRequest('nonexistent'));

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

        $result = $this->search(new SearchRequest('PHP'));

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

        $result = $this->search(new SearchRequest('Content', new SearchFilters(contentType: 'article')));

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

        $result = $this->search(new SearchRequest('Test', new SearchFilters(minQuality: 50)));

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

        $result = $this->search(new SearchRequest('Post', new SearchFilters(topics: ['php'])));

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

        $result = $this->search(new SearchRequest('Content'));

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

        $result = $this->search(new SearchRequest('Searchable', pageSize: 2));

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
        $result = $this->search(new SearchRequest('AND OR NOT'));

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

    private function search(SearchRequest $request): \Waaseyaa\Search\SearchResult
    {
        return $this->provider->search($request, \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());
    }
}
