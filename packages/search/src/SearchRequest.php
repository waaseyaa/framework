<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** @internal Parked with the first-party search read surface. */
final readonly class SearchRequest
{
    public function __construct(
        public string $query,
        public SearchFilters $filters = new SearchFilters(),
        public int $page = 1,
        public int $pageSize = 20,
        public bool $includeFacets = true,
    ) {}
}
