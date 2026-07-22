<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Symfony\Component\Routing\Route;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * Automatically registers JSON:API routes for all known entity types.
 *
 * For each entity type registered with the EntityTypeManager, this provider
 * creates five CRUD routes plus one field auto-save route:
 *
 *   GET    /api/{entityType}                    — collection (index)
 *   GET    /api/{entityType}/{id}               — single resource (show)
 *   POST   /api/{entityType}                    — create (store)
 *   PATCH  /api/{entityType}/{id}               — update
 *   DELETE /api/{entityType}/{id}               — delete
 *   PUT    /api/{entityType}/{id}/field/{key}   — per-field auto-save (F3)
 */
final class JsonApiRouteProvider
{
    private const int STRUCTURAL_ROUTE_CACHE_LIMIT = 2;

    /** @var array<string, list<array{name: string, route: Route}>> */
    private static array $structuralRouteCache = [];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {}

    /**
     * Register JSON:API routes for all entity types on the given router.
     */
    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $this->replayTemplates($router, $this->routeTemplates(workflow: false));
    }

    /** @return list<array{name: string, route: Route}> */
    private function routeTemplates(bool $workflow): array
    {
        $definitions = $this->entityTypeManager->getDefinitions();
        $exposure = [];
        foreach ($definitions as $entityTypeId => $definition) {
            $exposure[$entityTypeId] = EntityTypeApiExposure::isExposed($definition, $this->exposurePolicy);
        }
        ksort($exposure);
        $key = $this->basePath . "\0" . ($workflow ? 'workflow' : 'base') . "\0"
            . json_encode($exposure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (isset(self::$structuralRouteCache[$key])) {
            return self::$structuralRouteCache[$key];
        }

        $templateRouter = new WaaseyaaRouter();
        if ($workflow) {
            foreach ($exposure as $entityTypeId => $exposed) {
                if ($exposed) {
                    $this->registerWorkflowTransitionRoutesForType($templateRouter, $entityTypeId);
                }
            }
        } else {
            $this->buildBaseRoutes($templateRouter, $exposure);
        }

        $templates = [];
        foreach ($templateRouter->getRouteCollection()->all() as $name => $route) {
            $templates[] = ['name' => $name, 'route' => clone $route];
        }
        if (count(self::$structuralRouteCache) >= self::STRUCTURAL_ROUTE_CACHE_LIMIT) {
            array_shift(self::$structuralRouteCache);
        }
        return self::$structuralRouteCache[$key] = $templates;
    }

    /** @param array<string, bool> $exposure */
    private function buildBaseRoutes(WaaseyaaRouter $router, array $exposure): void
    {
        // Discovery endpoint: GET /api
        $router->addRoute(
            'api.discovery',
            RouteBuilder::create($this->basePath)
                ->controller('Waaseyaa\\Api\\ApiDiscoveryController::discover')
                ->methods('GET')
                ->allowAll()
                ->build(),
        );

        foreach ($exposure as $entityTypeId => $exposed) {
            if (!$exposed) {
                $this->registerNotExposedRoutes($router, $entityTypeId);
                continue;
            }
            $this->registerEntityTypeRoutes($router, $entityTypeId);
            $this->registerFieldAutoSave($router, $entityTypeId);
            $this->registerTranslationRoutes($router, $entityTypeId);
        }
    }

    /** @param list<array{name: string, route: Route}> $templates */
    private function replayTemplates(WaaseyaaRouter $router, array $templates): void
    {
        foreach ($templates as $template) {
            $router->addRoute($template['name'], clone $template['route']);
        }
    }

    private function registerNotExposedRoutes(WaaseyaaRouter $router, string $entityTypeId): void
    {
        $diagnostic = static function (): array {
            return [
                'statusCode' => 404,
                'body' => [
                    'jsonapi' => ['version' => '1.1'],
                    'errors' => [[
                        'status' => '404',
                        'title' => 'Not Found',
                        'detail' => 'No route matches the requested path.',
                    ]],
                ],
            ];
        };

        foreach ([
            "api.{$entityTypeId}.not_exposed" => $this->basePath . '/' . $entityTypeId,
            "api.{$entityTypeId}.not_exposed_path" => $this->basePath . '/' . $entityTypeId . '/{path}',
        ] as $name => $path) {
            $builder = RouteBuilder::create($path)
                ->controller($diagnostic)
                ->methods('GET', 'POST', 'PATCH', 'DELETE', 'PUT')
                ->allowAll();
            if (str_ends_with($name, '_path')) {
                $builder->requirement('path', '.+');
            }
            $router->addRoute($name, $builder->build());
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
                ->controller('Waaseyaa\\Api\\JsonApiController::index')
                ->methods('GET')
                ->allowAll()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // GET single resource (show).
        $router->addRoute(
            "api.{$entityTypeId}.show",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\JsonApiController::show')
                ->methods('GET')
                ->allowAll()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // POST create (store).
        $router->addRoute(
            "api.{$entityTypeId}.store",
            RouteBuilder::create($collectionPath)
                ->controller('Waaseyaa\\Api\\JsonApiController::store')
                ->methods('POST')
                ->requireAuthentication()
                ->jsonApi()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // PATCH update.
        $router->addRoute(
            "api.{$entityTypeId}.update",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\JsonApiController::update')
                ->methods('PATCH')
                ->requireAuthentication()
                ->jsonApi()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // DELETE.
        $router->addRoute(
            "api.{$entityTypeId}.destroy",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\JsonApiController::destroy')
                ->methods('DELETE')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );
    }

    /**
     * Register the 5 translation CRUD sub-routes for a single entity type.
     *
     *   GET    /api/{entityType}/{id}/translations
     *   GET    /api/{entityType}/{id}/translations/{langcode}
     *   POST   /api/{entityType}/{id}/translations/{langcode}
     *   PATCH  /api/{entityType}/{id}/translations/{langcode}
     *   DELETE /api/{entityType}/{id}/translations/{langcode}
     */
    private function registerTranslationRoutes(WaaseyaaRouter $router, string $entityTypeId): void
    {
        $collectionPath = $this->basePath . '/' . $entityTypeId . '/{id}/translations';
        $resourcePath = $collectionPath . '/{langcode}';

        // GET translation list.
        $router->addRoute(
            "api.{$entityTypeId}.translations.index",
            RouteBuilder::create($collectionPath)
                ->controller('Waaseyaa\\Api\\Controller\\TranslationController::index')
                ->methods('GET')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // GET single translation.
        $router->addRoute(
            "api.{$entityTypeId}.translations.show",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\Controller\\TranslationController::show')
                ->methods('GET')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // POST create translation.
        $router->addRoute(
            "api.{$entityTypeId}.translations.store",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\Controller\\TranslationController::store')
                ->methods('POST')
                ->requireAuthentication()
                ->jsonApi()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // PATCH update translation.
        $router->addRoute(
            "api.{$entityTypeId}.translations.update",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\Controller\\TranslationController::update')
                ->methods('PATCH')
                ->requireAuthentication()
                ->jsonApi()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        // DELETE translation.
        $router->addRoute(
            "api.{$entityTypeId}.translations.destroy",
            RouteBuilder::create($resourcePath)
                ->controller('Waaseyaa\\Api\\Controller\\TranslationController::destroy')
                ->methods('DELETE')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );
    }

    /**
     * Register the per-field auto-save route for a single entity type (F3).
     *
     * PUT /api/{entityType}/{id}/field/{key}
     */
    private function registerFieldAutoSave(WaaseyaaRouter $router, string $entityTypeId): void
    {
        $fieldPath = $this->basePath . '/' . $entityTypeId . '/{id}/field/{key}';

        $router->addRoute(
            "api.{$entityTypeId}.field_autosave",
            RouteBuilder::create($fieldPath)
                ->controller('Waaseyaa\\Api\\Controller\\FieldAutoSaveController::update')
                ->methods('PUT')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );
    }

    /**
     * Register the workflow transition routes for every entity type (CW-v1
     * WP-4, docs/specs/content-workflow.md "Integration -> API (WP-4)").
     *
     *   GET  /api/{entityType}/{id}/workflow/transitions
     *   POST /api/{entityType}/{id}/workflow/transition
     *
     * Called ONLY by `ApiServiceProvider::routes()`, and only when
     * `TransitionService` resolves (design decision 1 of the WP-4 plan) — a
     * core-only install without `waaseyaa/workflows` wired never registers
     * these routes, so a request to them 404s naturally rather than routing
     * to a controller that could not be constructed.
     */
    public function registerWorkflowTransitionRoutes(WaaseyaaRouter $router): void
    {
        $this->replayTemplates($router, $this->routeTemplates(workflow: true));
    }

    private function registerWorkflowTransitionRoutesForType(WaaseyaaRouter $router, string $entityTypeId): void
    {
        $workflowBasePath = $this->basePath . '/' . $entityTypeId . '/{id}/workflow';
        $controller = 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController';

        $router->addRoute(
            "api.{$entityTypeId}.workflow_transitions",
            RouteBuilder::create($workflowBasePath . '/transitions')
                ->controller($controller . '::transitions')
                ->methods('GET')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );

        $router->addRoute(
            "api.{$entityTypeId}.workflow_transition",
            RouteBuilder::create($workflowBasePath . '/transition')
                ->controller($controller . '::transition')
                ->methods('POST')
                ->requireAuthentication()
                ->default('_entity_type', $entityTypeId)
                ->build(),
        );
    }
}
