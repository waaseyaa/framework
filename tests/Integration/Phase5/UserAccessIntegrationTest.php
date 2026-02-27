<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Phase5;

use Aurora\Access\AccessPolicyInterface;
use Aurora\Access\AccessResult;
use Aurora\Access\AccountInterface;
use Aurora\Access\EntityAccessHandler;
use Aurora\Access\PermissionHandler;
use Aurora\Entity\EntityInterface;
use Aurora\User\AnonymousUser;
use Aurora\User\Role;
use Aurora\User\User;
use Aurora\User\UserSession;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for aurora/user + aurora/access interaction.
 *
 * Verifies that User entities, AnonymousUser, roles, and permissions
 * integrate correctly with EntityAccessHandler and PermissionHandler.
 */
#[CoversNothing]
final class UserAccessIntegrationTest extends TestCase
{
    private PermissionHandler $permissionHandler;
    private EntityAccessHandler $accessHandler;

    protected function setUp(): void
    {
        // Set up the permission handler with known permissions.
        $this->permissionHandler = new PermissionHandler();
        $this->permissionHandler->registerPermission(
            'view user profiles',
            'View user profiles',
            'Allows viewing other user profile pages.',
        );
        $this->permissionHandler->registerPermission(
            'administer users',
            'Administer users',
            'Full control over user accounts.',
        );
        $this->permissionHandler->registerPermission(
            'access content',
            'Access content',
            'View published content.',
        );

        // Set up the entity access handler with a permission-based policy.
        $this->accessHandler = new EntityAccessHandler([
            new PermissionBasedUserAccessPolicy(),
        ]);
    }

    // ---- User + Permission tests ----

