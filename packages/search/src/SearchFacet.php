<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * @internal Parked with the first-party search read surface.
 */
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
