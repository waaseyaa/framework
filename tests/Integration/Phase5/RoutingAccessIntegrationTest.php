<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Phase5;

use Aurora\Access\AccessResult;
use Aurora\Routing\AccessChecker;
use Aurora\Routing\AuroraRouter;
use Aurora\Routing\RouteBuilder;
use Aurora\Routing\RouteMatch;
use Aurora\User\AnonymousUser;
use Aurora\User\User;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for aurora/routing + aurora/access + aurora/user.
 *
 * Verifies that AuroraRouter, RouteBuilder, and AccessChecker work together
 * to enforce permission-based, role-based, and public route access.
 */
#[CoversNothing]
final class RoutingAccessIntegrationTest extends TestCase
{
    private AuroraRouter $router;
    private AccessChecker $accessChecker;

    protected function setUp(): void
    {
        $this->router = new AuroraRouter();
        $this->accessChecker = new AccessChecker();

        // Register routes using RouteBuilder.
        $this->router->addRoute(
            'user.profile',
            RouteBuilder::create('/user/{id}')
                ->controller('UserController::view')
                ->requirePermission('view user profiles')
                ->requirement('id', '\d+')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'admin.dashboard',
            RouteBuilder::create('/admin/dashboard')
                ->controller('AdminController::dashboard')
                ->requirePermission('administer site')
                ->requireRole('administrator')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'homepage',
            RouteBuilder::create('/home')
                ->controller('PageController::home')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'content.view',
            RouteBuilder::create('/content/{slug}')
                ->controller('ContentController::view')
                ->requirePermission('access content')
                ->methods('GET')
                ->build(),
        );

        $this->router->addRoute(
            'admin.users',
            RouteBuilder::create('/admin/users')
                ->controller('AdminController::users')
                ->requireRole('administrator')
                ->methods('GET')
                ->build(),
        );
    }

    // ---- Route matching tests ----

    public function testRouterMatchesUserProfileRoute(): void
    {
        $params = $this->router->match('/user/42');

        $this->assertSame('user.profile', $params['_route']);
        $this->assertSame('42', $params['id']);
    }

    public function testRouterMatchesHomepageRoute(): void
    {
        $params = $this->router->match('/home');

        $this->assertSame('homepage', $params['_route']);
    }

    public function testRouterMatchesContentRoute(): void
    {
        $params = $this->router->match('/content/hello-world');

        $this->assertSame('content.view', $params['_route']);
        $this->assertSame('hello-world', $params['slug']);
    }

    public function testRouterGeneratesUrl(): void
    {
        $url = $this->router->generate('user.profile', ['id' => 42]);

        $this->assertSame('/user/42', $url);
    }

    // ---- Permission-based access tests ----

