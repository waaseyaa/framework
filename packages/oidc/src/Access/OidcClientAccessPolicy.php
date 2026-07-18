<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for OidcClient entities.
 *
 * Entity operations (view/update/delete/create) are gated by the
 * `administer oidc clients` permission. Authenticated non-admin users and
 * anonymous callers cannot enumerate, read, or mutate clients via the
 * entity API. The OIDC authorize/token endpoints must look up clients via
 * a dedicated storage-bypass service (not through entity access checks) to
 * validate client_id + redirect_uri without exposing the entity CRUD surface.
 *
 * Field-level rules:
 *  - `client_secret_hash` is Forbidden for all view operations (the hash
 *    is never exposed through the API, even to admins — rotation is
 *    write-only).
 *  - `client_id` is Forbidden for edit on existing clients (immutable
 *    public identifier per OIDC spec); allowed on new entities so admins
 *    can set it at creation.
 *  - All other fields are Neutral (open-by-default per field policy
 *    semantics).
 */
#[PolicyAttribute(entityType: 'oidc_client')]
final class OidcClientAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private const ADMIN_PERMISSION = 'administer oidc clients';

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return null;
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new OidcClientProtectedFieldReadPolicy();
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'oidc_client';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission(self::ADMIN_PERMISSION)) {
            return AccessResult::allowed('User has "administer oidc clients" permission.');
        }

        return AccessResult::neutral('OIDC clients are admin-only.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission(self::ADMIN_PERMISSION)) {
            return AccessResult::allowed('User has "administer oidc clients" permission.');
        }

        return AccessResult::neutral('OIDC clients are admin-only.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($fieldName === 'client_secret_hash' && $operation === 'view') {
            return AccessResult::forbidden('client_secret_hash is never exposed through the API.');
        }

        if ($fieldName === 'client_id' && $operation === 'edit' && !$entity->isNew()) {
            return AccessResult::forbidden('client_id is immutable after creation.');
        }

        return AccessResult::neutral('No field-level restriction.');
    }
}
