<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Routing\RouteBuilder;

final readonly class McpRouteProvider
{
    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'mcp.endpoint',
            RouteBuilder::create('/mcp')
                ->controller('Waaseyaa\\Mcp\\McpEndpoint::handle')
                ->methods('POST', 'GET')
                ->csrfExempt()
                ->build(),
        );

        $router->addRoute(
            'mcp.server_card',
            RouteBuilder::create('/.well-known/mcp.json')
                ->controller('Waaseyaa\\Mcp\\McpServerCard::toJson')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );
    }
}
