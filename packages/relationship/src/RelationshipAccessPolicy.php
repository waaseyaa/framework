<?php

declare(strict_types=1);

namespace Waaseyaa\Relationship;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityValues;

#[PolicyAttribute(entityType: 'relationship')]
final class RelationshipAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private readonly RelationshipMaintenanceReader $maintenanceReader;

    public function __construct(?RelationshipMaintenanceReader $maintenanceReader = null)
    {
        $this->maintenanceReader = $maintenanceReader ?? new RelationshipMaintenanceReader();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'relationship';
    }

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return null;
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new RelationshipProtectedFieldReadPolicy();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        $status = $entity instanceof Relationship
            ? $this->maintenanceReader->read($entity)->status
            : EntityValues::statusToInt($entity->get('status'));

        return match ($operation) {
            'view' => $status === 1 && $account->hasPermission('access content')
                ? AccessResult::allowed('Published relationship view allowed.')
                : AccessResult::neutral('Relationship view denied.'),
            'update' => $account->hasPermission('edit any relationship content')
                ? AccessResult::allowed('User has edit any relationship content permission.')
                : AccessResult::neutral('Relationship update denied.'),
            'delete' => $account->hasPermission('delete any relationship content')
                ? AccessResult::allowed('User has delete any relationship content permission.')
                : AccessResult::neutral('Relationship delete denied.'),
            default => AccessResult::neutral(),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer nodes')) {
            return AccessResult::allowed('User has administer nodes permission.');
        }

        if ($account->hasPermission('create relationship content')) {
            return AccessResult::allowed('User has create relationship content permission.');
        }

        return AccessResult::neutral('Relationship create denied.');
    }
}
