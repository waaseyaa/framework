<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Mcp\Auth\BearerTokenAuth;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\McpServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(McpServiceProvider::class)]
final class McpServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_mcp_routes_through_the_package_service_provider(): void
    {
        $router = new WaaseyaaRouter();
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());

        new McpServiceProvider()->routes($router, $entityTypeManager);

        $routes = $router->getRouteCollection();
        self::assertNotNull($routes->get('mcp.endpoint'));
        self::assertNotNull($routes->get('mcp.server_card'));
    }

    #[Test]
    public function registers_default_mcp_auth_binding(): void
    {
        $provider = new McpServiceProvider();
        $provider->register();

        $auth = $provider->resolve(McpAuthInterface::class);
        self::assertInstanceOf(BearerTokenAuth::class, $auth);
        // Default token map is empty: any Authorization header authenticates
        // to no account. Production overrides via the kernel-services bus
        // or by re-binding McpAuthInterface in an application provider.
        self::assertNull($auth->authenticate('Bearer anything'));
    }
}
