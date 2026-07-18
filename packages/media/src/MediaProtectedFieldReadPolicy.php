<?php

declare(strict_types=1);

namespace Waaseyaa\Media;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Closed owner/admin policy for protected Media fields. @internal */
final class MediaProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'media' || !in_array($fieldName, ['uid', 'source_uri'], true)) {
            return AccessResult::forbidden('Media protected policy applies only to protected media fields.');
        }
        if ($principal->hasPermission('administer media')) {
            return AccessResult::allowed('Media administrators may read protected media fields.');
        }
        $ownerId = in_array('uid', $subject->fields(), true) ? $subject->get('uid') : null;

        return $principal->isAuthenticated() && $ownerId !== null && (string) $principal->id() === (string) $ownerId
            ? AccessResult::allowed('Media owners may read protected media fields.')
            : AccessResult::forbidden('Protected media fields require owner or administrator access.');
    }
}
