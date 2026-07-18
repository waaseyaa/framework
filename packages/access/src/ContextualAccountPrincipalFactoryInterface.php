<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

/** HTTP companion that binds the resolved tenant/community to a principal snapshot. @api */
interface ContextualAccountPrincipalFactoryInterface extends AccountPrincipalFactoryInterface
{
    public function fromAccountInContext(AccountInterface $account, ?string $tenantId, ?string $communityId): AuthorizationPrincipalInterface;
}
