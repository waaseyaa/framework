<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers framework-layer HTTP routes on the router.
 *
 * Registers alias-keyed framework routes (OpenAPI, entity-type lifecycle,
 * broadcast, media upload, attachment download, search, discovery, SSR
 * page rendering) and delegates app-level routes to service providers.
 *
 * Higher-layer package routes (e.g. waaseyaa/api controller FQCNs) are
 * registered by their respective service providers via the provider loop —
 * see ApiServiceProvider::routes() for the api package routes.
 */
final class BuiltinRouteRegistrar
{
    /**
     * @param list<ServiceProvider> $providers
     */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly array $providers = [],
    ) {}

    public function register(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'api.openapi',
            RouteBuilder::create('/api/openapi.json')
                ->controller('openapi')
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types',
            RouteBuilder::create('/api/entity-types')
                ->controller('entity_types')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );

        $router->addRoute(
            'api.entity_types.disable',
            RouteBuilder::create('/api/entity-types/{entity_type}/disable')
                ->controller('entity_type.disable')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types.enable',
            RouteBuilder::create('/api/entity-types/{entity_type}/enable')
                ->controller('entity_type.enable')
                ->requireRole('admin')
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.broadcast',
            RouteBuilder::create('/api/broadcast')
                ->controller('broadcast')
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.media.upload',
            RouteBuilder::create('/api/media/upload')
                ->controller('media.upload')
                ->requirePermission('access media')
                ->methods('POST')
                ->build(),
        );

        // Authorized download of a private attachment's bytes. Option-less by
        // design: the attachment package's AttachmentDownloadRouter (matched via
        // the 'attachment.download' controller string) is the enforcement point —
        // it runs the deny-by-default `view` check (delegated to the parent
        // entity) before streaming, and 404s on deny. Registered here (not in the
        // L2 attachment package) because route registration needs routing (L4),
        // exactly as `media.upload` above.
        $router->addRoute(
            'attachment.download',
            RouteBuilder::create('/attachment/{id}/download')
                ->controller('attachment.download')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );

        $router->addRoute(
            'api.search',
            RouteBuilder::create('/api/search')
                ->controller('search.semantic')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.hub',
            RouteBuilder::create('/api/discovery/hub/{entity_type}/{id}')
                ->controller('discovery.topic_hub')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.cluster',
            RouteBuilder::create('/api/discovery/cluster/{entity_type}/{id}')
                ->controller('discovery.cluster')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.timeline',
            RouteBuilder::create('/api/discovery/timeline/{entity_type}/{id}')
                ->controller('discovery.timeline')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.discovery.endpoint',
            RouteBuilder::create('/api/discovery/endpoint/{entity_type}/{id}')
                ->controller('discovery.endpoint')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // App routes. Registration order is only a *tiebreaker*, not the
        // precedence rule: WaaseyaaRouter::sortRoutesByPriority() reorders the
        // whole collection by RouteBuilder::priority() (option `_waaseyaa_priority`,
        // default 0) descending, using original registration index to break ties.
        // Because these providers register before `public.page` below, a default
        // (priority 0) app `/{alias}` route does sort ahead of the `/{path}` SSR
        // fallback — BUT only while nothing else re-sorts or competes at the same
        // priority. To GUARANTEE an app catch-all outranks the SSR render.page
        // fallback regardless of registration timing, give it ->priority(>=1)
        // (mirrors api.user.me ->priority(10) in AuthOidcRouteServiceProvider, #1532).
        // Precedence is documented in docs/specs/api-layer.md. Refs #1632.
        foreach ($this->providers as $provider) {
            $provider->routes($router, $this->entityTypeManager);
        }

        $router->addRoute(
            'public.home',
            RouteBuilder::create('/')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->default('path', '/')
                ->build(),
        );

        // SSR render.page fallback. Registered at DEFAULT priority (0) with no
        // explicit RouteBuilder::priority(), so it does not outrank app routes
        // that ask for ->priority(>=1). An app `/{alias}` route that wants to win
        // deterministically over this catch-all must set ->priority(>=1); relying
        // on registration order alone is fragile because sortRoutesByPriority()
        // can re-order the collection. See docs/specs/api-layer.md (#1632, #1532).
        $router->addRoute(
            'public.page',
            RouteBuilder::create('/{path}')
                ->controller('render.page')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('path', '(?!api(?:/|$)).+')
                ->build(),
        );

        $router->sortRoutesByPriority();
    }
}
