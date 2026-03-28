<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: ['reaction', 'comment', 'follow'])]
final class EngagementAccessPolicy implements AccessPolicyInterface
{
    /** @var list<string> */
    private const TYPES = ['reaction', 'comment', 'follow'];

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::TYPES, true);
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        return match ($operation) {
            'view' => AccessResult::allowed('Engagement entities are publicly viewable.'),
            'delete' => $this->ownerCheck($entity, $account),
            default => AccessResult::neutral('Non-admin cannot modify engagement entities.'),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if ($account->isAuthenticated()) {
            return AccessResult::allowed('Authenticated users may create engagement entities.');
        }

        return AccessResult::neutral('Anonymous users cannot create engagement entities.');
    }

    private function ownerCheck(EntityInterface $entity, AccountInterface $account): AccessResult
    {
        $userId = $entity->get('user_id');

        if ($userId !== null && (int) $userId === (int) $account->id()) {
            return AccessResult::allowed('Owner may delete own engagement entity.');
        }

        return AccessResult::neutral('Non-owner cannot delete engagement entity.');
    }
}
