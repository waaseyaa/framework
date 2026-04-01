<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_json_api_routes_through_the_package_service_provider(): void
    {
        $entityTypeManager = new EntityTypeManager(new EventDispatcher());
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'article',
            label: 'Article',
            class: TestEntity::class,
        ));

        $router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($router, $entityTypeManager);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.article.index'));
        $this->assertNotNull($routes->get('api.article.show'));
        $this->assertNotNull($routes->get('api.discovery'));
    }

    #[Test]
    public function does_not_register_routes_without_an_entity_type_manager(): void
    {
        $router = new WaaseyaaRouter();

        (new ApiServiceProvider())->routes($router, null);

        $this->assertCount(0, $router->getRouteCollection());
    }
}
