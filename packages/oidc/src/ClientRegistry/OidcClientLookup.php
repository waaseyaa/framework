<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\ClientRegistry;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\Oidc\Entity\OidcClient;

/**
 * Looks up OIDC clients by their stable public `client_id` identifier.
 *
 * Bypasses OidcClientAccessPolicy on purpose. The access policy gates the admin
 * CRUD surface behind `administer oidc clients`; the authorize and token flows
 * run with an anonymous account before the user logs in, so they must read
 * clients directly from storage without an access check.
 */
final class OidcClientLookup
{
    public function __construct(
        private readonly SqlEntityStorage $storage,
        // C-22 WP2: the query builder now lives on the repository.
        private readonly EntityRepositoryInterface $repository,
    ) {}

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

        $entity = $this->storage->load($ids[0]);

        return $entity instanceof OidcClient ? $entity : null;
    }
}
