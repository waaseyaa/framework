<?php

declare(strict_types=1);

namespace Waaseyaa\GraphQL;

use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers the /graphql endpoint route.
 */
final class GraphQlRouteProvider
{
    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'graphql.endpoint',
            RouteBuilder::create('/graphql')
                ->controller('graphql.endpoint')
                ->allowAll()
                ->methods('GET', 'POST')
                ->build(),
        );
    }
}
