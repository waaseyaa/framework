<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: ['group', 'group_type'])]
final class GroupAccessPolicy implements AccessPolicyInterface
{
    public const string ADMIN_PERMISSION = 'administer groups';

    private const array ENTITY_TYPES = ['group', 'group_type'];
    private const array MANAGED_OPERATIONS = ['view', 'update', 'delete'];

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::ENTITY_TYPES, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (!in_array($operation, self::MANAGED_OPERATIONS, true)) {
            return AccessResult::neutral('Group policy does not govern this operation.');
        }
        if ($account->hasPermission(self::ADMIN_PERMISSION)) {
            return AccessResult::allowed('Account holds administer groups permission.');
        }

        return AccessResult::forbidden('The administer groups permission is required.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (!$this->appliesTo($entityTypeId)) {
            return AccessResult::neutral('Group policy does not govern this entity type.');
        }
        if ($account->hasPermission(self::ADMIN_PERMISSION)) {
            return AccessResult::allowed('Account holds administer groups permission.');
        }

        return AccessResult::forbidden('The administer groups permission is required.');
    }
}
