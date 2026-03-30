<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class TestNodeUpdatePolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'node';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'update') {
            return $account->hasPermission('edit any article content')
                ? AccessResult::allowed('Update access granted.')
                : AccessResult::neutral('Update access denied.');
        }

        return AccessResult::allowed('Allowed for test operation.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Not used.');
    }
}
