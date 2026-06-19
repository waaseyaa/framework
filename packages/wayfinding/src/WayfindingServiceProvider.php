<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\AnchorCatalogController;

/**
 * Wayfinding service provider.
 *
 * Phase 1 (anchor registry + published catalog): binds the {@see AnchorRegistry}
 * and registers the public, read-only anchor-catalog endpoint. Later phases add
 * session-scoped beacon delivery, the overlay, trail persistence, and the
 * authenticated MCP write tier (kitty-specs/wayfinding-01KVGH5X/spec.md).
 */
final class WayfindingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The canonical registry instance, shared by later phases (emit-time
        // anchor validation, FR-005). The Phase-1 catalog controller resolves
        // its own from the kernel-bound EntityTypeManager.
        $this->singleton(AnchorRegistry::class, fn(): AnchorRegistry => new AnchorRegistry(
            $this->resolve(EntityTypeManager::class),
        ));
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $router->addRoute(
            'wayfinding.anchor_catalog',
            RouteBuilder::create('/.well-known/waaseyaa-anchors.json')
                ->controller(AnchorCatalogController::class . '::catalog')
                ->methods('GET')
                ->allowAll()
                ->priority(10)
                ->build(),
        );
    }
}
