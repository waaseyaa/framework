<?php

declare(strict_types=1);

namespace Waaseyaa\Groups;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Exact generic-read policy for Protected group authorization settings. @api */
final class GroupProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'group' || $fieldName !== 'members_can_view_directory') {
            return AccessResult::forbidden('Group field policy cannot release this field.');
        }
        if ($subject->fields() !== []) {
            return AccessResult::forbidden('Group directory visibility reads accept no policy subject inputs.');
        }

        return $principal->hasPermission(GroupAccessPolicy::ADMIN_PERMISSION)
            ? AccessResult::allowed('Group administrators may read directory visibility configuration.')
            : AccessResult::forbidden('Group directory visibility configuration is administrator-only.');
    }
}
