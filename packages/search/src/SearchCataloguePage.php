<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * One bounded principal-safe catalogue discovery window.
 *
 * {@see $next} is present when the protected scan can continue; it reveals no
 * counts, denied identifiers, or hidden positions to callers that leave it
 * sealed. An empty {@see $projections} list with a non-null {@see $next} is a
 * valid page (all candidates in the window were invisible).
 *
 * @api
 */
final readonly class SearchCataloguePage
{
    /**
     * @param list<SearchCandidateProjection> $projections
     */
    public function __construct(
        public array $projections,
        public ?SearchCatalogueScanPosition $next = null,
    ) {
        if (!array_is_list($projections)) {
            throw new \InvalidArgumentException('Search catalogue pages require a list of projections.');
        }
        foreach ($projections as $projection) {
            if (!$projection instanceof SearchCandidateProjection) {
                throw new \InvalidArgumentException('Search catalogue pages require SearchCandidateProjection members.');
            }
        }
    }
}
