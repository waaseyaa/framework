<?php

declare(strict_types=1);

namespace Waaseyaa\Routing\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\EntityDeepLinkRouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Integration tests for EntityDeepLinkRouteBuilder + WaaseyaaRouter.
 *
 * Scope: route building, registration, and URL matching.
 *
 * Out-of-scope (require full kernel boot — see WP10 E2E tests):
 *   - Entity hydration via EntityParamConverter (needs EntityTypeManager)
 *   - 404 response when entity ID is not found in storage
 *   - 401/403 access-policy enforcement via AccessChecker
 *
 * Those behaviours are covered by the production-shaped WP10 E2E suite rather
 * than represented here by permanently skipped placeholder tests.
 */
#[CoversNothing]
final class EntityDeepLinkResolutionTest extends TestCase
{
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->router = new WaaseyaaRouter();
    }

    #[Test]
    public function routeIsAddedAndMatchedByRouter(): void
    {
        $route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\Controller\NodeController::edit')
            ->build();

        $this->router->addRoute('node.edit', $route);

        $params = $this->router->match('/edit/node/1');

        $this->assertSame('node.edit', $params['_route']);
        $this->assertSame('1', $params['id']);
    }

    #[Test]
    public function matchedParamsIncludeControllerDefault(): void
    {
        $route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\Controller\NodeController::edit')
            ->build();

        $this->router->addRoute('node.edit', $route);

        $params = $this->router->match('/edit/node/42');

        $this->assertSame('App\Controller\NodeController::edit', $params['_controller']);
        $this->assertSame('42', $params['id']);
    }

    #[Test]
    public function differentEntityTypeSegmentsAreDistinct(): void
    {
        $nodeRoute = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\Controller\NodeController::edit')
            ->build();

        $userRoute = EntityDeepLinkRouteBuilder::for('/edit', 'user')
            ->controller('App\Controller\UserController::edit')
            ->build();

        $this->router->addRoute('node.edit', $nodeRoute);
        $this->router->addRoute('user.edit', $userRoute);

        $nodeParams = $this->router->match('/edit/node/10');
        $userParams = $this->router->match('/edit/user/20');

        $this->assertSame('node.edit', $nodeParams['_route']);
        $this->assertSame('10', $nodeParams['id']);

        $this->assertSame('user.edit', $userParams['_route']);
        $this->assertSame('20', $userParams['id']);
    }

    #[Test]
    public function nonMatchingPathThrowsRouteNotFoundException(): void
    {
        $route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\Controller\NodeController::edit')
            ->build();

        $this->router->addRoute('node.edit', $route);

        $this->expectException(RouteNotFoundException::class);
        $this->router->match('/view/node/1');
    }

    #[Test]
    public function routeHasEntityParameterOptionSet(): void
    {
        $route = EntityDeepLinkRouteBuilder::for('/edit', 'node')
            ->controller('App\Controller\NodeController::edit')
            ->build();

        $this->router->addRoute('node.edit', $route);

        $collection = $this->router->getRouteCollection();
        $registeredRoute = $collection->get('node.edit');

        $this->assertNotNull($registeredRoute);
        $parameters = $registeredRoute->getOption('parameters');
        $this->assertIsArray($parameters);
        $this->assertSame(['type' => 'entity:node'], $parameters['id']);
    }
}
