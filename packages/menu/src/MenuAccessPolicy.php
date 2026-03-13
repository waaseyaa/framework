<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: ['menu', 'menu_link'])]
final class MenuAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, ['menu', 'menu_link'], true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer menu')) {
            return AccessResult::allowed('User has administer menu permission.');
        }

        return match ($operation) {
            'view' => $account->isAuthenticated()
                ? AccessResult::allowed('Authenticated users may view menus.')
                : AccessResult::neutral('View access not granted to unauthenticated users.'),
            default => AccessResult::neutral("No opinion on '$operation' for menu entities."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer menu')) {
            return AccessResult::allowed('User has administer menu permission.');
        }

        return AccessResult::neutral('User lacks administer menu permission.');
    }
}
