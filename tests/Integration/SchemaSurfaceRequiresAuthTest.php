<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Waaseyaa\Access\AccessChecker;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * The REST schema self-description surface must require authentication.
 *
 * `GET /api/schema/{entity_type}` and `GET /api/openapi.json` were registered
 * option-less, so `AccessChecker` returned Neutral and `AuthorizationMiddleware`
 * passed anonymous callers through (200). That enumerated every registered
 * entity type plus its attribute/field schema to anonymous — and because schema
 * field-access is computed against a value-less prototype entity, it disclosed
 * the *definitions* of instance-state-gated fields (e.g. classification-gated
 * fields) that a real row would deny. These two routes are the self-description
 * of an API whose data routes are already auth-gated (#1649 auth-gated the `/api`
 * discovery index for the same reason), so leaving them public is inconsistent.
 *
 * They now carry `_authenticated`, so an anonymous caller gets 401 and an
 * authenticated caller still gets 200. SCOPE: only these two REST routes change.
 * The public read-only `/mcp` surface and the `/.well-known/waaseyaa-anchors.json`
 * agent catalog are registered by other providers and stay public — they are the
 * intended anonymous agent-readable entry points.
 */
#[CoversNothing]
final class SchemaSurfaceRequiresAuthTest extends TestCase
{
    private WaaseyaaRouter $router;

    private AccessChecker $accessChecker;

    protected function setUp(): void
    {
        $this->router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        new BuiltinRouteRegistrar(new EntityTypeManager(new EventDispatcher()))->register($this->router);
        $this->accessChecker = new AccessChecker();
    }

    /**
     * @return list<array{0: string}>
     */
    public static function schemaRoutes(): array
    {
        return [['api.schema.show'], ['api.openapi']];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('schemaRoutes')]
    public function schema_route_requires_authentication(string $routeName): void
    {
        $route = $this->routeNamed($routeName);

        // Config pin: the route now carries _authenticated. Pre-fix this option
        // was absent (null) and the route fell through to Neutral pass-through.
        self::assertTrue(
            $route->getOption('_authenticated'),
            "{$routeName} must require authentication (audit: anonymous schema/OpenAPI enumeration).",
        );
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('schemaRoutes')]
    public function anonymous_is_denied_authenticated_passes(string $routeName): void
    {
        $route = $this->routeNamed($routeName);

        // Anonymous → unauthenticated (AuthorizationMiddleware maps this to 401).
        // Pre-fix: no _authenticated option ⇒ Neutral ⇒ pass-through (200).
        self::assertTrue(
            $this->accessChecker->check($route, $this->anonymous())->isUnauthenticated(),
            "{$routeName} must reject an anonymous caller (401).",
        );

        // Authenticated → not unauthenticated (passes the gate ⇒ 200).
        self::assertFalse(
            $this->accessChecker->check($route, $this->authenticated())->isUnauthenticated(),
            "{$routeName} must still serve an authenticated caller (200).",
        );
    }

    private function routeNamed(string $name): Route
    {
        $route = $this->router->getRouteCollection()->get($name);
        self::assertNotNull($route, "BuiltinRouteRegistrar must register {$name}.");

        return $route;
    }

    private function anonymous(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 0;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['anonymous'];
            }

            public function isAuthenticated(): bool
            {
                return false;
            }
        };
    }

    private function authenticated(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int
            {
                return 1;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return ['authenticated'];
            }

            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }
}
