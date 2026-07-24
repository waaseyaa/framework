<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/**
 * Exact fail-closed release policy for User Protected fields.
 *
 * @api
 */
final class UserProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'user') {
            return AccessResult::forbidden('User field policy cannot release another entity type.');
        }

        return match ($fieldName) {
            'status' => $this->statusAccess($principal, $structure, $subject),
            'name' => $this->nameAccess($principal, $subject),
            'consent_date', 'consent_on_file', 'must_reset_password', 'disabled'
                => $this->administrativeStateAccess($principal, $subject),
            default => AccessResult::forbidden(sprintf("User field '%s' is not a Protected release surface.", $fieldName)),
        };
    }

    private function statusAccess(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
    ): AccessResult {
        if ($subject->fields() !== []) {
            return AccessResult::forbidden('Direct status reads accept no policy subject inputs.');
        }

        if ($principal->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        if ($structure->id !== null && (string) $principal->id() === (string) $structure->id) {
            return AccessResult::allowed('Users may read their own account status.');
        }

        return AccessResult::forbidden('User status is readable only by self or an administrator.');
    }

    private function nameAccess(
        AuthorizationPrincipalInterface $principal,
        PolicySubjectViewInterface $subject,
    ): AccessResult {
        if ($subject->fields() !== ['status']) {
            return AccessResult::forbidden('User name visibility requires the exact compiled status input.');
        }

        if ($principal->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        if ($subject->get('status') !== true) {
            return AccessResult::forbidden('Inactive user names are administrator-only.');
        }

        if ($principal->hasPermission('access user profiles')) {
            return AccessResult::allowed('Active user name is visible to a profile viewer.');
        }

        return AccessResult::forbidden('Reading a User name requires profile-view permission.');
    }

    private function administrativeStateAccess(
        AuthorizationPrincipalInterface $principal,
        PolicySubjectViewInterface $subject,
    ): AccessResult {
        if ($subject->fields() !== []) {
            return AccessResult::forbidden('Administrative User state reads accept no policy subject inputs.');
        }
        if ($principal->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        return AccessResult::forbidden('Administrative User state requires "administer users".');
    }
}
