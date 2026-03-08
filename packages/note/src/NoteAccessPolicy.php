<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Access policy for the built-in Note content type.
 *
 * core.note is non-deletable via API — this policy enforces that
 * unconditionally, regardless of account role or permissions.
 * Disable/lifecycle management is handled via #198.
 */
#[PolicyAttribute(entityType: 'note')]
final class NoteAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'note';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if ($operation === 'delete') {
            return AccessResult::forbidden('core.note is a built-in type and cannot be deleted. See #198 for lifecycle management.');
        }

        return AccessResult::neutral("No opinion on '$operation' operation.");
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer notes')) {
            return AccessResult::allowed('User has administer notes permission.');
        }

        if ($account->hasPermission('create note content')) {
            return AccessResult::allowed("User has 'create note content' permission.");
        }

        return AccessResult::neutral('User lacks note creation permission.');
    }
}
