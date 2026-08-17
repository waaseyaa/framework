<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Introspection\JsonApi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Introspection\JsonApi\JsonApiIntrospectionProvider;

#[CoversClass(JsonApiIntrospectionProvider::class)]
final class JsonApiIntrospectionProviderTest extends TestCase
{
    #[Test]
    public function getKeyReturnsJsonapi(): void
    {
        $routes = new RouteCollection();

        $provider = new JsonApiIntrospectionProvider($routes);

        self::assertSame('jsonapi', $provider->getKey());
    }

    #[Test]
    public function provideReturnsGraphSectionWithJsonApiRoutes(): void
    {
        $routes = new RouteCollection();

        // JSON:API route with entity parameter.
        $jsonApiRoute = new Route(
            '/api/node/{node}',
            ['_controller' => 'Waaseyaa\\Api\\Controller\\JsonApiController::show'],
            [],
            [
                '_json_api' => true,
                'parameters' => ['node' => ['type' => 'entity:node']],
            ],
        );
        $jsonApiRoute->setMethods(['GET']);
        $routes->add('api.node.show', $jsonApiRoute);

        // Non-JSON:API route — should be excluded. The controller string is arbitrary
        // fixture data (exclusion is driven by the missing `_json_api` route option, not
        // this string); deliberately NOT "Waaseyaa\…"-namespaced since it names no real
        // class in any package (bin/check-package-layers PL010 fails closed on FQCNs
        // that resolve to no known PSR-4 root).
        $otherRoute = new Route(
            '/admin/dashboard',
            ['_controller' => 'App\\Admin\\Controller\\DashboardController::index'],
        );
        $otherRoute->setMethods(['GET']);
        $routes->add('admin.dashboard', $otherRoute);

        $provider = new JsonApiIntrospectionProvider($routes);
        $section = $provider->provide();

        self::assertInstanceOf(GraphSection::class, $section);
        self::assertSame('jsonapi', $section->key);
        self::assertArrayHasKey('api.node.show', $section->data);
        self::assertArrayNotHasKey('admin.dashboard', $section->data);

        $resource = $section->data['api.node.show'];
        self::assertSame('node', $resource['entity_type']);
        self::assertSame('/api/node/{node}', $resource['path']);
        self::assertSame(['GET'], $resource['methods']);
        self::assertSame('Waaseyaa\\Api\\Controller\\JsonApiController::show', $resource['controller']);
    }

    #[Test]
    public function provideHandlesJsonApiRouteWithoutEntityParameter(): void
    {
        $routes = new RouteCollection();

        // JSON:API route without entity parameter.
        $jsonApiRoute = new Route(
            '/api/search',
            ['_controller' => 'Waaseyaa\\Api\\Controller\\SearchController::index'],
            [],
            ['_json_api' => true],
        );
        $jsonApiRoute->setMethods(['GET', 'POST']);
        $routes->add('api.search', $jsonApiRoute);

        $provider = new JsonApiIntrospectionProvider($routes);
        $section = $provider->provide();

        self::assertArrayHasKey('api.search', $section->data);

        $resource = $section->data['api.search'];
        self::assertNull($resource['entity_type']);
        self::assertSame('/api/search', $resource['path']);
        self::assertSame(['GET', 'POST'], $resource['methods']);
    }

    #[Test]
    public function provideReturnsEmptyDataWhenNoJsonApiRoutes(): void
    {
        $routes = new RouteCollection();

        $otherRoute = new Route('/admin', ['_controller' => 'SomeController::index']);
        $routes->add('admin.index', $otherRoute);

        $provider = new JsonApiIntrospectionProvider($routes);
        $section = $provider->provide();

        self::assertSame([], $section->data);
    }
}
