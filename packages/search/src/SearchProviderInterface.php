<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Principal-explicit full-text search service. @api */
interface SearchProviderInterface
{
    public function search(SearchRequest $request, AuthorizationPrincipalInterface $principal): SearchResult;
}
