<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Unit;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AccessChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

#[CoversClass(AccessChecker::class)]
final class AccessCheckerTest extends TestCase
{
    private AccessChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new AccessChecker();
    }

    // --- No requirements ---

    #[Test]
    public function noRequirementsReturnsNeutral(): void
    {
        $route = new Route('/test');
        $account = $this->createMock(AccountInterface::class);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isNeutral());
    }

    // --- _public ---

    #[Test]
    public function publicTrueReturnsAllowed(): void
    {
        $route = new Route('/test');
        $route->setOption('_public', true);
        $account = $this->createMock(AccountInterface::class);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    // --- _permission ---

    #[Test]
    public function permissionGrantedReturnsAllowed(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(true);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function permissionDeniedReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(false);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    // --- _role ---

    #[Test]
    public function roleMatchReturnsAllowed(): void
    {
        $route = new Route('/admin');
        $route->setOption('_role', 'administrator');

        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')
            ->willReturn(['authenticated', 'administrator']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function roleMismatchReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_role', 'administrator');

        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')
            ->willReturn(['authenticated']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function multipleRolesAnyMatchReturnsAllowed(): void
    {
        $route = new Route('/admin');
        $route->setOption('_role', 'administrator, editor');

        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')
            ->willReturn(['authenticated', 'editor']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function multipleRolesNoneMatchReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_role', 'administrator, editor');

        $account = $this->createMock(AccountInterface::class);
        $account->method('getRoles')
            ->willReturn(['authenticated']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    // --- _authenticated ---

    #[Test]
    public function authenticatedOptionWithAuthenticatedAccountReturnsAllowed(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function authenticatedOptionWithAnonymousAccountReturnsUnauthenticated(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isUnauthenticated());
        $this->assertStringContainsString('Authentication is required', $result->reason);
    }

    #[Test]
    public function authenticatedOptionShortCircuitsBeforePermissionCheck(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);
        $route->setOption('_permission', 'access content');

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);
        // hasPermission should never be called — short-circuited.
        $account->expects($this->never())->method('hasPermission');

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isUnauthenticated());
    }

    #[Test]
    public function authenticatedWithPermissionBothCheckedWhenAuthenticated(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_authenticated', true);
        $route->setOption('_permission', 'access content');

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);
        $account->method('hasPermission')->with('access content')->willReturn(true);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    // --- Combined requirements (AND logic) ---

    #[Test]
    public function combinedPermissionAndRoleBothPassReturnsAllowed(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_role', 'administrator');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(true);
        $account->method('getRoles')
            ->willReturn(['administrator']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function combinedPermissionPassesButRoleFailsReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_role', 'administrator');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(true);
        $account->method('getRoles')
            ->willReturn(['authenticated']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function combinedPermissionFailsButRolePassesReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_permission', 'administer site');
        $route->setOption('_role', 'administrator');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(false);
        $account->method('getRoles')
            ->willReturn(['administrator']);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }

    // --- _public + _authenticated interaction ---

    #[Test]
    public function publicWithAuthenticatedRejectsAnonymous(): void
    {
        // _authenticated short-circuits before _public has any effect.
        $route = new Route('/api/node');
        $route->setOption('_public', true);
        $route->setOption('_authenticated', true);

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(false);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isUnauthenticated());
    }

    #[Test]
    public function publicWithAuthenticatedAllowsAuthenticated(): void
    {
        $route = new Route('/api/node');
        $route->setOption('_public', true);
        $route->setOption('_authenticated', true);

        $account = $this->createMock(AccountInterface::class);
        $account->method('isAuthenticated')->willReturn(true);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function publicWithPermissionBothPass(): void
    {
        $route = new Route('/admin');
        $route->setOption('_public', true);
        $route->setOption('_permission', 'administer site');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(true);

        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function publicTrueWithPermissionDeniedReturnsForbidden(): void
    {
        $route = new Route('/admin');
        $route->setOption('_public', true);
        $route->setOption('_permission', 'administer site');

        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')
            ->with('administer site')
            ->willReturn(false);

        $result = $this->checker->check($route, $account);

        // AND logic: public = allowed AND _permission = forbidden => forbidden
        $this->assertTrue($result->isForbidden());
    }

    #[Test]
    public function sessionOptionTrueAllowsWhenSessionActive(): void
    {
        // Start a session for this test
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }

        $route = new Route('/chat');
        $route->setOption('_session', true);

        $account = $this->createMock(AccountInterface::class);
        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());
    }

    #[Test]
    public function sessionOptionWithRequiredKeysAllowsWhenPresent(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION['nickname'] = 'guest42';

        $route = new Route('/chat');
        $route->setOption('_session', ['nickname']);

        $account = $this->createMock(AccountInterface::class);
        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isAllowed());

        unset($_SESSION['nickname']);
    }

    #[Test]
    public function sessionOptionWithRequiredKeysDeniesWhenMissing(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            @session_start();
        }
        unset($_SESSION['nickname']);

        $route = new Route('/chat');
        $route->setOption('_session', ['nickname']);

        $account = $this->createMock(AccountInterface::class);
        $result = $this->checker->check($route, $account);

        $this->assertTrue($result->isForbidden());
    }
}
