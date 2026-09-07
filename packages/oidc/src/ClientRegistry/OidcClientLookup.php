<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\ClientRegistry;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Oidc\Entity\OidcClient;
use Waaseyaa\Oidc\Exception\AmbiguousClientIdException;

/**
 * Looks up OIDC clients by their stable public `client_id` identifier.
 *
 * Bypasses OidcClientAccessPolicy on purpose. The access policy gates the admin
 * CRUD surface behind `administer oidc clients`; the authorize and token flows
 * run with an anonymous account before the user logs in, so they must read
 * clients directly from storage without an access check.
 *
 * `client_id` is a database-enforced unique registry identity (#2766). This
 * lookup is the shared pre-auth resolution path for authorize, token,
 * refresh, and revoke — a multi-match here means an impossible state (the
 * unique key is missing or a race slipped past it), so it fails closed
 * rather than silently picking `$ids[0]` and authenticating an arbitrary row.
 */
final class OidcClientLookup
{
    public function __construct(
        // C-22 WP2/WP3: query + read path both go through the canonical repository.
        private readonly EntityRepositoryInterface $repository,
    ) {}

    /** @throws AmbiguousClientIdException If more than one row matches — see class docblock. */
    public function findByClientId(string $clientId): ?OidcClient
    {
        if ($clientId === '') {
            return null;
        }

        $ids = $this->repository->getQuery()
            // system context: client registry lookup runs pre-auth
            ->accessCheck(false)
            ->condition('client_id', $clientId)
            ->execute();

        if ($ids === []) {
            return null;
        }

        if (\count($ids) > 1) {
            throw new AmbiguousClientIdException($clientId, \array_map('strval', $ids));
        }

        $entity = $this->repository->find((string) $ids[0]);

        return $entity instanceof OidcClient ? $entity : null;
    }
}
