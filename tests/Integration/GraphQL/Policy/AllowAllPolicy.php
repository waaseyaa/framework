<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class AllowAllPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('Test: allow all');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::allowed('Test: allow all creates');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
