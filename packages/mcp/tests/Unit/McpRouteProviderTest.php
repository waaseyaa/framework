<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\McpRouteProvider;
use Waaseyaa\Mcp\Auth\OAuthProtectedResourceMetadataConfig;
use Waaseyaa\Routing\WaaseyaaRouter;

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
    public function writeEndpointPinsPublicRouterAndCsrfExemptContract(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider()->registerRoutes($router);

        $route = $router->getRouteCollection()->get('mcp.endpoint.write');

        self::assertNotNull($route);
        self::assertSame('/mcp/write', $route->getPath());
        self::assertSame(['POST', 'GET'], $route->getMethods());
        self::assertTrue($route->getOption('_public'));
        self::assertFalse($route->getOption('_csrf'));
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

    #[Test]
    public function oauth_protected_resource_metadata_is_registered_only_when_configured(): void
    {
        $router = new WaaseyaaRouter();
        new McpRouteProvider(false, new OAuthProtectedResourceMetadataConfig(
            'https://cms.example/mcp/write',
            ['https://identity.example'],
        ))->registerRoutes($router);

        $routes = $router->getRouteCollection();
        self::assertNull($routes->get('mcp.endpoint'));
        $metadata = $routes->get('mcp.oauth_protected_resource');
        self::assertNotNull($metadata);
        self::assertSame('/.well-known/oauth-protected-resource/mcp/write', $metadata->getPath());
        self::assertSame(['GET'], $metadata->getMethods());
        self::assertTrue($metadata->getOption('_public'));
    }
}
