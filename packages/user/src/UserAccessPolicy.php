<?php

declare(strict_types=1);

namespace Waaseyaa\User;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for user entities.
 *
 * - View: active accounts visible with 'access user profiles'; blocked accounts admin-only.
 * - Update: own account allowed; others need admin or an exact consumer delegate.
 * - Delete: admin-only; self-deletion is forbidden.
 * - Create: admin-only; consent-aware provisioning is a separate boundary.
 * - Field edit: privilege-bearing fields (roles, permissions, status, email_verified)
 *   require 'administer users' even on one's own account, including delegated managers; credential / 2FA material
 *   is never reachable through the generic field surface. This closes the JSON:API
 *   mass-assignment escalation where a non-admin self-PATCHes `roles: ['administrator']`
 *   — and the same surface must not accept `legacy_pass`, a password equivalent.
 */
#[PolicyAttribute(entityType: 'user')]
final class UserAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    public function __construct(private readonly ?UserManagementDelegateInterface $managementDelegate = null) {}

    /**
     * Privilege-bearing fields a non-administrator must never edit — not even on their
     * own account. These need 'administer users';
     * without it the field is Forbidden, which is what `EntityAccessHandler::checkFieldAccess()`
     * (and therefore the JSON:API write path) rejects on.
     *
     * @var list<string>
     */
    private const array PRIVILEGED_EDIT_FIELDS = ['roles', 'permissions', 'status', 'email_verified'];

    /**
     * Credential and second-factor material — never reachable (read or write) through the
     * generic field surface, for anyone, including administrators. Password changes go
     * through {@see User::setRawPassword()}; 2FA material through the dedicated two-factor flow.
     *
     * @var list<string>
     */
    private const array CREDENTIAL_FIELDS = ['pass', 'legacy_pass', 'two_factor_secret', 'two_factor_recovery_codes_hash', 'two_factor_last_used_step', 'session_generation'];

    public function protectedEntityReadPolicy(): ProtectedEntityReadPolicyInterface
    {
        return new UserEntityReadPolicy();
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new UserProtectedFieldReadPolicy();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'user';
    }

    /** @param AccountInterface $account Direct legacy callers still require runtime principal narrowing. */
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer users')) {
            // Admin can do everything except delete themselves.
            if ($operation === 'delete' && $account->id() === $entity->id()) {
                return AccessResult::forbidden('Cannot delete own account.');
            }

            return AccessResult::allowed('User has "administer users" permission.');
        }

        assert($entity instanceof User);

        if ($operation === 'update' && $account instanceof AuthorizationPrincipalInterface
            && $account->isAuthenticated()
            && $this->managementDelegate?->canUpdate($entity, $account)) {
            return AccessResult::allowed('Consumer delegate admits this actor and account target.');
        }

        if ($operation === 'change_active_status' && $account instanceof AuthorizationPrincipalInterface
            && $account->isAuthenticated()
            && $this->managementDelegate?->canUpdate($entity, $account)
            && $this->managementDelegate->canChangeActiveStatus($entity, $account)) {
            return AccessResult::allowed('Consumer delegate admits the dedicated account lifecycle operation.');
        }

        return match ($operation) {
            'view' => $this->viewAccess($entity, $account),
            'update' => $this->updateAccess($entity, $account),
            'delete' => AccessResult::neutral('Only administrators can delete accounts.'),
            default => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer users')) {
            return AccessResult::allowed('User has "administer users" permission.');
        }

        return AccessResult::neutral('Only administrators can create accounts.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        // Credential / 2FA material is opaque to the generic field surface entirely.
        if (in_array($fieldName, self::CREDENTIAL_FIELDS, true)) {
            return AccessResult::forbidden(
                sprintf("Field '%s' is not accessible through the generic field API.", $fieldName),
            );
        }

        // Privilege-bearing fields are editable only with 'administer users' — so a user
        // editing their own account (allowed by updateAccess) still cannot grant themselves
        // roles/permissions/active status. Non-'edit' operations stay open-by-default.
        if ($operation === 'edit' && in_array($fieldName, self::PRIVILEGED_EDIT_FIELDS, true)) {
            if ($account->hasPermission('administer users')) {
                return AccessResult::neutral(sprintf("'administer users' may edit '%s'.", $fieldName));
            }

            return AccessResult::forbidden(
                sprintf("Editing '%s' requires the 'administer users' permission.", $fieldName),
            );
        }

        return AccessResult::neutral();
    }

    private function viewAccess(User $user, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Non-administrator profile reads require the V2 immutable principal and policy-subject path.');
    }

    private function updateAccess(User $user, AccountInterface $account): AccessResult
    {
        if ($account->id() === $user->id()) {
            return AccessResult::allowed('Users can edit their own account.');
        }

        return AccessResult::neutral('Only administrators or account owners can edit accounts.');
    }
}
