<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * Regression test for audit finding D-36: facet scans are gated behind
 * SearchRequest::$includeFacets so performance-sensitive callers can skip the
 * three unbounded GROUP BY scans (one a json_each cross-join). The default
 * (true) must preserve the prior behaviour of always returning facets.
 */
#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderFacetGateTest extends TestCase
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
    }

    #[Test]
    public function facets_are_returned_by_default(): void
    {
        $result = $this->provider->search(new SearchRequest('Content'));

        $this->assertNotNull($result->getFacet('content_type'));
        $this->assertNotNull($result->getFacet('source_name'));
        $this->assertNotNull($result->getFacet('topics'));
        $this->assertSame(2, $result->totalHits);
    }

    #[Test]
    public function facets_are_suppressed_when_disabled(): void
    {
        $result = $this->provider->search(new SearchRequest('Content', includeFacets: false));

        // Hits are unaffected; only the three GROUP BY facet scans are skipped.
        $this->assertSame(2, $result->totalHits);
        $this->assertCount(2, $result->hits);
        $this->assertSame([], $result->facets);
        $this->assertNull($result->getFacet('content_type'));
        $this->assertNull($result->getFacet('source_name'));
        $this->assertNull($result->getFacet('topics'));
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
