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
