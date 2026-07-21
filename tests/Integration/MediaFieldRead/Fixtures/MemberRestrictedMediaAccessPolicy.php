<?php

declare(strict_types=1);

namespace MediaReadRegression;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: 'media')]
final class MemberRestrictedMediaAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'media';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($entity->bundle() !== 'members_document' || $operation !== 'view') {
            return AccessResult::neutral();
        }

        return in_array('band_member', $account->getRoles(), true)
            ? AccessResult::allowed('Band Members may view member documents.')
            : AccessResult::forbidden('Member documents require Band Member access.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
