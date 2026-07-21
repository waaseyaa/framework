<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityViewProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityStructure;

/** Delegates Protected media release to the complete entity-view policy set. @internal */
final class MediaProtectedFieldReadPolicy implements EntityViewProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        return $structure->entityTypeId === 'media'
            ? AccessResult::neutral('Protected media fields delegate to the complete entity view decision.')
            : AccessResult::forbidden('Media protected policy applies only to protected media fields.');
    }
}
