<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

final readonly class ContentSearchPage
{
    /**
     * @param array<mixed> $hits
     * @param array<mixed> $facets
     */
    public function __construct(
        public int $totalHits,
        public int $totalPages,
        public int $currentPage,
        public int $pageSize,
        public array $hits,
        public array $facets,
    ) {
        if ($totalHits < 0 || $totalPages < 0 || $currentPage < 1 || $pageSize < 1 || $pageSize > 100) {
            throw new ContentSearchBoundaryException('Search page metadata violates the API contract.');
        }
        if (!array_is_list($hits) || count($hits) > 100 || !array_is_list($facets) || count($facets) > 20) {
            throw new ContentSearchBoundaryException('Search result collections violate the API contract.');
        }
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                throw new ContentSearchBoundaryException('Search hit shape violates the API contract.');
            }
            self::assertHit($hit);
        }
        foreach ($facets as $facet) {
            if (!is_array($facet)) {
                throw new ContentSearchBoundaryException('Search facet shape violates the API contract.');
            }
            self::assertFacet($facet);
        }
    }

    /** @return list<array{id: string, title: string, url: string, highlight: string, score: float, source: string, contentType: string, qualityScore: int, crawledAt: string, topics: list<string>, image: string}> */
    public function safeHits(): array
    {
        /** @var list<array{id: string, title: string, url: string, highlight: string, score: float, source: string, contentType: string, qualityScore: int, crawledAt: string, topics: list<string>, image: string}> */
        return $this->hits;
    }

    /** @return list<array{name: string, buckets: list<array{key: string, count: int}>}> */
    public function safeFacets(): array
    {
        /** @var list<array{name: string, buckets: list<array{key: string, count: int}>}> */
        return $this->facets;
    }

    /** @param array<string, mixed> $hit */
    private static function assertHit(array $hit): void
    {
        $keys = ['id', 'title', 'url', 'highlight', 'score', 'source', 'contentType', 'qualityScore', 'crawledAt', 'topics', 'image'];
        if (array_keys($hit) !== $keys) {
            throw new ContentSearchBoundaryException('Search hit shape violates the API contract.');
        }
        foreach (['id' => 256, 'title' => 512, 'url' => 2048, 'highlight' => 4096, 'source' => 128, 'contentType' => 128, 'crawledAt' => 64, 'image' => 2048] as $key => $maximum) {
            if (!is_string($hit[$key]) || !mb_check_encoding($hit[$key], 'UTF-8') || mb_strlen($hit[$key], 'UTF-8') > $maximum) {
                throw new ContentSearchBoundaryException('Search hit string violates the API contract.');
            }
        }
        if (!is_float($hit['score']) || !is_finite($hit['score'])) {
            throw new ContentSearchBoundaryException('Search hit score violates the API contract.');
        }
        if (!is_int($hit['qualityScore']) || $hit['qualityScore'] < 0 || $hit['qualityScore'] > 100) {
            throw new ContentSearchBoundaryException('Search hit quality violates the API contract.');
        }
        if (!is_array($hit['topics']) || !array_is_list($hit['topics']) || count($hit['topics']) > 20) {
            throw new ContentSearchBoundaryException('Search hit topics violate the API contract.');
        }
        foreach ($hit['topics'] as $topic) {
            if (!is_string($topic) || !mb_check_encoding($topic, 'UTF-8') || mb_strlen($topic, 'UTF-8') > 128) {
                throw new ContentSearchBoundaryException('Search hit topic violates the API contract.');
            }
        }
    }

    /** @param array<string, mixed> $facet */
    private static function assertFacet(array $facet): void
    {
        if (array_keys($facet) !== ['name', 'buckets'] || !is_string($facet['name']) || mb_strlen($facet['name'], 'UTF-8') > 128) {
            throw new ContentSearchBoundaryException('Search facet shape violates the API contract.');
        }
        if (!is_array($facet['buckets']) || !array_is_list($facet['buckets']) || count($facet['buckets']) > 100) {
            throw new ContentSearchBoundaryException('Search facet buckets violate the API contract.');
        }
        foreach ($facet['buckets'] as $bucket) {
            if (
                !is_array($bucket)
                || array_keys($bucket) !== ['key', 'count']
                || !is_string($bucket['key'])
                || !mb_check_encoding($bucket['key'], 'UTF-8')
                || mb_strlen($bucket['key'], 'UTF-8') > 128
                || !is_int($bucket['count'])
                || $bucket['count'] < 0
            ) {
                throw new ContentSearchBoundaryException('Search facet bucket violates the API contract.');
            }
        }
    }
}
