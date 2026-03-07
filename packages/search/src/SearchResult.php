<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class SearchResult
{
    /**
     * @param SearchHit[] $hits
     * @param SearchFacet[] $facets
     */
    public function __construct(
        public int $totalHits,
        public int $totalPages,
        public int $currentPage,
        public int $pageSize,
        public int $tookMs,
        public array $hits,
        public array $facets = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            totalHits: 0,
            totalPages: 0,
            currentPage: 1,
            pageSize: 20,
            tookMs: 0,
            hits: [],
        );
    }

    public function getFacet(string $name): ?SearchFacet
    {
        foreach ($this->facets as $facet) {
            if ($facet->name === $name) {
                return $facet;
            }
        }
        return null;
    }
}
