<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\AnchorCatalogController;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * Wayfinding service provider.
 *
 * Phase 1 (anchor registry + published catalog): binds the {@see AnchorRegistry}
 * and registers the public, read-only anchor-catalog endpoint.
 * Phase 2 (session-scoped delivery): registers the authenticated emit endpoint
 * ({@see EmitBeaconController}) that publishes beacons to a target session's
 * reserved private channel over the bounded SSE loop. Later phases add the overlay,
 * trail persistence, and the authenticated MCP write tier
 * (kitty-specs/wayfinding-01KVGH5X/spec.md).
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

        // Phase 2: session-scoped beacon emit. Authenticated + the
        // "present guided content" capability, fail-closed (LD-2/FR-003).
        $router->addRoute(
            'wayfinding.emit_beacon',
            RouteBuilder::create('/api/wayfinding/beacons')
                ->controller(EmitBeaconController::class . '::emit')
                ->methods('POST')
                ->requireAuthentication()
                ->requirePermission(EmitBeaconController::CAPABILITY)
                ->priority(10)
                ->build(),
        );
    }
}
