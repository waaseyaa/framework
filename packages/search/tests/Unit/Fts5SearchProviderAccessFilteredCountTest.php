<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Search\Fts5\Fts5SearchIndexer;
use Waaseyaa\Search\Fts5\Fts5SearchProvider;
use Waaseyaa\Search\SearchCandidateProjection;
use Waaseyaa\Search\SearchCandidateReference;
use Waaseyaa\Search\SearchCandidateResolverInterface;
use Waaseyaa\Search\SearchIndexableInterface;
use Waaseyaa\Search\SearchRequest;

/**
 * Regression test for WP03 / §2.2 — FTS5 access oracle:
 * totalHits and facet bucket counts must
 * reflect only the documents the acting account may view, not the raw SQL
 * count. An anonymous visitor must not be able to infer the existence of
 * access-restricted documents through totalHits or facet counts.
 */
#[CoversClass(Fts5SearchProvider::class)]
final class Fts5SearchProviderAccessFilteredCountTest extends TestCase
{
    private DBALDatabase $database;
    private Fts5SearchIndexer $indexer;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        $this->indexer = new Fts5SearchIndexer($this->database);
        $this->indexer->ensureSchema();

        // Index two documents that both match the word "Tutorial":
        //   node:1 — allowed (viewable)
        //   node:2 — denied (access-restricted, e.g. unpublished node)
        // Both share the same content_type ('article') so the facet bucket
        // count is a direct observable of whether the denied doc was included.
        $this->indexItem('node:1', ['title' => 'Tutorial One', 'body' => 'Searchable tutorial'], [
            'entity_type' => 'node',
            'content_type' => 'article',
            'source_name' => 'blog',
            'quality_score' => 0,
            'topics' => ['php'],
            'url' => '/node/1',
            'og_image' => '',
            'created_at' => '2026-01-01T00:00:00Z',
        ]);
        $this->indexItem('node:2', ['title' => 'Tutorial Two', 'body' => 'Searchable tutorial'], [
            'entity_type' => 'node',
            'content_type' => 'article',
            'source_name' => 'blog',
            'quality_score' => 0,
            'topics' => ['php'],
            'url' => '/node/2',
            'og_image' => '',
            'created_at' => '2026-01-02T00:00:00Z',
        ]);
    }

    #[Test]
    public function total_hits_and_facets_are_filtered_when_checker_denies_one_document(): void
    {
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $this->resolverDenying('node:2'));
        $result = $provider->search(new SearchRequest('tutorial'), \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());

        // Only the viewable document should be counted.
        $this->assertSame(1, $result->totalHits, 'totalHits must exclude access-denied documents');
        $this->assertSame(1, $result->totalPages);

        // The content_type facet bucket count must also be 1, not 2.
        $contentTypeFacet = $result->getFacet('content_type');
        $this->assertNotNull($contentTypeFacet, 'content_type facet should be present');
        $this->assertCount(1, $contentTypeFacet->buckets);
        $this->assertSame('article', $contentTypeFacet->buckets[0]->key);
        $this->assertSame(1, $contentTypeFacet->buckets[0]->count, 'facet bucket count must exclude access-denied documents');

        // The topics facet for 'php' should also be 1.
        $topicsFacet = $result->getFacet('topics');
        $this->assertNotNull($topicsFacet, 'topics facet should be present');
        $phpBucket = null;
        foreach ($topicsFacet->buckets as $bucket) {
            if ($bucket->key === 'php') {
                $phpBucket = $bucket;
                break;
            }
        }
        $this->assertNotNull($phpBucket, "'php' topic bucket should be present");
        $this->assertSame(1, $phpBucket->count, 'topics facet count must exclude access-denied documents');

        // Hits must also only contain the viewable document.
        $this->assertCount(1, $result->hits);
        $this->assertSame('node:1', $result->hits[0]->id);
    }

    #[Test]
    public function total_hits_is_zero_when_checker_denies_all_documents(): void
    {
        $resolver = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver(
            $this->database,
            static fn(): bool => false,
        );
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $resolver);
        $result = $provider->search(new SearchRequest('tutorial'), \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());

        $this->assertSame(0, $result->totalHits, 'totalHits must be 0 when all docs are access-denied');
        $this->assertSame(0, $result->totalPages);
        $this->assertSame([], $result->hits);
        $this->assertSame([], $result->facets);
    }

    #[Test]
    public function facets_excluded_but_count_still_filtered_when_include_facets_false(): void
    {
        // When includeFacets is false, facets are not built — but totalHits must
        // still reflect the access-filtered count, not the raw SQL count.
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $this->resolverDenying('node:2'));
        $result = $provider->search(new SearchRequest('tutorial', includeFacets: false), \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create());

        $this->assertSame(1, $result->totalHits, 'totalHits must be access-filtered even when includeFacets=false');
        $this->assertSame([], $result->facets, 'facets must be empty when includeFacets=false');
    }

    #[Test]
    public function candidate_rows_below_the_public_bound_are_complete(): void
    {
        for ($id = 3; $id <= 201; ++$id) {
            $this->indexItem('node:' . $id, ['title' => 'Tutorial ' . $id, 'body' => 'Searchable tutorial'], [
                'entity_type' => 'node',
                'content_type' => 'article',
                'created_at' => '2026-01-01T00:00:00Z',
            ]);
        }
        $resolver = new class ($this->database) implements SearchCandidateResolverInterface {
            public int $calls = 0;
            private readonly \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver $inner;

            public function __construct(DBALDatabase $database)
            {
                $this->inner = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver($database);
            }

            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                ++$this->calls;

                return $this->inner->resolve($reference, $principal);
            }
        };
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $resolver);

        $result = $provider->search(
            new SearchRequest('tutorial'),
            \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create(),
        );

        self::assertSame(201, $resolver->calls);
        self::assertSame(201, $result->totalHits);
        self::assertTrue($result->isComplete);
    }

    #[Test]
    public function an_exhausted_candidate_window_is_bounded_and_explicitly_incomplete(): void
    {
        for ($id = 3; $id <= 1002; ++$id) {
            $this->indexItem('node:' . $id, ['title' => 'Tutorial ' . $id, 'body' => 'Searchable tutorial'], [
                'entity_type' => 'node',
                'content_type' => 'article',
                'created_at' => '2026-01-01T00:00:00Z',
            ]);
        }
        $resolver = new class ($this->database) implements SearchCandidateResolverInterface {
            public int $calls = 0;
            private readonly \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver $inner;
            public function __construct(DBALDatabase $database)
            {
                $this->inner = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver($database);
            }
            public function resolve(SearchCandidateReference $reference, AuthorizationPrincipalInterface $principal): ?SearchCandidateProjection
            {
                ++$this->calls;
                return $this->inner->resolve($reference, $principal);
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Search candidate window exhausted; result totals and facets are lower bounds.',
            ['candidate_limit' => 1000],
        );
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $resolver, $logger);

        $result = $provider->search(
            new SearchRequest('tutorial'),
            \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create(),
        );

        self::assertSame(1000, $resolver->calls, 'The sentinel row must never be projected.');
        self::assertSame(1000, $result->totalHits);
        self::assertFalse($result->isComplete);
    }

    #[Test]
    public function an_accessible_match_after_two_hundred_denied_candidates_is_not_diluted_out(): void
    {
        for ($id = 3; $id <= 201; ++$id) {
            $this->indexItem('node:' . $id, ['title' => 'Tutorial ' . $id, 'body' => 'Searchable tutorial'], [
                'entity_type' => 'node',
                'content_type' => 'article',
                'created_at' => '2026-01-01T00:00:00Z',
            ]);
        }
        $resolver = new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver(
            $this->database,
            static fn(SearchCandidateReference $reference): bool => $reference->documentId === 'node:201',
        );
        $provider = new Fts5SearchProvider($this->database, $this->indexer, $resolver);

        $result = $provider->search(
            new SearchRequest('tutorial'),
            \Waaseyaa\Search\Tests\Support\SearchTestPrincipal::create(),
        );

        self::assertSame(1, $result->totalHits);
        self::assertSame('node:201', $result->hits[0]->id);
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

    private function resolverDenying(string $deniedId): \Waaseyaa\Search\SearchCandidateResolverInterface
    {
        return new \Waaseyaa\Search\Tests\Support\IndexedSearchCandidateResolver(
            $this->database,
            static fn(\Waaseyaa\Search\SearchCandidateReference $reference): bool => $reference->documentId !== $deniedId,
        );
    }
}
