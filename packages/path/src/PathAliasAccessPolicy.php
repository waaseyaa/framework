<?php

declare(strict_types=1);

namespace Waaseyaa\Path;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

#[PolicyAttribute(entityType: 'path_alias')]
final class PathAliasAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'path_alias';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer url aliases')) {
            return AccessResult::allowed('User has administer url aliases permission.');
        }

        return match ($operation) {
            'view' => ($entity instanceof PathAlias && !$entity->isPublished())
                ? AccessResult::neutral('Unpublished path aliases are not publicly viewable.')
                : AccessResult::allowed('Published path aliases are publicly viewable.'),
            default => AccessResult::neutral("No permission for '$operation' on path aliases."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer url aliases')) {
            return AccessResult::allowed('User has administer url aliases permission.');
        }

        return AccessResult::neutral('User lacks administer url aliases permission.');
    }
}
