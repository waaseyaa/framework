<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase7;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Api\JsonApiController;
use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Api\Tests\Fixtures\InMemoryEntityStorage;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;

/**
 * End-to-end: routes + API controller + entity storage integration tests.
 *
 * Exercises: waaseyaa/api (JsonApiRouteProvider, JsonApiController) with
 * waaseyaa/routing (WaaseyaaRouter, RouteBuilder) and waaseyaa/entity
 * (EntityTypeManager).
 */
#[CoversNothing]
final class ApiRoutingIntegrationTest extends TestCase
{
    private WaaseyaaRouter $router;
    private EntityTypeManager $entityTypeManager;
    private InMemoryEntityStorage $nodeStorage;
    private JsonApiController $controller;

    protected function setUp(): void
    {
        $this->nodeStorage = new InMemoryEntityStorage('node');

        $this->entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            fn() => $this->nodeStorage,
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
                'bundle' => 'type',
            ],
        ));

        // Set up router with API routes.
        $this->router = new WaaseyaaRouter(new RequestContext());
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($this->router);

        // Set up controller.
        $serializer = new ResourceSerializer($this->entityTypeManager);
        $this->controller = new JsonApiController($this->entityTypeManager, $serializer);
    }

    #[Test]
    public function routeProviderRegistersAllFiveRoutes(): void
    {
        $routes = $this->router->getRouteCollection();

        $this->assertNotNull($routes->get('api.node.index'));
        $this->assertNotNull($routes->get('api.node.show'));
        $this->assertNotNull($routes->get('api.node.store'));
        $this->assertNotNull($routes->get('api.node.update'));
        $this->assertNotNull($routes->get('api.node.destroy'));
    }

    #[Test]
    public function indexRouteMatchesGetCollection(): void
    {
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node');

        $this->assertSame('api.node.index', $params['_route']);
        $this->assertSame('node', $params['_entity_type']);
    }

    #[Test]
    public function showRouteMatchesGetWithId(): void
    {
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node/42');

        $this->assertSame('api.node.show', $params['_route']);
        $this->assertSame('42', $params['id']);
        $this->assertSame('node', $params['_entity_type']);
    }

    #[Test]
    public function storeRouteMatchesPostCollection(): void
    {
        $context = new RequestContext('', 'POST');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node');

        $this->assertSame('api.node.store', $params['_route']);
        $this->assertSame('node', $params['_entity_type']);
    }

    #[Test]
    public function updateRouteMatchesPatchWithId(): void
    {
        $context = new RequestContext('', 'PATCH');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node/7');

        $this->assertSame('api.node.update', $params['_route']);
        $this->assertSame('7', $params['id']);
        $this->assertSame('node', $params['_entity_type']);
    }

    #[Test]
    public function destroyRouteMatchesDeleteWithId(): void
    {
        $context = new RequestContext('', 'DELETE');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node/3');

        $this->assertSame('api.node.destroy', $params['_route']);
        $this->assertSame('3', $params['id']);
        $this->assertSame('node', $params['_entity_type']);
    }

    #[Test]
    public function routeParametersExtractEntityTypeAndId(): void
    {
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node/123');

        $this->assertSame('node', $params['_entity_type']);
        $this->assertSame('123', $params['id']);
    }

    #[Test]
    public function unmatchedPathThrowsResourceNotFound(): void
    {
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $this->expectException(ResourceNotFoundException::class);
        $router->match('/api/unknown_type');
    }

    #[Test]
    public function fullRequestCycleMatchRouteCallControllerGetResponse(): void
    {
        // Step 1: Create test data.
        $entity = $this->nodeStorage->create([
            'title' => 'Route Test Article',
            'type' => 'article',
            'status' => 1,
        ]);
        $this->nodeStorage->save($entity);

        // Step 2: Match a GET route for the entity.
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $routeProvider = new JsonApiRouteProvider($this->entityTypeManager);
        $routeProvider->registerRoutes($router);

        $params = $router->match('/api/node/' . $entity->id());

        // Step 3: Extract route parameters.
        $entityTypeId = $params['_entity_type'];
        $entityId = $params['id'];
        $routeName = $params['_route'];

        $this->assertSame('node', $entityTypeId);
        $this->assertSame((string) $entity->id(), $entityId);
        $this->assertSame('api.node.show', $routeName);

        // Step 4: Call the controller.
        $doc = $this->controller->show($entityTypeId, (int) $entityId);

        // Step 5: Verify response.
        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertSame('Route Test Article', $array['data']['attributes']['title']);
    }

    #[Test]
    public function fullCrudCycleThroughRouteMatching(): void
    {
        // POST: Create via route match.
        $postContext = new RequestContext('', 'POST');
        $postRouter = new WaaseyaaRouter($postContext);
        (new JsonApiRouteProvider($this->entityTypeManager))->registerRoutes($postRouter);

        $postParams = $postRouter->match('/api/node');
        $this->assertSame('api.node.store', $postParams['_route']);

        $createDoc = $this->controller->store($postParams['_entity_type'], [
            'data' => [
                'type' => 'node',
                'attributes' => [
                    'title' => 'CRUD Cycle Node',
                    'type' => 'article',
                    'status' => 1,
                ],
            ],
        ]);
        $this->assertSame(201, $createDoc->statusCode);
        $createArray = $createDoc->toArray();
        $uuid = $createArray['data']['id'];

        // GET: Read via route match.
        $getContext = new RequestContext('', 'GET');
        $getRouter = new WaaseyaaRouter($getContext);
        (new JsonApiRouteProvider($this->entityTypeManager))->registerRoutes($getRouter);

        $getParams = $getRouter->match('/api/node/1');
        $this->assertSame('api.node.show', $getParams['_route']);

        $showDoc = $this->controller->show($getParams['_entity_type'], (int) $getParams['id']);
        $this->assertSame(200, $showDoc->statusCode);

        // PATCH: Update via route match.
        $patchContext = new RequestContext('', 'PATCH');
        $patchRouter = new WaaseyaaRouter($patchContext);
        (new JsonApiRouteProvider($this->entityTypeManager))->registerRoutes($patchRouter);

        $patchParams = $patchRouter->match('/api/node/1');
        $this->assertSame('api.node.update', $patchParams['_route']);

        $updateDoc = $this->controller->update($patchParams['_entity_type'], (int) $patchParams['id'], [
            'data' => [
                'type' => 'node',
                'id' => $uuid,
                'attributes' => [
                    'title' => 'Updated CRUD Cycle',
                ],
            ],
        ]);
        $this->assertSame(200, $updateDoc->statusCode);

        // DELETE: Destroy via route match.
        $deleteContext = new RequestContext('', 'DELETE');
        $deleteRouter = new WaaseyaaRouter($deleteContext);
        (new JsonApiRouteProvider($this->entityTypeManager))->registerRoutes($deleteRouter);

        $deleteParams = $deleteRouter->match('/api/node/1');
        $this->assertSame('api.node.destroy', $deleteParams['_route']);

        $deleteDoc = $this->controller->destroy($deleteParams['_entity_type'], (int) $deleteParams['id']);
        $this->assertSame(204, $deleteDoc->statusCode);
    }

    #[Test]
    public function urlGenerationForEntityResources(): void
    {
        $collectionUrl = $this->router->generate('api.node.index');
        $this->assertSame('/api/node', $collectionUrl);

        $resourceUrl = $this->router->generate('api.node.show', ['id' => 42]);
        $this->assertSame('/api/node/42', $resourceUrl);

        $updateUrl = $this->router->generate('api.node.update', ['id' => 7]);
        $this->assertSame('/api/node/7', $updateUrl);

        $deleteUrl = $this->router->generate('api.node.destroy', ['id' => 3]);
        $this->assertSame('/api/node/3', $deleteUrl);
    }

    #[Test]
    public function routesForMultipleEntityTypes(): void
    {
        // Register a second entity type.
        $termStorage = new InMemoryEntityStorage('taxonomy_term');
        $entityTypeManager = new EntityTypeManager(
            new EventDispatcher(),
            function ($definition) use ($termStorage) {
                if ($definition->id() === 'taxonomy_term') {
                    return $termStorage;
                }
                return $this->nodeStorage;
            },
        );

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'node',
            label: 'Node',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title', 'bundle' => 'type'],
        ));

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'taxonomy_term',
            label: 'Taxonomy Term',
            class: TestEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'name', 'bundle' => 'vid'],
        ));

        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $routeProvider = new JsonApiRouteProvider($entityTypeManager);
        $routeProvider->registerRoutes($router);

        $routes = $router->getRouteCollection();

        // Node routes.
        $this->assertNotNull($routes->get('api.node.index'));
        $this->assertNotNull($routes->get('api.node.show'));

        // Taxonomy routes.
        $this->assertNotNull($routes->get('api.taxonomy_term.index'));
        $this->assertNotNull($routes->get('api.taxonomy_term.show'));

        // Verify matching.
        $nodeParams = $router->match('/api/node');
        $this->assertSame('api.node.index', $nodeParams['_route']);

        $termParams = $router->match('/api/taxonomy_term');
        $this->assertSame('api.taxonomy_term.index', $termParams['_route']);
    }

    #[Test]
    public function routeDefaultsIncludeControllerInfo(): void
    {
        $routes = $this->router->getRouteCollection();

        $indexRoute = $routes->get('api.node.index');
        $this->assertNotNull($indexRoute);
        $defaults = $indexRoute->getDefaults();
        $this->assertSame('node', $defaults['_entity_type']);
        $this->assertArrayHasKey('_controller', $defaults);
        $this->assertStringContainsString('JsonApiController', $defaults['_controller']);
    }

    #[Test]
    public function fullRequestCycleForCollectionEndpoint(): void
    {
        // Seed some data.
        for ($i = 1; $i <= 3; $i++) {
            $entity = $this->nodeStorage->create([
                'title' => "Article {$i}",
                'type' => 'article',
                'status' => 1,
            ]);
            $this->nodeStorage->save($entity);
        }

        // Match the index route.
        $context = new RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        (new JsonApiRouteProvider($this->entityTypeManager))->registerRoutes($router);

        $params = $router->match('/api/node');
        $this->assertSame('api.node.index', $params['_route']);

        // Call controller.
        $doc = $this->controller->index($params['_entity_type']);
        $this->assertSame(200, $doc->statusCode);
        $array = $doc->toArray();
        $this->assertCount(3, $array['data']);
    }
}
