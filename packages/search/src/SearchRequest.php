<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class SearchRequest
{
    public function __construct(
        public string $query,
        public SearchFilters $filters = new SearchFilters(),
        public int $page = 1,
        public int $pageSize = 20,
        public bool $includeFacets = true,
    ) {}

    public function cacheKey(): string
    {
        return hash('sha256', serialize([
            $this->query,
            $this->filters,
            $this->page,
            $this->pageSize,
            $this->includeFacets,
        ]));
    }
}
