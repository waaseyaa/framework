<?php

declare(strict_types=1);

namespace Waaseyaa\Oidc\Access;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Exact admin-only release policy for the Protected client display name. @api */
final class OidcClientProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        if ($structure->entityTypeId !== 'oidc_client' || $fieldName !== 'name' || $subject->fields() !== []) {
            return AccessResult::forbidden('OIDC client field policy cannot release this field.');
        }

        return $principal->hasPermission('administer oidc clients')
            ? AccessResult::allowed('Administrator may read the OIDC client display name.')
            : AccessResult::forbidden('OIDC client names are administrator-only.');
    }
}
