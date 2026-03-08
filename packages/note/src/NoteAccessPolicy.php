<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for the built-in Note content type.
 *
 * Entity-level (deny-by-default):
 *   - tenant.member  : view only
 *   - tenant.admin   : view + create + update
 *   - platform.admin : view + create + update (all fields including system)
 *   - anonymous      : neutral (denied by EntityAccessHandler's isAllowed() check)
 *   - DELETE          : unconditionally forbidden — core.note is non-deletable via API
 *
 * Field-level (open-by-default, Forbidden restricts):
 *   - System fields (id, uuid, tenant_id, created_at, updated_at):
 *     edit forbidden for everyone except platform.admin.
 *   - User fields (title, body): neutral for all — no restriction.
 */
#[PolicyAttribute(entityType: 'note')]
final class NoteAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    /**
     * Fields that are always read-only after creation for non-platform.admin roles.
     * 'tenant_id' is settable on creation (identifying the owning tenant) but
     * immutable on update — enforced via the isNew() check in fieldAccess().
     */
    private const ALWAYS_READONLY_FIELDS = ['id', 'uuid', 'created_at', 'updated_at'];

    /** Settable on creation, immutable on update for non-platform.admin roles. */
    private const CREATE_ONLY_FIELDS = ['tenant_id'];

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'note';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'delete') {
            return AccessResult::forbidden(
                '[DEFAULT_TYPE_NOT_DELETABLE] core.note is a built-in type and cannot be deleted via API. '
                . 'To disable it temporarily, use `waaseyaa type:disable note`.',
            );
        }

        return match ($operation) {
            'view'   => $this->viewAccess($account),
            'update' => $this->updateAccess($account),
            default  => AccessResult::neutral("No opinion on '$operation' operation."),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($this->hasRole('platform.admin', $account)) {
            return AccessResult::allowed('platform.admin can create notes.');
        }

        if ($this->hasRole('tenant.admin', $account)) {
            return AccessResult::allowed('tenant.admin can create notes.');
        }

        if ($account->hasPermission('administer notes')) {
            return AccessResult::allowed('User has administer notes permission.');
        }

        if ($account->hasPermission('create note content')) {
            return AccessResult::allowed("User has 'create note content' permission.");
        }

        return AccessResult::neutral('User lacks note creation permission.');
    }

    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        if ($operation === 'edit') {
            $isSystemField = in_array($fieldName, self::ALWAYS_READONLY_FIELDS, true)
                || (!$entity->isNew() && in_array($fieldName, self::CREATE_ONLY_FIELDS, true));

            if ($isSystemField) {
                if ($this->hasRole('platform.admin', $account)) {
                    return AccessResult::neutral('platform.admin may edit system fields.');
                }

                return AccessResult::forbidden("System field '$fieldName' is read-only.");
            }
        }

        return AccessResult::neutral();
    }

    private function viewAccess(AccountInterface $account): AccessResult
    {
        if ($this->hasRole('platform.admin', $account)
            || $this->hasRole('tenant.admin', $account)
            || $this->hasRole('tenant.member', $account)
        ) {
            return AccessResult::allowed('Authenticated tenant member can view notes.');
        }

        return AccessResult::neutral('Account has no tenant role; view not granted.');
    }

    private function updateAccess(AccountInterface $account): AccessResult
    {
        if ($this->hasRole('platform.admin', $account) || $this->hasRole('tenant.admin', $account)) {
            return AccessResult::allowed('Account can update notes.');
        }

        return AccessResult::neutral('tenant.member cannot update notes.');
    }

    private function hasRole(string $role, AccountInterface $account): bool
    {
        return in_array($role, $account->getRoles(), true);
    }
}
