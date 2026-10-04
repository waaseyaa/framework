<?php

declare(strict_types=1);

namespace Waaseyaa\Api;

use Waaseyaa\Api\Controller\NotExposedController;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Routing\RouteMetadataCompiler;
use Waaseyaa\Routing\WaaseyaaRouter;

/** JSON:API structural declarations and their bare-router compatibility projection. */
final class JsonApiRouteProvider
{
    private const int STRUCTURAL_ROUTE_CACHE_LIMIT = 2;
    /** @var array<string, list<RouteDefinition>> Immutable declarations only. */
    private static array $structuralRouteCache = [];

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly string $basePath = '/api',
        private readonly ?EntityTypeApiExposurePolicy $exposurePolicy = null,
    ) {}

    /** @return iterable<RouteDefinition> Pure generation from finalized copied inputs. @api */
    public static function routeDefinitions(RouteContributionContext $context, string $basePath = '/api', bool $workflow = false, int $ordinal = 0, bool $requestTerminals = false): iterable
    {
        $exposure = [];
        foreach ($context->entities as $entity) {
            $key = 'entity:' . $entity['id'];
            if (!is_bool($entity['api_exposed'] ?? null) || array_key_exists($key, $exposure)) {
                throw new \InvalidArgumentException('JSON:API routes require unique finalized entity exposure inputs.');
            }
            $exposure[$key] = $entity;
        }
        $entities = array_values($exposure);
        usort($entities, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
        if (!$workflow) {
            yield new RouteDefinition('api.discovery', $basePath, HandlerReference::fromString($requestTerminals ? 'class:Waaseyaa\\Api\\Http\\Router\\DiscoveryRouter::discover' : 'class:Waaseyaa\\Api\\ApiDiscoveryController::discover'), methods: ['GET'], options: ['_public' => true], sourceId: $context->sourceId, ordinal: $ordinal++);
        }
        foreach ($entities as $entity) {
            $id = $entity['id'];
            $path = $basePath . '/' . $id;
            if (!$entity['api_exposed']) {
                if (!$workflow) {
                    foreach (['not_exposed' => '', 'not_exposed_path' => '/{path}'] as $suffix => $tail) {
                        yield new RouteDefinition('api.' . $id . '.' . $suffix, $path . $tail, HandlerReference::fromString('class:' . NotExposedController::class . '::__invoke'), methods: ['GET', 'POST', 'PATCH', 'DELETE', 'PUT'], requirements: $tail === '' ? [] : ['path' => '.+'], options: ['_public' => true], sourceId: $context->sourceId, ordinal: $ordinal++);
                    }
                }
                continue;
            }
            $rows = $workflow ? [
                ['workflow_transitions', '/{id}/workflow/transitions', 'GET', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transitions', false, false],
                ['workflow_transition', '/{id}/workflow/transition', 'POST', 'Waaseyaa\\Api\\Controller\\WorkflowTransitionController::transition', false, false],
            ] : [
                ['index', '', 'GET', 'Waaseyaa\\Api\\JsonApiController::index', true, false],
                ['show', '/{id}', 'GET', 'Waaseyaa\\Api\\JsonApiController::show', true, false],
                ['store', '', 'POST', 'Waaseyaa\\Api\\JsonApiController::store', false, true],
                ['update', '/{id}', 'PATCH', 'Waaseyaa\\Api\\JsonApiController::update', false, true],
                ['destroy', '/{id}', 'DELETE', 'Waaseyaa\\Api\\JsonApiController::destroy', false, false],
                ['field_autosave', '/{id}/field/{key}', 'PUT', 'Waaseyaa\\Api\\Controller\\FieldAutoSaveController::update', false, false],
                ['translations.index', '/{id}/translations', 'GET', 'Waaseyaa\\Api\\Controller\\TranslationController::index', false, false],
                ['translations.show', '/{id}/translations/{langcode}', 'GET', 'Waaseyaa\\Api\\Controller\\TranslationController::show', false, false],
                ['translations.store', '/{id}/translations/{langcode}', 'POST', 'Waaseyaa\\Api\\Controller\\TranslationController::store', false, true],
                ['translations.update', '/{id}/translations/{langcode}', 'PATCH', 'Waaseyaa\\Api\\Controller\\TranslationController::update', false, true],
                ['translations.destroy', '/{id}/translations/{langcode}', 'DELETE', 'Waaseyaa\\Api\\Controller\\TranslationController::destroy', false, false],
            ];
            foreach ($rows as [$suffix, $tail, $method, $controller, $public, $jsonApi]) {
                $options = [$public ? '_public' : '_authenticated' => true];
                if ($jsonApi) {
                    $options['_json_api'] = true;
                }
                if ($requestTerminals) {
                    [$class, $action] = explode('::', $controller, 2);
                    $controller = match ($class) {
                        'Waaseyaa\Api\JsonApiController' => 'Waaseyaa\Foundation\Http\Router\JsonApiRouter::handle',
                        'Waaseyaa\Api\Controller\TranslationController' => 'Waaseyaa\Foundation\Http\Router\TranslationRouter::handle',
                        'Waaseyaa\Api\Controller\FieldAutoSaveController' => 'Waaseyaa\Api\Http\Router\FieldAutoSaveApiRouter::update',
                        'Waaseyaa\Api\Controller\WorkflowTransitionController' => 'Waaseyaa\Api\Http\Router\WorkflowTransitionApiRouter::' . $action,
                        default => throw new \LogicException('Unsupported JSON:API request terminal.'),
                    };
                }
                yield new RouteDefinition('api.' . $id . '.' . $suffix, $path . $tail, HandlerReference::fromString('class:' . $controller), methods: [$method], defaults: ['_entity_type' => $id], options: $options, sourceId: $context->sourceId, ordinal: $ordinal++);
            }
        }
    }

    public function registerRoutes(WaaseyaaRouter $router): void
    {
        $this->replayTemplates($router, $this->routeTemplates(false));
    }

    public function registerWorkflowTransitionRoutes(WaaseyaaRouter $router): void
    {
        $this->replayTemplates($router, $this->routeTemplates(true));
    }

    /** @return list<RouteDefinition> */
    private function routeTemplates(bool $workflow): array
    {
        $exposure = [];
        $entities = [];
        foreach ($this->entityTypeManager->getDefinitions() as $definition) {
            $id = $definition->id();
            $exposure[$id] = EntityTypeApiExposure::isExposed($definition, $this->exposurePolicy);
            $entities[] = ['id' => $id, 'api_exposed' => $exposure[$id]];
        }
        ksort($exposure, SORT_STRING);
        $key = $this->basePath . "\0" . ($workflow ? 'workflow' : 'base') . "\0" . json_encode($exposure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (isset(self::$structuralRouteCache[$key])) {
            return self::$structuralRouteCache[$key];
        }
        $templates = iterator_to_array(self::routeDefinitions(new RouteContributionContext(self::class, 0, entities: $entities), $this->basePath, $workflow));
        if (count(self::$structuralRouteCache) >= self::STRUCTURAL_ROUTE_CACHE_LIMIT) {
            array_shift(self::$structuralRouteCache);
        }
        return self::$structuralRouteCache[$key] = $templates;
    }

    /** @param list<RouteDefinition> $templates */
    private function replayTemplates(WaaseyaaRouter $router, array $templates): void
    {
        foreach ($templates as $definition) {
            $route = new RouteMetadataCompiler()->compileRoute($definition);
            $options = $route->getOptions();
            unset($options['_waaseyaa_priority']);
            $route->setOptions($options);
            $route->setDefault('_controller', $definition->handler->target === NotExposedController::class
                ? new NotExposedController()->__invoke(...)
                : $definition->handler->target . '::' . $definition->handler->method);
            $router->addRoute($definition->name, $route);
        }
    }
}
