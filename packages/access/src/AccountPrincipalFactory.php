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

        throw new \LogicException(
            'A plain AccountInterface cannot be losslessly converted into an immutable authorization principal. '
            . 'Identity providers must return AuthorizationPrincipalInterface or explicitly wrap the account '
            . 'in DelegatingAuthorizationPrincipal with provider-owned claims.',
        );
    }
}
