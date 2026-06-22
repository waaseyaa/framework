<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding;

use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Entity\Trail;
use Waaseyaa\Wayfinding\Http\AnchorCatalogController;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;
use Waaseyaa\Wayfinding\Http\SessionTokenController;

/**
 * Wayfinding service provider.
 *
 * Phase 1 (anchor registry + published catalog): binds the {@see AnchorRegistry}
 * and registers the public, read-only anchor-catalog endpoint.
 * Phase 2 (session-scoped delivery): registers the authenticated emit endpoint
 * ({@see EmitBeaconController}) that publishes beacons to a target session's
 * reserved private channel over the bounded SSE loop.
 * Phase 4 (trail persistence): registers the {@see Trail} two-axis (revisionable +
 * translatable) entity type that backs saved trails (LD-5 / FR-009..FR-011); the
 * persistence model lives in {@see \Waaseyaa\Wayfinding\Trail\TrailStore} and the
 * authenticated write tier that exposes it is Phase 5
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

        // Phase 4: the saved-trail entity is versioned + translatable (en + fr)
        // — the two storage axes the no-silent-overwrite revision rule rides on
        // (LD-5). Schema is materialised by EntitySchemaSync at db:init like any
        // other registered type.
        $this->entityType(EntityType::fromClass(
            Trail::class,
            revisionable: true,
            translatable: true,
            group: 'content',
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

        // Phase 5 (P0-2): supported read path for the caller's own non-secret
        // session token — the value the SSE `connected` frame carries — without
        // intercepting the SSE wire. Authenticated; returns only the caller's
        // own token (derived from its session).
        $router->addRoute(
            'wayfinding.session_token',
            RouteBuilder::create('/api/wayfinding/session')
                ->controller(SessionTokenController::class . '::show')
                ->methods('GET')
                ->requireAuthentication()
                ->priority(10)
                ->build(),
        );

        // Phase 5 (P0-1): a viewer dismissing the live trail clears its OWN
        // session's retained beacons so they stop replaying on reconnect/reload.
        // Authenticated, own-session scoped — no presenter capability (the
        // viewer is not the presenter), unlike emit.
        $router->addRoute(
            'wayfinding.clear_beacons',
            RouteBuilder::create('/api/wayfinding/beacons')
                ->controller(EmitBeaconController::class . '::clear')
                ->methods('DELETE')
                ->requireAuthentication()
                ->priority(10)
                ->build(),
        );
    }
}
