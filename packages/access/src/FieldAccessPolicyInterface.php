<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Checks access for a specific field on an entity.
 *
 * Policies implement this interface alongside AccessPolicyInterface to
 * opt into field-level access control. The same #[AccessPolicy] attribute
 * and appliesTo() method scope field checks to entity types.
 */
interface FieldAccessPolicyInterface
{
    /**
     * Check access for a specific field on an entity.
     *
     * @param EntityInterface  $entity    The entity being accessed.
     * @param string           $fieldName The field name being checked.
     * @param string           $operation The operation: 'view' or 'edit'.
     * @param AccountInterface $account   The account requesting access.
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult;
}
