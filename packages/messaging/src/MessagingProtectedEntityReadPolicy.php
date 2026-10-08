<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Participant-only Protected entity visibility. @api */
final readonly class MessagingProtectedEntityReadPolicy implements ProtectedEntityReadPolicyInterface
{
    public function __construct(private MessagingAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $operation,
    ): AccessResult {
        return $operation === 'view'
            ? $this->policy->protectedViewAccess($principal, $structure, $subject)
            : AccessResult::neutral();
    }
}