    public function testUserWithPermissionCanAccessPermissionProtectedRoute(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'viewer',
            'permissions' => ['view user profiles'],
            'roles' => ['authenticated'],
        ]);

        $params = $this->router->match('/user/42');
        $route = $this->getRouteByName('user.profile');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue($result->isAllowed(), 'User with "view user profiles" should access user profile route.');
    }

    public function testUserWithoutPermissionCannotAccessPermissionProtectedRoute(): void
    {
        $user = new User([
            'uid' => 2,
            'name' => 'basic',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        $route = $this->getRouteByName('user.profile');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue(
            $result->isForbidden(),
            'User without "view user profiles" should be forbidden from user profile route.',
        );
    }

    public function testAnonymousUserCannotAccessPermissionProtectedRoute(): void
    {
        $anonymous = new AnonymousUser();

        $route = $this->getRouteByName('user.profile');
        $result = $this->accessChecker->check($route, $anonymous);

        $this->assertTrue(
            $result->isForbidden(),
            'Anonymous user should be forbidden from user profile route.',
        );
    }

    // ---- Role-based access tests ----

    public function testUserWithRequiredRoleCanAccessRoleProtectedRoute(): void
    {
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => [],
            'roles' => ['administrator'],
        ]);

        $route = $this->getRouteByName('admin.users');
        $result = $this->accessChecker->check($route, $admin);

        $this->assertTrue($result->isAllowed(), 'User with administrator role should access admin.users route.');
    }

    public function testUserWithoutRequiredRoleCannotAccessRoleProtectedRoute(): void
    {
        $user = new User([
            'uid' => 2,
            'name' => 'editor',
            'permissions' => [],
            'roles' => ['editor'],
        ]);

        $route = $this->getRouteByName('admin.users');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue(
            $result->isForbidden(),
            'User without administrator role should be forbidden from admin.users route.',
        );
    }

    // ---- Combined permission + role tests ----

    public function testRouteRequiringBothPermissionAndRoleAllowsQualifiedUser(): void
    {
        $admin = new User([
            'uid' => 1,
            'name' => 'admin',
            'permissions' => ['administer site'],
            'roles' => ['administrator'],
        ]);

        $route = $this->getRouteByName('admin.dashboard');
        $result = $this->accessChecker->check($route, $admin);

        $this->assertTrue(
            $result->isAllowed(),
            'User with both required permission and role should access admin.dashboard.',
        );
    }

    public function testRouteRequiringBothPermissionAndRoleForbidsUserWithOnlyPermission(): void
    {
        $user = new User([
            'uid' => 2,
            'name' => 'partial',
            'permissions' => ['administer site'],
            'roles' => ['editor'],
        ]);

        $route = $this->getRouteByName('admin.dashboard');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue(
            $result->isForbidden(),
            'User with permission but wrong role should be forbidden from admin.dashboard.',
        );
    }

    public function testRouteRequiringBothPermissionAndRoleForbidsUserWithOnlyRole(): void
    {
        $user = new User([
            'uid' => 3,
            'name' => 'role_only',
            'permissions' => [],
            'roles' => ['administrator'],
        ]);

        $route = $this->getRouteByName('admin.dashboard');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue(
            $result->isForbidden(),
            'User with role but missing permission should be forbidden from admin.dashboard.',
        );
    }

    // ---- Public route tests ----

    public function testPublicRouteAllowsAnonymousUser(): void
    {
        $anonymous = new AnonymousUser();

        $route = $this->getRouteByName('homepage');
        $result = $this->accessChecker->check($route, $anonymous);

        $this->assertTrue($result->isAllowed(), 'Anonymous user should access public homepage.');
    }

    public function testPublicRouteAllowsAuthenticatedUser(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'user',
            'permissions' => [],
            'roles' => ['authenticated'],
        ]);

        $route = $this->getRouteByName('homepage');
        $result = $this->accessChecker->check($route, $user);

        $this->assertTrue($result->isAllowed(), 'Authenticated user should access public homepage.');
    }

    // ---- Anonymous with specific permissions ----

    public function testAnonymousWithPermissionCanAccessContentRoute(): void
    {
        $anonymous = new AnonymousUser(['access content']);

        $route = $this->getRouteByName('content.view');
        $result = $this->accessChecker->check($route, $anonymous);

        $this->assertTrue(
            $result->isAllowed(),
            'Anonymous user with "access content" should be able to view content.',
        );
    }

    // ---- RouteMatch value object ----

    public function testRouteMatchHoldsMatchedParameters(): void
    {
        $route = $this->getRouteByName('user.profile');
        $match = new RouteMatch('user.profile', $route, ['id' => '42']);

        $this->assertSame('user.profile', $match->routeName);
        $this->assertSame('42', $match->getParameter('id'));
        $this->assertTrue($match->hasParameter('id'));
        $this->assertFalse($match->hasParameter('nonexistent'));
        $this->assertNull($match->getParameter('nonexistent'));
    }

    // ---- End-to-end route match + access check ----

    public function testEndToEndMatchAndCheckAccess(): void
    {
        $user = new User([
            'uid' => 1,
            'name' => 'viewer',
            'permissions' => ['access content'],
            'roles' => ['authenticated'],
        ]);

        // Simulate the full flow: match URL -> extract route -> check access.
        $params = $this->router->match('/content/about-us');
        $routeName = $params['_route'];
        $route = $this->getRouteByName($routeName);
        $result = $this->accessChecker->check($route, $user);

        $this->assertSame('content.view', $routeName);
        $this->assertSame('about-us', $params['slug']);
        $this->assertTrue($result->isAllowed());
    }

    // ---- URL generation ----

    public function testUrlGenerationForVariousRoutes(): void
    {
        $this->assertSame('/user/1', $this->router->generate('user.profile', ['id' => 1]));
        $this->assertSame('/admin/dashboard', $this->router->generate('admin.dashboard'));
        $this->assertSame('/home', $this->router->generate('homepage'));
        $this->assertSame('/content/my-article', $this->router->generate('content.view', ['slug' => 'my-article']));
    }

    /**
     * Helper: Retrieve a Route object by name from the router.
     */
    private function getRouteByName(string $name): \Symfony\Component\Routing\Route
    {
        $collection = $this->router->getRouteCollection();
        $route = $collection->get($name);
        $this->assertNotNull($route, "Route '{$name}' should exist.");

        return $route;
    }
}
