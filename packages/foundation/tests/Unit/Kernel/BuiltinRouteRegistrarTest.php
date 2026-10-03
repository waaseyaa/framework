<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\BuiltinRouteRegistrar;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(BuiltinRouteRegistrar::class)]
final class BuiltinRouteRegistrarTest extends TestCase
{
    public function testLegacyProjectionPreservesExactBaseDeclarations(): void
    {
        $router = new WaaseyaaRouter();
        new BuiltinRouteRegistrar(new EntityTypeManager(new EventDispatcher()))->register($router);
        $actual = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $actual[$name] = ['path' => $route->getPath(), 'defaults' => $route->getDefaults(), 'requirements' => $route->getRequirements(), 'options' => $route->getOptions(), 'host' => $route->getHost(), 'schemes' => $route->getSchemes(), 'methods' => $route->getMethods(), 'condition' => $route->getCondition()];
        }
        $expected = json_decode(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Routing/builtin-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(array_keys($expected), array_keys($actual));
        self::assertSame($expected, $actual);
    }

    #[Test]
    public function registers_core_api_routes(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $registrar = new BuiltinRouteRegistrar($entityTypeManager);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $registrar->register($router);

        $routes = $router->getRouteCollection();
        // api.schema.show is now owned by ApiServiceProvider::routes() (WP5 route-table inversion).
        $this->assertNull($routes->get('api.schema.show'), 'api.schema.show belongs to the api package now, not to the bare registrar.');
        $this->assertNotNull($routes->get('api.openapi'));
        $this->assertNotNull($routes->get('api.entity_types'));
        $this->assertSame('admin', $routes->get('api.entity_types')?->getOption('_role'));
        $this->assertFalse((bool) $routes->get('api.entity_types')?->getOption('_public'));
        $this->assertNotNull($routes->get('api.entity_types.disable'));
        $this->assertNotNull($routes->get('api.entity_types.enable'));
        $this->assertNotNull($routes->get('api.broadcast'));
        $this->assertNotNull($routes->get('api.search'));
        $this->assertNotNull($routes->get('api.media.upload'));
        $this->assertSame(['GET', 'POST'], $routes->get('api.media.upload')?->getMethods());
        $this->assertTrue((bool) $routes->get('api.media.upload')?->getOption('_authenticated'));
        $this->assertSame('access media', $routes->get('api.media.upload')?->getOption('_permission'));
        $this->assertNotSame(false, $routes->get('api.media.upload')?->getOption('_csrf'));
        $this->assertNotNull($routes->get('media.download'));
        $this->assertTrue((bool) $routes->get('media.download')?->getOption('_public'));
        $this->assertNotNull($routes->get('media.view'));
        $this->assertSame('/media/{id}/view', $routes->get('media.view')?->getPath());
        $this->assertSame(['GET'], $routes->get('media.view')?->getMethods());
        $this->assertTrue((bool) $routes->get('media.view')?->getOption('_public'));
    }

    #[Test]
    public function registers_discovery_routes(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $registrar = new BuiltinRouteRegistrar($entityTypeManager);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $registrar->register($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.discovery.hub'));
        $this->assertNotNull($routes->get('api.discovery.cluster'));
        $this->assertNotNull($routes->get('api.discovery.timeline'));
        $this->assertNotNull($routes->get('api.discovery.endpoint'));
    }

    #[Test]
    public function registers_public_ssr_routes(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $registrar = new BuiltinRouteRegistrar($entityTypeManager);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $registrar->register($router);

        $routes = $router->getRouteCollection();
        $this->assertNull($routes->get('mcp.endpoint'), 'MCP route is owned by waaseyaa/mcp, not BuiltinRouteRegistrar.');
        $this->assertNotNull($routes->get('public.home'));
        $this->assertNotNull($routes->get('public.page'));
    }

    #[Test]
    public function public_home_route_has_render_option(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $registrar = new BuiltinRouteRegistrar($entityTypeManager);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));

        $registrar->register($router);

        $this->assertTrue((bool) $router->getRouteCollection()->get('public.home')?->getOption('_render'));
    }
}
