<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/** Resolves an index pointer to a canonical principal-safe projection. @api */
interface SearchCandidateResolverInterface
{
    public function resolve(
        SearchCandidateReference $reference,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection;
}
