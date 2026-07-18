<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityStructure;

#[PolicyAttribute(entityType: 'user_block')]
final class UserBlockAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new UserBlockEntityReadPolicy();
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new UserBlockFieldReadPolicy();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'user_block';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if ($entity instanceof EntityBase) {
            return AccessResult::neutral('Framework UserBlock access requires the V2 immutable principal and policy-subject path.');
        }

        $blockerId = $entity->get('blocker_id');

        if ($blockerId !== null && (int) $blockerId === (int) $account->id()) {
            return AccessResult::allowed('Blocker may manage own blocks.');
        }

        return AccessResult::neutral('Only the blocker may access this block.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if ($account->isAuthenticated()) {
            return AccessResult::allowed('Authenticated users may create blocks.');
        }

        return AccessResult::neutral('Anonymous users cannot create blocks.');
    }
}

/** @api */
final class UserBlockEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($structure->entityTypeId !== 'user_block' || $subject->fields() !== ['blocker_id']) {
            return AccessResult::forbidden('UserBlock access requires the exact compiled blocker input.');
        }
        if ($principal->hasPermission('administer content')) {
            return AccessResult::allowed('Administrator may access UserBlock records.');
        }

        return (string) $subject->get('blocker_id') === (string) $principal->id()
            ? AccessResult::allowed('Blocker may access their own block record.')
            : AccessResult::forbidden('UserBlock records are restricted to their blocker or an administrator.');
    }
}

/** @api */
final class UserBlockFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'user_block') {
            return AccessResult::forbidden('UserBlock field policy cannot release another entity type.');
        }
        if ($principal->hasPermission('administer content')) {
            return AccessResult::allowed('Administrator may read UserBlock fields.');
        }
        if ($fieldName === 'blocker_id') {
            return AccessResult::forbidden('The blocker selector is administrator-readable only.');
        }
        if ($subject->fields() !== ['blocker_id']) {
            return AccessResult::forbidden('UserBlock field access requires the exact compiled blocker input.');
        }

        return (string) $subject->get('blocker_id') === (string) $principal->id()
            ? AccessResult::allowed('Blocker may read their own block record fields.')
            : AccessResult::forbidden('UserBlock fields are restricted to their blocker or an administrator.');
    }
}
