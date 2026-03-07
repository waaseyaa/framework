<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Search\FacetBucket;
use Waaseyaa\Search\SearchFacet;
use Waaseyaa\Search\SearchHit;
use Waaseyaa\Search\SearchResult;

#[CoversClass(SearchResult::class)]
#[CoversClass(SearchHit::class)]
#[CoversClass(SearchFacet::class)]
#[CoversClass(FacetBucket::class)]
final class SearchResultTest extends TestCase
{
    #[Test]
    public function empty_result(): void
    {
        $result = SearchResult::empty();

        $this->assertSame(0, $result->totalHits);
        $this->assertSame(0, $result->totalPages);
        $this->assertSame([], $result->hits);
        $this->assertSame([], $result->facets);
    }

    #[Test]
    public function it_constructs_with_hits_and_facets(): void
    {
        $hit = new SearchHit(
            id: 'abc123',
            title: 'Test Article',
            url: 'https://example.com/test',
            sourceName: 'Example Source',
            crawledAt: '2026-03-06T12:00:00Z',
            qualityScore: 80,
            contentType: 'article',
            topics: ['education', 'health'],
            score: 15.5,
        );

        $facet = new SearchFacet(
            name: 'topics',
            buckets: [
                new FacetBucket(key: 'education', count: 500),
                new FacetBucket(key: 'health', count: 300),
            ],
        );

        $result = new SearchResult(
            totalHits: 1,
            totalPages: 1,
            currentPage: 1,
            pageSize: 20,
            tookMs: 150,
            hits: [$hit],
            facets: [$facet],
        );

        $this->assertSame(1, $result->totalHits);
        $this->assertCount(1, $result->hits);
        $this->assertSame('Test Article', $result->hits[0]->title);
        $this->assertSame(['education', 'health'], $result->hits[0]->topics);
    }

    #[Test]
    public function get_facet_by_name(): void
    {
        $topicsFacet = new SearchFacet(name: 'topics', buckets: []);
        $sourcesFacet = new SearchFacet(name: 'sources', buckets: []);

        $result = new SearchResult(
            totalHits: 0,
            totalPages: 0,
            currentPage: 1,
            pageSize: 20,
            tookMs: 0,
            hits: [],
            facets: [$topicsFacet, $sourcesFacet],
        );

        $this->assertSame($topicsFacet, $result->getFacet('topics'));
        $this->assertSame($sourcesFacet, $result->getFacet('sources'));
        $this->assertNull($result->getFacet('nonexistent'));
    }
}
