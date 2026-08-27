<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Entity-level `view` is FORBIDDEN, `update`/`delete` are allowed to an
 * authenticated account.
 *
 * The inverse of {@see MutationOraclePolicy}, and the shape that separates
 * identity resolution from authorization: a caller here is entitled to modify
 * an entity it may not read. Real policies do this — an embargoed or
 * legal-hold record an editor must still be able to retract, a moderation
 * queue whose contents only the workflow may read.
 *
 * Under this policy a `view`-filtered lookup finds nothing, so any resolver
 * that access-checks identity resolution reports "not found" for an entity the
 * caller is authorized to update.
 */
final class UpdateWithoutViewPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view') {
            return AccessResult::forbidden('Not readable');
        }

        return $account->isAuthenticated()
            ? AccessResult::allowed('Authenticated may modify')
            : AccessResult::forbidden('Anonymous may not modify');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::forbidden('Creation is out of scope for this fixture');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
