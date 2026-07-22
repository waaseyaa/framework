<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Fixtures\UserNameContentTestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\Exception\RouteNotFoundException;
use Waaseyaa\Routing\WaaseyaaRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Foundation\Http\ControllerDispatcher;

#[CoversClass(JsonApiRouteProvider::class)]
final class JsonApiRouteProviderTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private WaaseyaaRouter $router;

    protected function setUp(): void
    {
        $this->resetStructuralRouteCache();
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter();
    }

    #[Test]
    public function equivalent_manager_shapes_reuse_templates_but_receive_fresh_routes_and_collections(): void
    {
        $firstManager = $this->managerWith(['user' => true, 'article' => true]);
        $secondManager = $this->managerWith(['article' => true, 'user' => true]);
        $firstRouter = new WaaseyaaRouter();
        $secondRouter = new WaaseyaaRouter();

        new JsonApiRouteProvider($firstManager)->registerRoutes($firstRouter);
        $firstCache = $this->structuralRouteCache();
        self::assertCount(1, $firstCache);
        $firstTemplate = array_values($firstCache)[0][0]['route'];
        new JsonApiRouteProvider($secondManager)->registerRoutes($secondRouter);

        $secondCache = $this->structuralRouteCache();
        self::assertCount(1, $secondCache);
        self::assertSame($firstTemplate, array_values($secondCache)[0][0]['route']);
        $firstInternalCollection = $this->routerCollection($firstRouter);
        $secondInternalCollection = $this->routerCollection($secondRouter);
        self::assertNotSame($firstInternalCollection, $secondInternalCollection);
        $firstInternalRoute = $firstInternalCollection->get('api.article.index');
        $secondInternalRoute = $secondInternalCollection->get('api.article.index');
        self::assertNotNull($firstInternalRoute);
        self::assertNotNull($secondInternalRoute);
        self::assertNotSame($firstTemplate, $firstInternalRoute);
        self::assertNotSame($firstInternalRoute, $secondInternalRoute);
        $firstInternalRoute->setPath('/mutated-only-in-first-router');
        self::assertSame('/api/article', $secondInternalRoute->getPath());
        $firstCollection = $firstRouter->getRouteCollection();
        $secondCollection = $secondRouter->getRouteCollection();
        self::assertNotSame($firstCollection, $secondCollection);
        $firstRoute = $firstCollection->get('api.article.index');
        $secondRoute = $secondCollection->get('api.article.index');
        self::assertNotNull($firstRoute);
        self::assertNotNull($secondRoute);
        self::assertNotSame($firstRoute, $secondRoute);
        self::assertSame('/mutated-only-in-first-router', $firstRoute->getPath());
        self::assertSame('/api/article', $secondRoute->getPath());
    }

    #[Test]
    public function structural_cache_key_binds_ids_exposure_base_path_and_workflow_request(): void
    {
        new JsonApiRouteProvider($this->managerWith(['article' => true]))
            ->registerRoutes(new WaaseyaaRouter());
        new JsonApiRouteProvider($this->managerWith(['article' => true]))
            ->registerRoutes(new WaaseyaaRouter());
        self::assertCount(1, $this->structuralRouteCache());

        new JsonApiRouteProvider($this->managerWith(['article' => true]), '/jsonapi')
            ->registerRoutes(new WaaseyaaRouter());
        new JsonApiRouteProvider($this->managerWith(['user' => true]))
            ->registerRoutes(new WaaseyaaRouter());
        new JsonApiRouteProvider($this->managerWith(['article' => false]))
            ->registerRoutes(new WaaseyaaRouter());
        self::assertCount(2, $this->structuralRouteCache());

        $beforeWorkflow = array_keys($this->structuralRouteCache());
        new JsonApiRouteProvider($this->managerWith(['article' => true]))
            ->registerWorkflowTransitionRoutes(new WaaseyaaRouter());
        $afterWorkflow = array_keys($this->structuralRouteCache());
        self::assertCount(2, $afterWorkflow);
        self::assertNotSame($beforeWorkflow, $afterWorkflow);
        self::assertTrue(array_any($afterWorkflow, static fn(string $key): bool => str_contains($key, "\0workflow\0")));
        new JsonApiRouteProvider($this->managerWith(['article' => true]))
            ->registerWorkflowTransitionRoutes(new WaaseyaaRouter());
        self::assertSame($afterWorkflow, array_keys($this->structuralRouteCache()));
    }

    #[Test]
    public function structural_cache_is_bounded_and_opaque_not_found_closures_capture_no_state(): void
    {
        $representativeTypes = [];
        for ($index = 0; $index < 45; ++$index) {
            $representativeTypes[sprintf('type_%02d', $index)] = true;
        }
        $memoryBefore = memory_get_usage();
        for ($index = 0; $index < 12; ++$index) {
            new JsonApiRouteProvider($this->managerWith($representativeTypes), '/api-' . $index)
                ->registerRoutes(new WaaseyaaRouter());
        }
        gc_collect_cycles();
        self::assertLessThanOrEqual(2, $this->structuralRouteCacheSize());
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $memoryBefore);

        $router = new WaaseyaaRouter();
        new JsonApiRouteProvider($this->managerWith(['hidden' => false]), '/diagnostic')
            ->registerRoutes($router);
        $route = $router->getRouteCollection()->get('api.hidden.not_exposed');
        self::assertNotNull($route);
        $controller = $route->getDefault('_controller');
        self::assertInstanceOf(\Closure::class, $controller);
        self::assertSame([], (new \ReflectionFunction($controller))->getStaticVariables());
    }

    #[Test]
    public function registersAllCrudRoutesForEntityType(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $routes = $this->router->getRouteCollection();

        // Eleven routes per entity type (5 CRUD + 1 field auto-save + 5 translation), plus the discovery route.
        $this->assertCount(12, $routes);
        $this->assertNotNull($routes->get('api.article.index'));
        $this->assertNotNull($routes->get('api.article.show'));
        $this->assertNotNull($routes->get('api.article.store'));
        $this->assertNotNull($routes->get('api.article.update'));
        $this->assertNotNull($routes->get('api.article.destroy'));
    }

    #[Test]
    public function application_allowlist_drives_routes_and_account_independent_not_found_responses(): void
    {
        $manager = $this->managerWith(['article' => true, 'tag' => true]);
        $policy = EntityTypeApiExposurePolicy::fromConfig($manager, [
            'api' => ['entity_type_allowlist' => ['article']],
        ]);
        $router = new WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        new JsonApiRouteProvider($manager, exposurePolicy: $policy)->registerRoutes($router);

        self::assertNotNull($router->getRouteCollection()->get('api.article.index'));
        self::assertNull($router->getRouteCollection()->get('api.tag.index'));

        $diagnostic = $router->getRouteCollection()->get('api.tag.not_exposed');
        self::assertNotNull($diagnostic);
        $controller = $diagnostic->getDefault('_controller');

        $anonymous = Request::create('/api/tag', 'GET');
        $anonymous->attributes->set('_account', new class implements \Waaseyaa\Access\AccountInterface {
            public function id(): int|string { return 0; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return []; }
            public function isAuthenticated(): bool { return false; }
        });
        $anonymousResult = $controller($anonymous);
        self::assertSame([
            'statusCode' => 404,
            'body' => [
                'jsonapi' => ['version' => '1.1'],
                'errors' => [[
                    'status' => '404',
                    'title' => 'Not Found',
                    'detail' => 'No route matches the requested path.',
                ]],
            ],
        ], $anonymousResult);

        $authenticated = clone $anonymous;
        $authenticated->attributes->set('_account', new class implements \Waaseyaa\Access\AccountInterface {
            public function id(): int|string { return 1; }
            public function hasPermission(string $permission): bool { return false; }
            public function getRoles(): array { return ['authenticated']; }
            public function isAuthenticated(): bool { return true; }
        });
        $authenticatedResult = $controller($authenticated);
        self::assertSame($anonymousResult, $authenticatedResult);
        self::assertArrayNotHasKey('code', $authenticatedResult['body']['errors'][0]);
        self::assertStringNotContainsString('tag', json_encode($authenticatedResult, JSON_THROW_ON_ERROR));

        self::assertSame($authenticatedResult, $controller($authenticated));
        self::assertSame($authenticatedResult, $controller($anonymous));

        self::assertNull($router->getRouteCollection()->get('api.article.related'));
        self::assertNull($router->getRouteCollection()->get('api.article.relationships'));
    }

    #[Test]
    public function sequential_allowlist_shapes_do_not_reuse_structural_routes(): void
    {
        $manager = $this->managerWith(['article' => true, 'tag' => true]);
        $articlePolicy = EntityTypeApiExposurePolicy::fromConfig($manager, [
            'api' => ['entity_type_allowlist' => ['article']],
        ]);
        $tagPolicy = EntityTypeApiExposurePolicy::fromConfig($manager, [
            'api' => ['entity_type_allowlist' => ['tag']],
        ]);
        $articleRouter = new WaaseyaaRouter();
        $tagRouter = new WaaseyaaRouter();

        new JsonApiRouteProvider($manager, exposurePolicy: $articlePolicy)->registerRoutes($articleRouter);
        new JsonApiRouteProvider($manager, exposurePolicy: $tagPolicy)->registerRoutes($tagRouter);

        self::assertNotNull($articleRouter->getRouteCollection()->get('api.article.index'));
        self::assertNull($articleRouter->getRouteCollection()->get('api.tag.index'));
        self::assertNull($tagRouter->getRouteCollection()->get('api.article.index'));
        self::assertNotNull($tagRouter->getRouteCollection()->get('api.tag.index'));
    }

    #[Test]
    public function related_and_relationship_linkage_paths_remain_ordinary_unknown_routes(): void
    {
        $router = new WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        new JsonApiRouteProvider($this->managerWith(['article' => true]))->registerRoutes($router);

        foreach ([
            '/api/article/1/author',
            '/api/article/1/relationships/author',
            '/api/article/1/missing',
            '/api/article/1/relationships/missing',
        ] as $path) {
            try {
                $router->match($path);
                self::fail("Expected {$path} to remain outside the route table.");
            } catch (RouteNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function unexposedFeedTypesReceiveOnlyOpaqueNotFoundRoutesByDefault(): void
    {
        foreach (['feed_source', 'feed_item', 'fetch_log'] as $entityTypeId) {
            $this->entityTypeManager->registerEntityType(new EntityType(
                id: $entityTypeId,
                label: $entityTypeId,
                class: TestEntity::class,
                keys: TestEntity::definitionKeys(),
            ));
        }

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);
        $provider->registerWorkflowTransitionRoutes($this->router);

        $routes = $this->router->getRouteCollection();
        foreach (['feed_source', 'feed_item', 'fetch_log'] as $entityTypeId) {
            self::assertNull($routes->get("api.{$entityTypeId}.index"));
            self::assertNull($routes->get("api.{$entityTypeId}.workflow_transitions"));
            self::assertNull($routes->get("api.{$entityTypeId}.workflow_transition"));
            $diagnostic = $routes->get("api.{$entityTypeId}.not_exposed");
            self::assertNotNull($diagnostic);
            self::assertSame('/api/' . $entityTypeId, $diagnostic->getPath());

            $request = Request::create('/api/' . $entityTypeId);
            $request->attributes->set('_account', new class implements \Waaseyaa\Access\AccountInterface {
                public function id(): int|string { return 1; }
                public function hasPermission(string $permission): bool { return false; }
                public function getRoles(): array { return ['authenticated']; }
                public function isAuthenticated(): bool { return true; }
            });
            $response = ($diagnostic->getDefault('_controller'))($request);
            self::assertSame(404, $response['statusCode']);
            self::assertArrayNotHasKey('code', $response['body']['errors'][0]);
            self::assertSame('No route matches the requested path.', $response['body']['errors'][0]['detail']);
            self::assertStringNotContainsString($entityTypeId, json_encode($response, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function anonymousUnexposedTypeRequestIsIndistinguishableFromAnUnknownRoute(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'feed_source',
            label: 'Feed source',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
        ));

        $router = new WaaseyaaRouter(new \Symfony\Component\Routing\RequestContext('', 'GET'));
        new JsonApiRouteProvider($this->entityTypeManager)->registerRoutes($router);
        $match = $router->match('/api/feed_source');
        $request = Request::create('/api/feed_source');
        $request->attributes->add($match);

        $response = new ControllerDispatcher([])->dispatch($request);
        $document = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->getStatusCode());
        self::assertArrayNotHasKey('code', $document['errors'][0]);
        self::assertSame('No route matches the requested path.', $document['errors'][0]['detail']);
        self::assertStringNotContainsString('feed_source', json_encode($document, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function indexRouteHasCorrectPathAndMethod(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.index');

        $this->assertSame('/api/article', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame('article', $route->getDefault('_entity_type'));
    }

    #[Test]
    public function showRouteHasIdParameter(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.show');

        $this->assertSame('/api/article/{id}', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
    }

    #[Test]
    public function storeRouteIsPostMethod(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.store');

        $this->assertSame('/api/article', $route->getPath());
        $this->assertSame(['POST'], $route->getMethods());
    }

    #[Test]
    public function updateRouteIsPatchMethod(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.update');

        $this->assertSame('/api/article/{id}', $route->getPath());
        $this->assertSame(['PATCH'], $route->getMethods());
    }

    #[Test]
    public function destroyRouteIsDeleteMethod(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.destroy');

        $this->assertSame('/api/article/{id}', $route->getPath());
        $this->assertSame(['DELETE'], $route->getMethods());
    }

    #[Test]
    public function writeRoutesRequireAuthentication(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $routes = $this->router->getRouteCollection();

        $this->assertTrue($routes->get('api.article.store')->getOption('_authenticated'));
        $this->assertTrue($routes->get('api.article.update')->getOption('_authenticated'));
        $this->assertTrue($routes->get('api.article.destroy')->getOption('_authenticated'));
    }

    #[Test]
    public function readRoutesDoNotRequireAuthentication(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $routes = $this->router->getRouteCollection();

        $this->assertNull($routes->get('api.article.index')->getOption('_authenticated'));
        $this->assertNull($routes->get('api.article.show')->getOption('_authenticated'));
    }

    #[Test]
    public function registersRoutesForMultipleEntityTypes(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'user',
            label: 'User',
            class: UserNameContentTestEntity::class,
            keys: UserNameContentTestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $routes = $this->router->getRouteCollection();

        // 11 routes per entity type (5 CRUD + 1 field auto-save + 5 translation) x 2 entity types + 1 discovery route = 23 routes.
        $this->assertCount(23, $routes);
        $this->assertNotNull($routes->get('api.article.index'));
        $this->assertNotNull($routes->get('api.user.index'));
    }

    #[Test]
    public function customBasePathIsUsed(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager, '/jsonapi');
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.index');

        $this->assertSame('/jsonapi/article', $route->getPath());
    }

    #[Test]
    public function noRoutesRegisteredWhenNoEntityTypes(): void
    {
        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $routes = $this->router->getRouteCollection();

        // Discovery route always registered even with no entity types.
        $this->assertCount(1, $routes);
        $this->assertNotNull($routes->get('api.discovery'));
    }

    #[Test]
    public function routesContainControllerDefaults(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        $route = $this->router->getRouteCollection()->get('api.article.index');

        $this->assertSame(
            'Waaseyaa\\Api\\JsonApiController::index',
            $route->getDefault('_controller'),
        );
    }

    #[Test]
    public function routeMatchingWorksForCollectionPath(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($this->router);

        // The router should be able to match the path.
        $context = new \Symfony\Component\Routing\RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);
        $provider->registerRoutes($router);

        $match = $router->match('/api/article');

        $this->assertSame('api.article.index', $match['_route']);
        $this->assertSame('article', $match['_entity_type']);
    }

    #[Test]
    public function routeMatchingWorksForResourcePath(): void
    {
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $context = new \Symfony\Component\Routing\RequestContext('', 'GET');
        $router = new WaaseyaaRouter($context);

        $provider = new JsonApiRouteProvider($this->entityTypeManager);
        $provider->registerRoutes($router);

        $match = $router->match('/api/article/42');

        $this->assertSame('api.article.show', $match['_route']);
        $this->assertSame('42', $match['id']);
        $this->assertSame('article', $match['_entity_type']);
    }

    /** @param array<string, bool> $types */
    private function managerWith(array $types): EntityTypeManager
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        foreach ($types as $id => $exposed) {
            $manager->registerEntityType(new EntityType(
                id: $id,
                label: ucfirst($id),
                class: TestEntity::class,
                keys: TestEntity::definitionKeys(),
                api: $exposed,
            ));
        }

        return $manager;
    }

    private function resetStructuralRouteCache(): void
    {
        $this->setProviderStatic('structuralRouteCache', []);
    }

    /** @return array<string, list<array{name: string, route: \Symfony\Component\Routing\Route}>> */
    private function structuralRouteCache(): array
    {
        $cache = $this->providerStatic('structuralRouteCache');
        self::assertIsArray($cache);

        return $cache;
    }

    private function structuralRouteCacheSize(): int
    {
        return count($this->structuralRouteCache());
    }

    private function setProviderStatic(string $name, mixed $value): void
    {
        (new \ReflectionProperty(JsonApiRouteProvider::class, $name))->setValue(null, $value);
    }

    private function providerStatic(string $name): mixed
    {
        return (new \ReflectionProperty(JsonApiRouteProvider::class, $name))->getValue();
    }

    private function routerCollection(WaaseyaaRouter $router): \Symfony\Component\Routing\RouteCollection
    {
        $collection = (new \ReflectionProperty(WaaseyaaRouter::class, 'routes'))->getValue($router);
        self::assertInstanceOf(\Symfony\Component\Routing\RouteCollection::class, $collection);

        return $collection;
    }
}
