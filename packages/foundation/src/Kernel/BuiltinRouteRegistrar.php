<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Kernel;

use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Registers all built-in HTTP routes on the router.
 *
 * Handles JSON:API entity routes, schema, OpenAPI, discovery endpoints,
 * MCP, media upload, SSR page rendering, and app-level provider routes.
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
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $router->addRoute(
            'api.schema.show',
            RouteBuilder::create('/api/schema/{entity_type}')
                ->controller('Waaseyaa\\Api\\Controller\\SchemaController::show')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.openapi',
            RouteBuilder::create('/api/openapi.json')
                ->controller('openapi')
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.entity_types',
            RouteBuilder::create('/api/entity-types')
                ->controller('entity_types')
                ->methods('GET')
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

        $router->addRoute(
            'mcp.endpoint',
            RouteBuilder::create('/mcp')
                ->controller('mcp.endpoint')
                ->allowAll()
                ->jsonApi()
                ->methods('GET', 'POST')
                ->build(),
        );

        $graphQlRouteProvider = new \Waaseyaa\GraphQL\GraphQlRouteProvider();
        $graphQlRouteProvider->registerRoutes($router);

        $router->addRoute(
            'api.user.me',
            RouteBuilder::create('/api/user/me')
                ->controller('user.me')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'api.auth.login',
            RouteBuilder::create('/api/auth/login')
                ->controller('auth.login')
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        $router->addRoute(
            'api.auth.logout',
            RouteBuilder::create('/api/auth/logout')
                ->controller('auth.logout')
                ->allowAll()
                ->methods('POST')
                ->build(),
        );

        // App routes — registered before SSR catchall so they take priority.
        foreach ($this->providers as $provider) {
            $provider->routes($router);
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
    }
}
