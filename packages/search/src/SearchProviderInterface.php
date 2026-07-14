<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

/**
 * @internal Parked until a first-party read endpoint adopts the access-checked provider.
 */
interface SearchProviderInterface
{
    public function search(SearchRequest $request): SearchResult;
}
