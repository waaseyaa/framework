<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship\Tests\Fixtures;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Stand-in for a real endpoint entity type's own AccessPolicy (e.g.
 * NodeAccessPolicy): view is allowed when `published` is true, or when the
 * account holds the 'administer nodes' bypass — otherwise neutral (denied).
 * Exercises {@see \Waaseyaa\Relationship\RelationshipEndpointVisibilityPolicy}'s
 * delegation to a genuinely independent per-entity-type policy.
 *
 * @internal Test double for Relationship package tests.
 */
final class EndpointFixtureAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'endpoint_entity';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        if ($operation !== 'view') {
            return AccessResult::neutral();
        }

        return $entity->get('published') === true
            ? AccessResult::allowed('Endpoint is published.')
            : AccessResult::neutral('Endpoint is not published.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }
}
