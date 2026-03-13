<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use Waaseyaa\Mcp\McpRouteProvider;
use Waaseyaa\Routing\WaaseyaaRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpRouteProvider::class)]
final class McpRouteProviderTest extends TestCase
{
    #[Test]
    public function registerRoutesAddsMcpEndpointRoute(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $endpointRoute = $routes->get('mcp.endpoint');

        $this->assertNotNull($endpointRoute);
        $this->assertSame('/mcp', $endpointRoute->getPath());
        $this->assertContains('POST', $endpointRoute->getMethods());
        $this->assertContains('GET', $endpointRoute->getMethods());
    }

    #[Test]
    public function registerRoutesAddsServerCardRoute(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertNotNull($cardRoute);
        $this->assertSame('/.well-known/mcp.json', $cardRoute->getPath());
        $this->assertContains('GET', $cardRoute->getMethods());
    }

    #[Test]
    public function mcpEndpointRouteIsCsrfExempt(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();
        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $mcpRoute = $routes->get('mcp.endpoint');

        $this->assertNotNull($mcpRoute, 'mcp.endpoint route should be registered');
        $this->assertFalse($mcpRoute->getOption('_csrf'), 'MCP endpoint should be CSRF exempt');
    }

    #[Test]
    public function serverCardRouteIsPublic(): void
    {
        $router = new WaaseyaaRouter();
        $provider = new McpRouteProvider();

        $provider->registerRoutes($router);

        $routes = $router->getRouteCollection();
        $cardRoute = $routes->get('mcp.server_card');

        $this->assertTrue($cardRoute->getOption('_public'));
    }
}
