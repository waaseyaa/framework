<?php

declare(strict_types=1);

namespace Waaseyaa\Search;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Principal-safe content discovery and direct reads over protected index pointers.
 *
 * Implementations MUST treat raw index rows only as bounded candidate pointers.
 * No raw field, identifier, path, count, ordering position, or error may reach
 * callers. Every returned projection must be re-resolved canonically under the
 * exact supplied principal.
 *
 * @api
 */
interface SearchContentCatalogueInterface
{
    /** @return list<SearchCandidateProjection> One bounded discovery window. */
    public function list(AuthorizationPrincipalInterface $principal): array;

    public function readByPublicPath(
        string $publicPath,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection;
}
