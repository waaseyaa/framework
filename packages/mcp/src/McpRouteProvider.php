<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final readonly class McpRouteProvider
{
    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'mcp.endpoint',
            RouteBuilder::create('/mcp')
                ->controller('Waaseyaa\\Mcp\\McpEndpoint::serve')
                ->methods('POST', 'GET')
                ->allowAll()
                ->csrfExempt()
                ->build(),
        );

        // Authenticated MCP write tier (FR-004): a SEPARATE route from the public
        // read-only `/mcp`, so the alpha.221 trio is untouched (C-001). The route
        // is open at the HTTP layer; authentication is enforced inside the inner
        // McpEndpoint by the bearer-token auth strategy (fail-closed: 401 without
        // a valid token), exactly as `/mcp` authenticates anonymously.
        $router->addRoute(
            'mcp.endpoint.write',
            RouteBuilder::create('/mcp/write')
                ->controller('Waaseyaa\\Mcp\\AuthenticatedMcpEndpoint::serve')
                ->methods('POST', 'GET')
                ->allowAll()
                ->csrfExempt()
                ->build(),
        );

        $router->addRoute(
            'mcp.server_card',
            RouteBuilder::create('/.well-known/mcp.json')
                ->controller('Waaseyaa\\Mcp\\McpServerCard::serve')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );
    }
}
