<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface;

use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\GenericAdminSurfaceHost;
use Waaseyaa\Api\Schema\SchemaPresenter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\Bootstrap\AccessPolicyRegistry;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers admin surface routes with a generic CRUD host.
 *
 * Works out of the box: auto-discovers entity types and provides full
 * admin CRUD without any app-level configuration.
 *
 * To customize, either:
 * - Extend GenericAdminSurfaceHost and override methods
 * - Implement AbstractAdminSurfaceHost directly
 * Then call registerRoutes() from your own service provider.
 */
final class AdminSurfaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No bindings needed — host is constructed in routes() where
        // EntityTypeManager is available via injection.
    }

    /**
     * Auto-register admin surface routes with the generic host.
     *
     * If an app provides its own host via a higher-priority provider,
     * it should call registerRoutes() directly and skip this provider.
     */
    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        if ($entityTypeManager === null) {
            return;
        }

        $host = new GenericAdminSurfaceHost(
            entityTypeManager: $entityTypeManager,
            accessHandler: $this->discoverAccessHandler(),
            schemaPresenter: new SchemaPresenter(),
        );

        self::registerRoutes($router, $host);
    }

    /**
     * Register admin surface routes with a custom host.
     *
     * Call this from your application's service provider if you need
     * custom admin behavior beyond what GenericAdminSurfaceHost provides.
     */
    public static function registerRoutes(WaaseyaaRouter $router, AbstractAdminSurfaceHost $host): void
    {
        // Session endpoint uses requireSession (not requireAuthentication) so the
        // SPA can distinguish "not logged in" (SurfaceResult with ok:false) from
        // "endpoint not available" (network error). The host's resolveSession()
        // checks the account and returns null for unauthorized users.
        $router->addRoute('admin_surface.session', RouteBuilder::create('/admin/surface/session')
            ->methods('GET')
            ->requireSession()
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

    private function discoverAccessHandler(): ?EntityAccessHandler
    {
        $path = $this->projectRoot . '/storage/framework/packages.php';
        if (!is_file($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = require $path;
            $manifest = PackageManifest::fromArray($data);
        } catch (\Throwable) {
            return null;
        }

        $registry = new AccessPolicyRegistry(new NullLogger());

        return $registry->discover($manifest);
    }
}
