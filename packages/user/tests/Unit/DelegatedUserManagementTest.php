<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;
use Waaseyaa\User\UserManagementDelegateInterface;

final class DelegatedUserManagementTest extends TestCase
{
    public function testDefaultPolicyRetainsTheReportedClerkBlocker(): void
    {
        $policy = new UserAccessPolicy();
        $clerk = $this->principal();
        $target = new User(['uid' => 20]);
        self::assertFalse($policy->createAccess('user', 'user', $clerk)->isAllowed());
        self::assertFalse($policy->access($target, 'update', $clerk)->isAllowed());
        self::assertTrue($policy->fieldAccess($target, 'status', 'edit', $clerk)->isForbidden());
    }

    public function testDelegateAdmitsOnlyItsImmutableActorAndTarget(): void
    {
        $policy = new UserAccessPolicy($this->delegate());
        $handler = new EntityAccessHandler([$policy]);
        $target = new User(['uid' => 20]);
        self::assertFalse($policy->createAccess('user', 'user', $this->principal())->isAllowed());
        self::assertTrue($handler->check($target, 'update', $this->principal())->isAllowed());
        self::assertFalse($handler->check(new User(['uid' => 21]), 'update', $this->principal())->isAllowed());
        self::assertFalse($handler->check($target, 'update', $this->principal(11))->isAllowed());
        self::assertFalse($policy->createAccess('node', 'page', $this->principal())->isAllowed());
        self::assertFalse($policy->createAccess('user', 'other', $this->principal())->isAllowed());
        $mutable = $this->createStub(AccountInterface::class);
        $mutable->method('id')->willReturn(10);
        $mutable->method('isAuthenticated')->willReturn(true);
        self::assertFalse($policy->access($target, 'update', $mutable)->isAllowed());
        self::assertFalse($policy->createAccess('user', 'user', $mutable)->isAllowed());
        self::assertFalse($policy->createAccess('user', 'user', new AuthorizationPrincipal(0, false, [], [], 'anonymous'))->isAllowed());
    }

    public function testStatusNeedsBothUpdateAndStatusDelegation(): void
    {
        $target = new User(['uid' => 20]);
        $clerk = $this->principal();
        $policy = new UserAccessPolicy($this->delegate());
        self::assertTrue($policy->fieldAccess($target, 'status', 'edit', $clerk)->isForbidden());
        self::assertTrue($policy->access($target, 'change_active_status', $clerk)->isAllowed());
        self::assertTrue($policy->fieldAccess(new User(['uid' => 21]), 'status', 'edit', $clerk)->isForbidden());
        $readOnly = new UserAccessPolicy($this->delegate(false));
        self::assertTrue($readOnly->fieldAccess($target, 'status', 'edit', $clerk)->isForbidden());
        self::assertFalse($readOnly->access($target, 'change_active_status', $clerk)->isAllowed());
    }

    public function testDelegateNeverGrantsPrivilegeCredentialsOrDeletion(): void
    {
        $policy = new UserAccessPolicy($this->delegate());
        $target = new User(['uid' => 20]);
        foreach (['roles', 'permissions', 'email_verified', 'pass', 'legacy_pass', 'session_generation', 'two_factor_secret'] as $field) {
            self::assertTrue($policy->fieldAccess($target, $field, 'edit', $this->principal())->isForbidden(), $field);
        }
        self::assertFalse($policy->access($target, 'delete', $this->principal())->isAllowed());
    }

    private function principal(int $id = 10): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($id, true, ['clerk'], ['sfn_manage_members'], 'delegate-test');
    }

    private function delegate(bool $updates = true): UserManagementDelegateInterface
    {
        return new class ($updates) implements UserManagementDelegateInterface {
            public function __construct(private readonly bool $updates) {}
            public function canUpdate(User $target, AuthorizationPrincipalInterface $actor): bool
            {
                return $this->updates && $actor->id() === 10 && $target->id() === 20;
            }
            public function canChangeActiveStatus(User $target, AuthorizationPrincipalInterface $actor): bool
            {
                return true;
            }
        };
    }
}
