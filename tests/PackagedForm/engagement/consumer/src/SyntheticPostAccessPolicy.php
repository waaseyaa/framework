<?php

declare(strict_types=1);

namespace EngagementConsumer;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: 'synthetic_post')]
final class SyntheticPostAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'synthetic_post';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view' && $entity instanceof SyntheticPost && $entity->get('status') === true) {
            return AccessResult::allowed('Published synthetic fixture posts are viewable.');
        }
        return AccessResult::neutral('Synthetic fixture policy grants view only to published posts.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Synthetic post creation is fixture-owned.');
    }
}
