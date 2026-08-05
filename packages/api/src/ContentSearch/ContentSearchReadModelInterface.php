<?php

declare(strict_types=1);

namespace Waaseyaa\Api\ContentSearch;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** @internal Optional-package anti-corruption port; not an application extension point. */
interface ContentSearchReadModelInterface
{
    public function search(ContentSearchQuery $query, AuthorizationPrincipalInterface $principal): ContentSearchPage;
}
