<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

/** Identifier-only cache payload for a resolved listing. @internal */
final readonly class ListingCacheProjection
{
    /**
     * @param list<int|string>        $rowIds
     * @param list<non-empty-string> $cacheTags
     * @param list<non-empty-string> $cacheContexts
     */
    public function __construct(
        public array $rowIds,
        public Pagination $pagination,
        public array $cacheTags,
        public array $cacheContexts,
    ) {}
}
