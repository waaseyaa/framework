<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProjectedProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/**
 * Non-recursive User entity-view policy for field-read activation.
 *
 * @api
 */
final class UserEntityReadPolicy implements ProjectedProtectedEntityReadPolicyInterface
{
    public function authorizationInputs(): array
    {
        return ['status'];
    }

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
        // A projected SQL subject reflects the physically stored value, so a
        // legacy row with no status key supplies null here. A sealed hydrated
        // subject may instead omit that absent input and fail the exact-shape
        // check above; direct User construction can apply the active default.
        // Neither query path may synthesize that permissive default. The only
        // accepted decision difference is the independent administrator grant.
        return $status === true || $status === 1 || $status === '1';
    }
}
