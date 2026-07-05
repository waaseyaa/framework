<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\GraphQL\Policy;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Shaped like the real `NodeAccessPolicy`: entity-level `update` is ALLOWED for
 * an authenticated (non-admin) caller, but a specific FIELD's `edit` is
 * FORBIDDEN for non-admins. This is the fixture that exercises the field-level
 * existence-oracle residual (R11 follow-up): the entity-level access check
 * passes for the caller, so the entity-level not-found collapse never fires, and
 * a real entity's forbidden-field edit would otherwise throw the distinguishable
 * "Access denied: cannot edit field '{name}'" while an absent id throws "Entity
 * not found" -- an existence oracle over every id for any editor.
 *
 * `view` is open to everyone (so the same fixture can host a public-read control).
 */
final class FieldOraclePolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    public function __construct(
        private readonly string $forbiddenEditField = 'body',
    ) {}

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'view') {
            return AccessResult::allowed('Public content');
        }

        // Entity-level update/delete: allowed for any authenticated caller
        // (an ordinary editor), mirroring `edit any {type} content`.
        return $account->isAuthenticated()
            ? AccessResult::allowed('Editor access')
            : AccessResult::forbidden('Anonymous cannot modify');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return $account->isAuthenticated()
            ? AccessResult::allowed('Editor create')
            : AccessResult::forbidden('Anonymous cannot create');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($operation === 'edit'
            && $fieldName === $this->forbiddenEditField
            && !in_array('admin', $account->getRoles(), true)
        ) {
            return AccessResult::forbidden("Field '{$fieldName}' is admin-only for edits");
        }

        return AccessResult::neutral();
    }
}
