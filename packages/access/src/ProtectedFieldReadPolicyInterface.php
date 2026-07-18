<?php

declare(strict_types=1);

namespace Waaseyaa\Access;

use Waaseyaa\Entity\EntityStructure;

/**
 * Fail-closed policy seam dedicated to Protected field reads.
 * Neutral is interpreted as denial by the future evaluator.
 *
 * @api
 */
interface ProtectedFieldReadPolicyInterface
{
    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult;
}
