<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/** Closed, strictly audited bridge used before an account field context exists. @api */
interface AuthorizationPrincipalBootstrapReaderInterface
{
    public function fromEntity(EntityInterface $account, ?string $tenantId = null, ?string $communityId = null): AuthorizationPrincipalInterface;
}
