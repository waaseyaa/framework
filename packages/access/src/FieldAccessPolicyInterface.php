<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Checks access for a specific field on an entity.
 *
 * Classes must implement both this interface AND AccessPolicyInterface
 * to participate in field-level access checks. EntityAccessHandler
 * iterates its AccessPolicyInterface policies and delegates to
 * fieldAccess() only for those that also implement this interface.
 * The same #[AccessPolicy] attribute and appliesTo() scoping apply.
 *
 * When no field access policy provides an opinion (all return Neutral),
 * the field is treated as accessible. Field access control is additive:
 * only explicit Forbidden results restrict access.
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
