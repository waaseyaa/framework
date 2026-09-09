<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\User\RegisteredRoleAssignment;
use Waaseyaa\User\RegisteredRoleAssignmentService;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;

#[CoversClass(RegisteredRoleAssignmentService::class)]
#[CoversClass(RegisteredRoleAssignment::class)]
final class RegisteredRoleAssignmentServiceTest extends TestCase
{
    private function service(): RegisteredRoleAssignmentService
    {
        return new RegisteredRoleAssignmentService(new RoleRepository([
            new Role('editor', 'Editor', ['edit', 'publish']),
            new Role('reviewer', 'Reviewer', ['review', 'publish']),
        ]));
    }

    #[Test]
    public function assignmentReplacesRegisteredSiblingAndPreservesUnknownMembership(): void
    {
        $actual = $this->service()->change(['editor', 'vip'], 'reviewer');

        self::assertSame(['vip', 'reviewer'], $actual->roles);
        self::assertSame(['review', 'publish'], $actual->permissions);
    }

    #[Test]
    public function removalRecomputesPermissionUnionFromRemainingRegisteredRoles(): void
    {
        $actual = $this->service()->change(['editor', 'reviewer', 'vip'], 'reviewer', remove: true);

        self::assertSame(['editor', 'vip'], $actual->roles);
        self::assertSame(['edit', 'publish'], $actual->permissions);
    }

    #[Test]
    public function unknownRoleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->change([], 'missing');
    }
}
