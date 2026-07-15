<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use Waaseyaa\Api\JsonApiRouteProvider;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Api\Tests\Fixtures\UserNameContentTestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
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
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $this->router = new WaaseyaaRouter();
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
    public function unexposedFeedTypesReceiveOnlyLoudDiagnosticRoutesByDefault(): void
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

            $response = ($diagnostic->getDefault('_controller'))();
            self::assertSame(404, $response['statusCode']);
            self::assertSame('entity_type_not_api_exposed', $response['body']['errors'][0]['code']);
            self::assertStringContainsString('api: true', $response['body']['errors'][0]['detail']);
        }
    }

    #[Test]
    public function unexposedTypeRequestReturnsNamedApiExposureDiagnostic(): void
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
        self::assertSame('entity_type_not_api_exposed', $document['errors'][0]['code']);
        self::assertStringContainsString('feed_source', $document['errors'][0]['detail']);
        self::assertStringContainsString('api: true', $document['errors'][0]['detail']);
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
}
