<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Routing\WaaseyaaRouter;
use Waaseyaa\Routing\RouteBuilder;

/**
 * Automatically registers JSON:API routes for all known entity types.
 *
 * For each entity type registered with the EntityTypeManager, this provider
 * creates five routes:
 *
 *   GET    /api/{entityType}       — collection (index)
 *   GET    /api/{entityType}/{id}  — single resource (show)
 *   POST   /api/{entityType}       — create (store)
 *   PATCH  /api/{entityType}/{id}  — update
 *   DELETE /api/{entityType}/{id}  — delete
 */
final class JsonApiRouteProvider
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
    ) {}

    /**
     * Register JSON:API routes for all entity types on the given router.
     */
    public function registerRoutes(WaaseyaaRouter $router): void
    {
        foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
            $this->registerEntityTypeRoutes($router, $entityTypeId);
        }
    }

    /**
     * Register the five CRUD routes for a single entity type.
     */
    private function registerEntityTypeRoutes(WaaseyaaRouter $router, string $entityTypeId): void
    {
        $collectionPath = $this->basePath . '/' . $entityTypeId;
        $resourcePath = $collectionPath . '/{id}';

        // GET collection (index).
        $router->addRoute(
            "api.{$entityTypeId}.index",
            RouteBuilder::create($collectionPath)
                ->controller("Waaseyaa\\Api\\JsonApiController::index")
                ->methods('GET')
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // GET single resource (show).
        $router->addRoute(
            "api.{$entityTypeId}.show",
            RouteBuilder::create($resourcePath)
                ->controller("Waaseyaa\\Api\\JsonApiController::show")
                ->methods('GET')
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // POST create (store).
        $router->addRoute(
            "api.{$entityTypeId}.store",
            RouteBuilder::create($collectionPath)
                ->controller("Waaseyaa\\Api\\JsonApiController::store")
                ->methods('POST')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // PATCH update.
        $router->addRoute(
            "api.{$entityTypeId}.update",
            RouteBuilder::create($resourcePath)
                ->controller("Waaseyaa\\Api\\JsonApiController::update")
                ->methods('PATCH')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // DELETE.
        $router->addRoute(
            "api.{$entityTypeId}.destroy",
            RouteBuilder::create($resourcePath)
                ->controller("Waaseyaa\\Api\\JsonApiController::destroy")
                ->methods('DELETE')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );
    }
}
