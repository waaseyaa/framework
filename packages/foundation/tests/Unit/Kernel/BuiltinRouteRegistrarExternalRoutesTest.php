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
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(BuiltinRouteRegistrar::class)]
final class BuiltinRouteRegistrarExternalRoutesTest extends TestCase
{
    #[Test]
    public function registers_external_route_providers_before_public_pages(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $registrar = new BuiltinRouteRegistrar(
            entityTypeManager: $entityTypeManager,
            providers: [],
            routeProviders: [new class {
                public function registerRoutes(WaaseyaaRouter $router): void
                {
                    $router->addRoute(
                        'external.route',
                        RouteBuilder::create('/external')
                            ->controller('external.route')
                            ->methods('GET')
                            ->build(),
                    );
                }
            }],
        );

        $registrar->register($router);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('external.route'));
        $this->assertNotNull($routes->get('public.page'));
    }
}
