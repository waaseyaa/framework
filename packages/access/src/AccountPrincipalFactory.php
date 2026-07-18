<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/**
 * Final identity-boundary snapshotter for account policy claims.
 *
 * @api
 */
final readonly class AccountPrincipalFactory implements ContextualAccountPrincipalFactoryInterface
{
    public function __construct(private ?AuthorizationPrincipalBootstrapReaderInterface $bootstrapReader = null) {}

    public function fromAccount(AccountInterface $account): AuthorizationPrincipalInterface
    {
        return $this->fromAccountInContext($account, null, null);
    }

    public function fromAccountInContext(AccountInterface $account, ?string $tenantId, ?string $communityId): AuthorizationPrincipalInterface
    {
        if ($account instanceof AuthorizationPrincipalInterface) {
            return $account;
        }

        if ($account instanceof \Waaseyaa\Entity\EntityInterface) {
            if ($this->bootstrapReader === null) {
                throw new \LogicException('Entity-backed accounts require the audited authorization-principal bootstrap reader.');
            }
            return $this->bootstrapReader->fromEntity($account, $tenantId, $communityId);
        }

        $roles = array_values(array_unique($account->getRoles()));
        sort($roles);
        $generation = hash('sha256', json_encode([
            'id' => $account->id(),
            'authenticated' => $account->isAuthenticated(),
            'roles' => $roles,
        ], JSON_THROW_ON_ERROR));

        return new AuthorizationPrincipal(
            accountId: $account->id(),
            authenticated: $account->isAuthenticated(),
            roles: $roles,
            permissions: [],
            claimsGeneration: $generation,
            tenantId: $tenantId,
            communityId: $communityId,
        );
    }
}
