<?php

declare(strict_types=1);

namespace Waaseyaa\Groups\StaffDirectory;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Access\ProtectedEntityReadPolicyInterface;
use Waaseyaa\Access\ProtectedFieldReadPolicyInterface;
use Waaseyaa\Access\ProtectedReadPolicyProviderInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;

/** Adds only the declared contextual User entity-read policy; never field authority. @internal */
#[PolicyAttribute(entityType: 'user')]
final class CapabilityScopedStaffDirectoryAccessPolicy implements AccessPolicyInterface, ProtectedReadPolicyProviderInterface
{
    private readonly ?CapabilityScopedStaffDirectoryReadPolicy $entityReadPolicy;

    public function __construct(DatabaseInterface $database, ?StaffDirectoryReadDeclaration $declaration = null)
    {
        $this->entityReadPolicy = $declaration === null
            ? null
            : new CapabilityScopedStaffDirectoryReadPolicy($database, $declaration);
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'user';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral('Staff directory reads require the contextual coordinator.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::neutral();
    }

    public function protectedEntityReadPolicy(): ?ProtectedEntityReadPolicyInterface
    {
        return $this->entityReadPolicy;
    }

    public function protectedFieldReadPolicy(): ?ProtectedFieldReadPolicyInterface
    {
        return null;
    }
}
