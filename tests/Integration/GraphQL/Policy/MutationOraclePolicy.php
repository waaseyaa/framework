<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * `view` is public (any account, including anonymous, may read); `update` and
 * `delete` require the `admin` role. This isolates the mutation-only existence
 * oracle (R11) from the read path: a control case can exercise a genuinely-open
 * view policy ("published content") while the mutation surface stays properly
 * access-controlled ("author-only edits") in the same fixture set.
 */
final class MutationOraclePolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view') {
            return AccessResult::allowed('Public content');
        }

        return in_array('admin', $account->getRoles(), true)
            ? AccessResult::allowed('Admin access')
            : AccessResult::forbidden('Only admins may modify');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return in_array('admin', $account->getRoles(), true)
            ? AccessResult::allowed('Admin create')
            : AccessResult::forbidden('Only admins may create');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