    public function testUserWithPermissionCanAccessEntity(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['view user profiles', 'administer users'],
            'roles' => ['administrator'],
        ]);

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'view', $user);

        $this->assertTrue($result->isAllowed(), 'User with "view user profiles" should be allowed to view.');
    }

    public function testUserWithoutPermissionGetsForbidden(): void
    {
        $user = new User([
            'uid' => 3,
            'name' => 'editor',
            'permissions' => ['access content'],
            'roles' => ['editor'],
        ]);

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'view', $user);

        $this->assertFalse(
            $result->isAllowed(),
            'User without "view user profiles" should not be allowed to view.',
        );
    }

    public function testAnonymousUserGetsForbiddenForProtectedOperations(): void
    {
        $anonymous = new AnonymousUser();

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'view', $anonymous);

        $this->assertFalse(
            $result->isAllowed(),
            'Anonymous user without permissions should not be able to view user profiles.',
        );
        $this->assertFalse($anonymous->isAuthenticated());
    }

    public function testAnonymousUserWithPermissionCanAccessPublicRoutes(): void
    {
        $anonymous = new AnonymousUser(['view user profiles']);

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'view', $anonymous);

        $this->assertTrue(
            $result->isAllowed(),
            'Anonymous user with explicit "view user profiles" permission should be allowed.',
        );
        $this->assertFalse($anonymous->isAuthenticated());
    }

    public function testAdminUserCanDeleteEntity(): void
    {
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer users'],
            'roles' => ['administrator'],
        ]);

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'delete', $admin);

        $this->assertTrue($result->isAllowed(), 'Admin should be able to delete users.');
    }

    public function testNonAdminCannotDeleteEntity(): void
    {
        $regularUser = new User([
            'uid' => 3,
            'name' => 'regular',
            'permissions' => ['view user profiles'],
            'roles' => ['authenticated'],
        ]);

        $targetUser = new User([
            'uid' => 2,
            'name' => 'target',
        ]);

        $result = $this->accessHandler->check($targetUser, 'delete', $regularUser);

        $this->assertFalse(
            $result->isAllowed(),
            'Non-admin user should not be able to delete users.',
        );
    }

    // ---- PermissionHandler integration tests ----

    public function testPermissionHandlerRegisteredPermissionsAreAvailable(): void
    {
        $this->assertTrue($this->permissionHandler->hasPermission('view user profiles'));
        $this->assertTrue($this->permissionHandler->hasPermission('administer users'));
        $this->assertTrue($this->permissionHandler->hasPermission('access content'));
        $this->assertFalse($this->permissionHandler->hasPermission('nonexistent permission'));
    }

    public function testPermissionHandlerReturnsAllPermissions(): void
    {
        $permissions = $this->permissionHandler->getPermissions();

        $this->assertCount(3, $permissions);
        $this->assertArrayHasKey('view user profiles', $permissions);
        $this->assertArrayHasKey('administer users', $permissions);
        $this->assertArrayHasKey('access content', $permissions);
    }

    // ---- Role integration ----

    public function testUserRolesIntegrateWithAccessChecking(): void
    {
        $editorRole = new Role(
            id: 'editor',
            label: 'Editor',
            permissions: ['view user profiles', 'access content'],
        );

        // Simulate role-to-permissions resolution.
        $user = new User([
            'uid' => 5,
            'name' => 'editor_user',
            'roles' => [$editorRole->id],
            'permissions' => $editorRole->permissions,
        ]);

        $this->assertSame(['editor'], $user->getRoles());
        $this->assertTrue($user->hasPermission('view user profiles'));
        $this->assertTrue($user->hasPermission('access content'));
        $this->assertFalse($user->hasPermission('administer users'));

        $targetUser = new User(['uid' => 10, 'name' => 'someone']);
        $result = $this->accessHandler->check($targetUser, 'view', $user);
        $this->assertTrue($result->isAllowed());
    }

    // ---- UserSession integration ----

    public function testUserSessionDefaultsToAnonymous(): void
    {
        $session = new UserSession();

        $this->assertFalse($session->isAuthenticated());
        $this->assertInstanceOf(AnonymousUser::class, $session->getAccount());
    }

    public function testUserSessionWithAuthenticatedUser(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer users'],
        ]);

        $session = new UserSession($user);

        $this->assertTrue($session->isAuthenticated());
        $this->assertSame($user, $session->getAccount());
    }

    public function testUserSessionCanSwitchAccounts(): void
    {
        $session = new UserSession();
        $this->assertFalse($session->isAuthenticated());

        $user = new User([
            'uid' => 7,
            'name' => 'switcher',
            'permissions' => ['access content'],
        ]);

        $session->setAccount($user);
        $this->assertTrue($session->isAuthenticated());
        $this->assertSame(7, $session->getAccount()->id());
    }

    // ---- Create access ----

    public function testCheckCreateAccessWithPermission(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer users'],
        ]);

        $result = $this->accessHandler->checkCreateAccess('user', 'user', $user);

        $this->assertTrue($result->isAllowed(), 'User with administer users should be able to create users.');
    }

    public function testCheckCreateAccessWithoutPermission(): void
    {
        $user = new User([
            'uid' => 5,
            'name' => 'regular',
            'permissions' => ['access content'],
        ]);

        $result = $this->accessHandler->checkCreateAccess('user', 'user', $user);

        $this->assertFalse($result->isAllowed(), 'User without administer users should not create users.');
    }

    // ---- Multiple policies ----

    public function testForbiddenPolicyOverridesAllowed(): void
    {
        $handler = new EntityAccessHandler([
            new PermissionBasedUserAccessPolicy(),
            new AlwaysForbiddenPolicy(),
        ]);

        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['view user profiles', 'administer users'],
        ]);

        $target = new User(['uid' => 2, 'name' => 'target']);
        $result = $handler->check($target, 'view', $admin);

        $this->assertTrue($result->isForbidden(), 'Forbidden policy should always win.');
    }
}

// ---- Supporting test doubles ----

/**
 * Access policy for user entities based on permissions.
 */
class PermissionBasedUserAccessPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return match ($operation) {
            'view' => $account->hasPermission('view user profiles')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "view user profiles" permission.'),
            'update' => $account->hasPermission('administer users')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "administer users" permission.'),
            'delete' => $account->hasPermission('administer users')
                ? AccessResult::allowed()
                : AccessResult::neutral('Missing "administer users" permission.'),
            default => AccessResult::neutral("Unknown operation: {$operation}"),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if ($account->hasPermission('administer users')) {
            return AccessResult::allowed();
        }

        return AccessResult::neutral('Missing "administer users" permission.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'user';
    }
}

/**
 * A policy that always forbids access.
 */
class AlwaysForbiddenPolicy implements AccessPolicyInterface
{
    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        return AccessResult::forbidden('Access is always denied by this policy.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        return AccessResult::forbidden('Access is always denied by this policy.');
    }

    public function appliesTo(string $entityTypeId): bool
    {
        return true;
    }
}
