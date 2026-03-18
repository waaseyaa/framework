<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

final class RoleBasedPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (in_array('admin', $account->getRoles(), true)) {
            return AccessResult::allowed('Admin access');
        }

        if (!$account->isAuthenticated()) {
            return AccessResult::forbidden('Anonymous denied');
        }

        if ($operation === 'view') {
            return AccessResult::allowed('Member view access');
        }

        return AccessResult::forbidden('Members cannot modify');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (in_array('admin', $account->getRoles(), true)) {
            return AccessResult::allowed('Admin create');
        }

        return AccessResult::forbidden('Non-admin cannot create');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($fieldName === 'secret' && !in_array('admin', $account->getRoles(), true)) {
            return AccessResult::forbidden('Secret restricted to admins');
        }

        return AccessResult::neutral();
    }
}
