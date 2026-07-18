<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/**
 * Non-recursive User entity-view policy for field-read activation.
 *
 * @api
 */
final class UserEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        if ($structure->entityTypeId !== 'user' || $operation !== 'view') {
            return AccessResult::neutral('User V2 policy has no opinion on this subject or operation.');
        }

        if ($subject->fields() !== ['status']) {
            return AccessResult::forbidden('User profile visibility requires the exact compiled status input.');
        }

        if ($principal->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        if (!self::isActive($subject->get('status'))) {
            return AccessResult::forbidden('Inactive user profiles are administrator-only.');
        }

        if ($principal->hasPermission('access user profiles')) {
            return AccessResult::allowed('Active user profile is visible to a profile viewer.');
        }

        return AccessResult::neutral('User lacks "access user profiles" permission.');
    }

    private static function isActive(mixed $status): bool
    {
        return $status === true || $status === 1 || $status === '1';
    }
}
