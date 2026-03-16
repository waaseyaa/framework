<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers admin surface routes.
 *
 * Applications must bind their own AbstractAdminSurfaceHost subclass
 * in their service provider before this provider boots.
 */
final class AdminSurfaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Host binding is the responsibility of the application's service provider.
        // This provider only wires routes.
    }

    /**
     * Helper to register standard admin surface routes.
     *
     * Call this from your application's service provider:
     *
     *     AdminSurfaceServiceProvider::registerRoutes($router, $host);
     */
    public static function registerRoutes(WaaseyaaRouter $router, AbstractAdminSurfaceHost $host): void
    {
        $router->addRoute('admin_surface.session', RouteBuilder::create('/admin/surface/session')
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request) => $host->handleSession($request))
            ->build());

        $router->addRoute('admin_surface.catalog', RouteBuilder::create('/admin/surface/catalog')
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request) => $host->handleCatalog($request))
            ->build());

        $router->addRoute('admin_surface.list', RouteBuilder::create('/admin/surface/{type}')
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type) => $host->handleList($request, $type))
            ->build());

        $router->addRoute('admin_surface.get', RouteBuilder::create('/admin/surface/{type}/{id}')
            ->methods('GET')
            ->requireAuthentication()
            ->controller(fn($request, $type, $id) => $host->handleGet($request, $type, $id))
            ->build());

        $router->addRoute('admin_surface.action', RouteBuilder::create('/admin/surface/{type}/action/{action}')
            ->methods('POST')
            ->requireAuthentication()
            ->controller(fn($request, $type, $action) => $host->handleAction($request, $type, $action))
            ->build());
    }
}
