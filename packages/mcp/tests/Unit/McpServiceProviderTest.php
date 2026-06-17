<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Mcp\Auth\McpAuthInterface;
use Waaseyaa\Mcp\Auth\PublicAnonymousAuth;
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
    public function registers_public_read_only_auth_binding_by_default(): void
    {
        $provider = new McpServiceProvider();
        $provider->register();

        $auth = $provider->resolve(McpAuthInterface::class);
        self::assertInstanceOf(PublicAnonymousAuth::class, $auth);
        // Public read-only default: every request resolves to an anonymous
        // account holding only read capabilities — never null, never a write cap.
        $account = $auth->authenticate('Bearer anything');
        self::assertNotNull($account);
        self::assertFalse($account->isAuthenticated());
        self::assertTrue($account->hasPermission('tool.entity.read'));
        self::assertFalse($account->hasPermission('tool.entity.create'));
    }
}
