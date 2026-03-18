<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

final class DenyByIdPolicy implements AccessPolicyInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly int|string $denyId,
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view'
            && $entity->getEntityTypeId() === $this->entityTypeId
            && (string) $entity->id() === (string) $this->denyId) {
            return AccessResult::forbidden("Test: entity {$this->denyId} denied");
        }

        return AccessResult::neutral();
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === $this->entityTypeId;
    }
}
