<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** Bounded principal-safe search result. @api */
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
        public array $hits,
        public array $facets = [],
        /** False when a provider stopped at its documented candidate window. */
        public bool $isComplete = true,
    ) {}

    public static function empty(): self
    {
        return new self(
            totalHits: 0,
            totalPages: 0,
            currentPage: 1,
            pageSize: 20,
            hits: [],
        );
    }

    public function getFacet(string $name): ?SearchFacet
    {
        return array_find($this->facets, static fn(SearchFacet $facet): bool => $facet->name === $name);
    }
}
