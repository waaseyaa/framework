<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/** Application contribution point for canonical non-entity search sources. @api */
interface ProvidesSearchSourceResolversInterface
{
    /** @return array<string, SearchCandidateResolverInterface> Exact document-id namespace => resolver. */
    public function searchSourceResolvers(): array;
}
