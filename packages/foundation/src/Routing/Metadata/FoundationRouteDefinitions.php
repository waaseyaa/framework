<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Routing\Metadata;

/** The sole declaration authority for framework builtin and terminal routes. @internal */
final class FoundationRouteDefinitions
{
    /** @return list<RouteDefinition> */
    public static function builtins(): array
    {
        return [
            new RouteDefinition('api.openapi', '/api/openapi.json', HandlerReference::fromString('builtin:openapi'), methods: ['GET'], options: ['_authenticated' => true], sourceId: 'foundation.builtin', ordinal: 0),
            new RouteDefinition('api.entity_types', '/api/entity-types', HandlerReference::fromString('builtin:entity_types'), methods: ['GET'], options: ['_role' => 'admin'], sourceId: 'foundation.builtin', ordinal: 1),
            new RouteDefinition('api.entity_types.disable', '/api/entity-types/{entity_type}/disable', HandlerReference::fromString('builtin:entity_type.disable'), methods: ['POST'], options: ['_role' => 'admin'], sourceId: 'foundation.builtin', ordinal: 2),
            new RouteDefinition('api.entity_types.enable', '/api/entity-types/{entity_type}/enable', HandlerReference::fromString('builtin:entity_type.enable'), methods: ['POST'], options: ['_role' => 'admin'], sourceId: 'foundation.builtin', ordinal: 3),
            new RouteDefinition('api.broadcast', '/api/broadcast', HandlerReference::fromString('builtin:broadcast'), methods: ['GET'], options: ['_authenticated' => true], sourceId: 'foundation.builtin', ordinal: 4),
            new RouteDefinition('api.media.upload', '/api/media/upload', HandlerReference::fromString('builtin:media.upload'), methods: ['GET', 'POST'], options: ['_authenticated' => true, '_permission' => 'access media'], sourceId: 'foundation.builtin', ordinal: 5),
            new RouteDefinition('media.download', '/media/{id}/download', HandlerReference::fromString('builtin:media.download'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 6),
            new RouteDefinition('media.view', '/media/{id}/view', HandlerReference::fromString('builtin:media.view'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 7),
            new RouteDefinition('attachment.download', '/attachment/{id}/download', HandlerReference::fromString('builtin:attachment.download'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 8),
            new RouteDefinition('api.search', '/api/search', HandlerReference::fromString('builtin:search.semantic'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 9),
            new RouteDefinition('api.discovery.hub', '/api/discovery/hub/{entity_type}/{id}', HandlerReference::fromString('builtin:discovery.topic_hub'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 10),
            new RouteDefinition('api.discovery.cluster', '/api/discovery/cluster/{entity_type}/{id}', HandlerReference::fromString('builtin:discovery.cluster'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 11),
            new RouteDefinition('api.discovery.timeline', '/api/discovery/timeline/{entity_type}/{id}', HandlerReference::fromString('builtin:discovery.timeline'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 12),
            new RouteDefinition('api.discovery.endpoint', '/api/discovery/endpoint/{entity_type}/{id}', HandlerReference::fromString('builtin:discovery.endpoint'), methods: ['GET'], options: ['_public' => true], sourceId: 'foundation.builtin', ordinal: 13),
        ];
    }

    /** @return list<RouteDefinition> */
    public static function terminal(): array
    {
        return [
            new RouteDefinition('public.home', '/', HandlerReference::fromString('builtin:render.page'), methods: ['GET'], defaults: ['path' => '/'], options: ['_public' => true, '_render' => true], sourceId: 'foundation.terminal', ordinal: 0),
            new RouteDefinition('public.page', '/{path}', HandlerReference::fromString('builtin:render.page'), methods: ['GET'], requirements: ['path' => '(?!api(?:/|$)).+'], options: ['_public' => true, '_render' => true], sourceId: 'foundation.terminal', ordinal: 1),
        ];
    }
}
