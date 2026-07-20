<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Access;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** @internal */
final readonly class WorkflowAuthorityProtectedReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(
        private WorkflowAuthorityVisibility $visibility,
    ) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        unset($subject);

        if ($operation !== 'view') {
            return AccessResult::neutral('Workflow authority grants entity view only.');
        }

        return $this->visibility->allows(
            $structure->entityTypeId,
            $structure->bundleId,
            $structure->id,
            $principal,
        )
            ? AccessResult::allowed('A current outgoing workflow transition grants working-copy visibility.')
            : AccessResult::neutral('No authorized current outgoing workflow transition grants visibility.');
    }
}
