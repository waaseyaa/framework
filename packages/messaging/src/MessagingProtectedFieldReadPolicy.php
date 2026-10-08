<?php

declare(strict_types=1);

namespace Waaseyaa\Messaging;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Entity\EntityStructure;

/** Releases messaging fields only to a participant of their containing thread. @api */
final readonly class MessagingProtectedFieldReadPolicy implements ProtectedFieldReadPolicyInterface
{
    public function __construct(private MessagingAccessPolicy $policy) {}

    public function access(
        AuthorizationPrincipalInterface $principal,
        EntityStructure $structure,
        PolicySubjectViewInterface $subject,
        string $fieldName,
    ): AccessResult {
        return $this->policy->protectedViewAccess($principal, $structure, $subject);
    }
}
