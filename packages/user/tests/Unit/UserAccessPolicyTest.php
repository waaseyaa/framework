<?php

declare(strict_types=1);

namespace Waaseyaa\User\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\User\User;
use Waaseyaa\User\UserAccessPolicy;

#[CoversClass(UserAccessPolicy::class)]
final class UserAccessPolicyTest extends TestCase
{
    private UserAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserAccessPolicy();
    }

    // -----------------------------------------------------------------
    // Interface and appliesTo
    // -----------------------------------------------------------------

    public function testImplementsAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(AccessPolicyInterface::class, $this->policy);
    }

    public function testIsFinal(): void
    {
        $reflection = new \ReflectionClass(UserAccessPolicy::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testAppliesToUser(): void
    {
        $this->assertTrue($this->policy->appliesTo('user'));
    }

    public function testDoesNotApplyToOtherEntityTypes(): void
    {
        $this->assertFalse($this->policy->appliesTo('node'));
        $this->assertFalse($this->policy->appliesTo('media'));
        $this->assertFalse($this->policy->appliesTo(''));
    }

    // -----------------------------------------------------------------
    // View: admin bypass
    // -----------------------------------------------------------------

    public function testViewWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: own account
    // -----------------------------------------------------------------

    public function testViewOwnAccountAllowed(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createPrincipal(5, ['access user profiles']);

        $result = new EntityAccessHandler([$this->policy])->check($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: other active account with permission
    // -----------------------------------------------------------------

    public function testViewOtherActiveAccountWithPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createPrincipal(10, ['access user profiles']);

        $result = new EntityAccessHandler([$this->policy])->check($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // View: other active account without permission
    // -----------------------------------------------------------------

    public function testViewOtherActiveAccountWithoutPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $account = $this->createPrincipal(10, []);

        $result = new EntityAccessHandler([$this->policy])->check($user, 'view', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // View: blocked account
    // -----------------------------------------------------------------

    public function testViewBlockedAccountDeniedForNonAdmin(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 0]);
        $account = $this->createPrincipal(10, ['access user profiles']);

        $result = new EntityAccessHandler([$this->policy])->check($user, 'view', $account);
        $this->assertTrue($result->isForbidden());
    }

    public function testViewBlockedAccountAllowedForAdmin(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 0]);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'view', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update: own account
    // -----------------------------------------------------------------

    public function testUpdateOwnAccount(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(5, []);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    // -----------------------------------------------------------------
    // Update: other account
    // -----------------------------------------------------------------

    public function testUpdateOtherAccountWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testUpdateOtherAccountWithoutAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'update', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------

    public function testDeleteWithAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testDeleteWithoutAdminPermission(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isNeutral());
    }

    public function testDeleteOwnAccountDenied(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(5, ['administer users']);

        $result = $this->policy->access($user, 'delete', $account);
        $this->assertTrue($result->isForbidden());
    }

    // -----------------------------------------------------------------
    // Create access
    // -----------------------------------------------------------------

    public function testCreateAccessWithAdminPermission(): void
    {
        $account = $this->createAccount(1, ['administer users']);

        $result = $this->policy->createAccess('user', 'user', $account);
        $this->assertTrue($result->isAllowed());
    }

    public function testCreateAccessWithoutAdminPermission(): void
    {
        $account = $this->createAccount(5, []);

        $result = $this->policy->createAccess('user', 'user', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Unknown operation
    // -----------------------------------------------------------------

    public function testUnknownOperationReturnsNeutral(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice']);
        $account = $this->createAccount(10, []);

        $result = $this->policy->access($user, 'unknown_op', $account);
        $this->assertTrue($result->isNeutral());
    }

    // -----------------------------------------------------------------
    // Field access — B-1: JSON:API mass-assignment privilege escalation
    // -----------------------------------------------------------------

    public function testImplementsFieldAccessPolicyInterface(): void
    {
        $this->assertInstanceOf(FieldAccessPolicyInterface::class, $this->policy);
    }

    /**
     * The core B-1 invariant. `access('update')` ALLOWS a non-admin to edit their own
     * account, but the field gate must FORBID a privileged field — so the JSON:API write
     * path (which 403s when checkFieldAccess() is Forbidden) refuses a self-PATCH of
     * `roles: ['administrator']`. Without this, any authenticated user escalates to admin.
     */
    public function testNonAdminCannotEditOwnRoles(): void
    {
        $user = new User(['uid' => 5, 'name' => 'alice', 'status' => 1]);
        $selfNonAdmin = $this->createAccount(5, []);

        $this->assertTrue($this->policy->access($user, 'update', $selfNonAdmin)->isAllowed());
        $this->assertTrue($this->policy->fieldAccess($user, 'roles', 'edit', $selfNonAdmin)->isForbidden());
    }

    #[DataProvider('privilegedFieldProvider')]
    public function testNonAdminCannotEditPrivilegedField(string $field): void
    {
        $user = new User(['uid' => 5]);
        $nonAdmin = $this->createAccount(5, []);

        $this->assertTrue($this->policy->fieldAccess($user, $field, 'edit', $nonAdmin)->isForbidden());
    }

    /** @return list<array{string}> */
    public static function privilegedFieldProvider(): array
    {
        return [['roles'], ['permissions'], ['status'], ['email_verified']];
    }

    public function testAdminMayEditPrivilegedFields(): void
    {
        $user = new User(['uid' => 5]);
        $admin = $this->createAccount(1, ['administer users']);

        $this->assertFalse($this->policy->fieldAccess($user, 'roles', 'edit', $admin)->isForbidden());
        $this->assertFalse($this->policy->fieldAccess($user, 'permissions', 'edit', $admin)->isForbidden());
    }

    #[DataProvider('credentialFieldProvider')]
    public function testCredentialFieldsForbiddenForEveryone(string $field, string $operation): void
    {
        $user = new User(['uid' => 5]);
        // Even an administrator cannot touch credential / 2FA material through the generic surface.
        $admin = $this->createAccount(1, ['administer users']);

        $this->assertTrue($this->policy->fieldAccess($user, $field, $operation, $admin)->isForbidden());
    }

    /** @return list<array{string, string}> */
    public static function credentialFieldProvider(): array
    {
        return [
            ['pass', 'edit'],
            ['pass', 'view'],
            ['two_factor_secret', 'edit'],
            ['two_factor_secret', 'view'],
            ['two_factor_recovery_codes_hash', 'edit'],
        ];
    }

    public function testNonPrivilegedFieldStaysOpenByDefault(): void
    {
        $user = new User(['uid' => 5]);
        $nonAdmin = $this->createAccount(5, []);

        // Ordinary fields are not restricted — field access stays open-by-default.
        $this->assertFalse($this->policy->fieldAccess($user, 'mail', 'edit', $nonAdmin)->isForbidden());
        // Privilege fields are only locked for 'edit'; viewing a role list is not restricted here.
        $this->assertFalse($this->policy->fieldAccess($user, 'roles', 'view', $nonAdmin)->isForbidden());
    }

    /**
     * Wiring check: the forbid result surfaces through EntityAccessHandler, which is the
     * path JsonApiController actually calls — and which only consults policies that are
     * instanceof FieldAccessPolicyInterface.
     */
    public function testEntityAccessHandlerForbidsRoleEscalation(): void
    {
        $handler = new EntityAccessHandler([$this->policy]);
        $user = new User(['uid' => 5]);
        $nonAdmin = $this->createAccount(5, []);

        $this->assertTrue($handler->checkFieldAccess($user, 'roles', 'edit', $nonAdmin)->isForbidden());
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    /** @param string[] $permissions */
    private function createAccount(int $id, array $permissions): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        $account->method('hasPermission')->willReturnCallback(
            fn(string $permission): bool => \in_array($permission, $permissions, true),
        );

        return $account;
    }

    /** @param list<string> $permissions */
    private function createPrincipal(int $id, array $permissions): AuthorizationPrincipal
    {
        return new AuthorizationPrincipal($id, true, [], $permissions, 'test-claims-1');
    }
}
