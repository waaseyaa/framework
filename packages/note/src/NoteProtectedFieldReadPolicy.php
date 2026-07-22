<?php

declare(strict_types=1);

namespace Waaseyaa\Note;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityViewProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityStructure;

/** Delegates Protected note ownership release to the complete entity-view policy set. @internal */
final class NoteProtectedFieldReadPolicy implements EntityViewProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        return $structure->entityTypeId === 'note' && $fieldName === 'uid'
            ? AccessResult::neutral('Protected note authorship delegates to the complete entity view decision.')
            : AccessResult::forbidden('Note protected policy applies only to note authorship.');
    }
}
