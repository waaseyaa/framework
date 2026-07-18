<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityStructure;

/**
 * Fail-closed V2 entity-read policy over immutable principal and compiled subject inputs.
 *
 * @api
 */
interface ProtectedEntityReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult;
}
