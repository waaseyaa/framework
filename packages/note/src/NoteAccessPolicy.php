<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for the built-in Note content type.
 *
 * Entity-level (deny-by-default):
 *   - tenant.member  : view, plus permission-scoped own-row operations
 *   - tenant.admin   : view + create + update + delete
 *   - platform.admin : view + create + update + delete (all fields including system)
 *   - administer notes: full row administration (the canonical administrator has every permission)
 *   - anonymous      : neutral (denied by EntityAccessHandler's isAllowed() check)
 *
 * Field-level (open-by-default, Forbidden restricts):
 *   - System fields (id, uuid, created_at, updated_at):
 *     edit forbidden for everyone except platform.admin.
 *   - User fields (title, body): neutral for all — no restriction.
 */
#[PolicyAttribute(entityType: 'note')]
final class NoteAccessPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    /** Fields that are always read-only for non-platform.admin roles. */
    private const ALWAYS_READONLY_FIELDS = ['id', 'uuid', 'created_at', 'updated_at'];

    /** @var \Closure(EntityBase): (int|string|null) */
    private readonly \Closure $ownerId;

    public function __construct()
    {
        $this->ownerId = \Closure::bind(
            static function (EntityBase $entity): int|string|null {
                try {
                    $ownerId = $entity->valueContainer->entityPolicySubjectView()->get('uid');
                } catch (\LogicException) {
                    // A legacy/synthetic Note shape without the reviewed uid
                    // authorization input cannot establish ownership.
                    return null;
                }

                return is_int($ownerId) || is_string($ownerId) ? $ownerId : null;
            },
            null,
            EntityBase::class,
        );
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'note';
    }

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return null;
    }

    public function protectedFieldReadPolicy(): ProtectedFieldReadPolicyInterface
    {
        return new NoteProtectedFieldReadPolicy();
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer notes')) {
            return AccessResult::allowed('Account may administer note rows.');
        }

        $isOwner = $this->isOwner($entity, $account);

        return match ($operation) {
            'view'   => $this->viewAccess($account, $isOwner),
            'update' => $this->updateAccess($account, $isOwner),
            'delete' => $this->deleteAccess($account, $isOwner),
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
            if ($fieldName === 'uid') {
                if ($account->hasPermission('administer notes')) {
                    return AccessResult::neutral('Note administrators may assign authorship.');
                }

                return AccessResult::forbidden('Note authorship is server-managed.');
            }

            $isSystemField = in_array($fieldName, self::ALWAYS_READONLY_FIELDS, true);

            if ($isSystemField) {
                if ($this->hasRole('platform.admin', $account)) {
                    return AccessResult::neutral('platform.admin may edit system fields.');
                }

                return AccessResult::forbidden("System field '$fieldName' is read-only.");
            }
        }

        return AccessResult::neutral();
    }

    private function viewAccess(AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($this->hasRole('platform.admin', $account)
            || $this->hasRole('tenant.admin', $account)
            || $this->hasRole('tenant.member', $account)
        ) {
            return AccessResult::allowed('Authenticated tenant member can view notes.');
        }

        if ($isOwner && $account->hasPermission('view own note content')) {
            return AccessResult::allowed('Author may view own note.');
        }

        return AccessResult::neutral('Account has no tenant role; view not granted.');
    }

    private function updateAccess(AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($this->hasRole('platform.admin', $account) || $this->hasRole('tenant.admin', $account)) {
            return AccessResult::allowed('Account can update notes.');
        }

        if ($isOwner && $account->hasPermission('edit own note content')) {
            return AccessResult::allowed('Author may update own note.');
        }

        return AccessResult::neutral('tenant.member cannot update notes.');
    }

    private function deleteAccess(AccountInterface $account, bool $isOwner): AccessResult
    {
        if ($this->hasRole('platform.admin', $account) || $this->hasRole('tenant.admin', $account)) {
            return AccessResult::allowed('Account can delete note rows.');
        }

        if ($isOwner && $account->hasPermission('delete own note content')) {
            return AccessResult::allowed('Author may delete own note.');
        }

        return AccessResult::neutral('Account cannot delete this note.');
    }

    private function isOwner(EntityInterface $entity, AccountInterface $account): bool
    {
        if (!$account->isAuthenticated() || !$entity instanceof EntityBase) {
            return false;
        }

        $ownerId = ($this->ownerId)($entity);

        return $ownerId !== null && (string) $ownerId === (string) $account->id();
    }

    private function hasRole(string $role, AccountInterface $account): bool
    {
        return in_array($role, $account->getRoles(), true);
    }
}
