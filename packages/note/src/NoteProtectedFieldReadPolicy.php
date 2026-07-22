<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Closed protected note view and authorship decision. @internal */
final class NoteProtectedFieldReadPolicy implements ProtectedEntityReadPolicyInterface, ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operationOrField,
    ): AccessResult {
        if ($structure->entityTypeId !== 'note') {
            return AccessResult::forbidden('Note protected policy applies only to note subjects.');
        }

        if (!in_array($operationOrField, ['view', 'uid'], true)) {
            return AccessResult::neutral("Note protected policy has no opinion on '$operationOrField'.");
        }

        if ($principal->hasPermission('administer notes')) {
            return AccessResult::allowed('Note administrators may read note rows and protected authorship.');
        }

        $uid = in_array('uid', $subject->fields(), true) ? $subject->get('uid') : null;
        $isOwner = $principal->isAuthenticated()
            && $uid !== null
            && (string) $principal->id() === (string) $uid;

        if (array_intersect(['platform.admin', 'tenant.admin', 'tenant.member'], $principal->getRoles()) !== []) {
            return AccessResult::allowed('Authenticated tenant members may read note rows and authorship.');
        }

        if ($isOwner && $principal->hasPermission('view own note content')) {
            return AccessResult::allowed('The note author may read their own row and authorship.');
        }

        return AccessResult::neutral('Protected note view was not granted.');
    }
}
