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
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
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
            keys: TestEntity::definitionKeys(),
            api: true,
        ));

        $router = new WaaseyaaRouter();
        (new ApiServiceProvider())->routes($router, $entityTypeManager);

        $routes = $router->getRouteCollection();
        $this->assertNotNull($routes->get('api.article.index'));
        $this->assertNotNull($routes->get('api.article.show'));
        $this->assertNotNull($routes->get('api.discovery'));
    }

    #[Test]
    public function boot_fails_fast_when_the_install_shape_does_not_register_an_allowlisted_type(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', [
            'api' => ['entity_type_allowlist' => ['removed_package_type']],
        ], []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('removed_package_type');
        $provider->boot();
    }
}
