<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityInterface;

/**
 * Checks access for a specific operation on an entity.
 *
 * Access policies are collected and evaluated by the EntityAccessHandler.
 * Each policy returns an AccessResult; the handler combines them.
 */
interface AccessPolicyInterface
{
    /**
     * Check access for an existing entity.
     *
     * @param EntityInterface $entity    The entity being accessed.
     * @param string          $operation The operation: 'view', 'update', or 'delete'.
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     */
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult;

    /**
     * Check access for creating a new entity.
     *
     * @param string           $entityTypeId The entity type ID (e.g. 'node').
     * @param string           $bundle       The bundle (e.g. 'article').
     * @param AuthorizationPrincipalInterface $account The immutable principal requesting access.
     */
    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult;

    /**
     * Whether this policy applies to the given entity type.
     *
     * @param string $entityTypeId The entity type ID.
     */
    public function appliesTo(string $entityTypeId): bool;
}
