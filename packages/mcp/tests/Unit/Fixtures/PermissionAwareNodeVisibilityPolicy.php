<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class PermissionAwareNodeVisibilityPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation !== 'view') {
            return AccessResult::neutral('Not used.');
        }

        if ((int) ($entity->toArray()['status'] ?? 0) === 1) {
            return AccessResult::allowed('Published');
        }

        return $account->hasPermission('view unpublished content')
            ? AccessResult::allowed('Unpublished access granted.')
            : AccessResult::forbidden('Unpublished');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Not used.');
    }
}
