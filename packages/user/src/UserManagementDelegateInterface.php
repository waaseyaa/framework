<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AuthorizationPrincipalInterface;

/**
 * Consumer-scoped account management inside the canonical User policy.
 *
 * Implementations must remain stateless, use audited target inputs, and admit
 * only their exact actor and target scope. This contract grants no credential,
 * role, permission, verification, read-projection or deletion authority.
 * @api
 */
interface UserManagementDelegateInterface
{
    public function canUpdate(User $target, AuthorizationPrincipalInterface $actor): bool;

    public function canChangeActiveStatus(User $target, AuthorizationPrincipalInterface $actor): bool;
}
