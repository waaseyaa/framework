<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

final readonly class SearchFacet
{
    /**
     * @param FacetBucket[] $buckets
     */
    public function __construct(
        public string $name,
        public array $buckets,
    ) {}
}
