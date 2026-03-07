<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

interface SearchProviderInterface
{
    public function search(SearchRequest $request): SearchResult;
}
