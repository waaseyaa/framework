<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

final class RestrictFieldPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function __construct(
        private readonly string $entityTypeId,
        private readonly string $fieldName,
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
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

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($fieldName === $this->fieldName) {
            return AccessResult::forbidden("Test: field {$this->fieldName} restricted");
        }

        return AccessResult::neutral();
    }
}
