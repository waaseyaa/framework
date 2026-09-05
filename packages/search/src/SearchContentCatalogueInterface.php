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
    /**
     * One bounded discovery window, optionally resumed after a prior scan position.
     *
     * Implementations MUST NOT expose raw counts, denied identifiers, or
     * ordering positions except via the sealed-capable {@see SearchCataloguePage::$next}.
     */
    public function list(
        AuthorizationPrincipalInterface $principal,
        ?SearchCatalogueScanPosition $after = null,
    ): SearchCataloguePage;

    public function readByPublicPath(
        string $publicPath,
        AuthorizationPrincipalInterface $principal,
    ): ?SearchCandidateProjection;
}
