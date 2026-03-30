<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Contract;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\Tests\Contract\AccessPolicyContractTest;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

/**
 * UserAccessPolicy casts the entity to User in access() for view/update.
 * We provide a real User instance to satisfy the assertion.
 */
final class UserAccessPolicyContractTest extends AccessPolicyContractTest
{
    protected function createPolicy(): AccessPolicyInterface
    {
        return new UserAccessPolicy();
    }

    protected function getApplicableEntityTypeId(): string
    {
        return 'user';
    }

    protected function createEntityStub(): EntityInterface
    {
        return new User([
            'uid' => 42,
            'name' => 'Test User',
            'status' => 1,
            'roles' => [],
            'permissions' => [],
        ]);
    }
}
