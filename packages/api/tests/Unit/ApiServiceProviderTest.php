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
use Waaseyaa\Foundation\Routing\Metadata\RouteExposureInputs;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderTest extends TestCase
{
    public function testBootPublishesTheExistingNarrowedExposurePolicy(): void
    {
        $manager = new class (new EventDispatcher()) extends EntityTypeManager {
            public int $definitionReads = 0;
            public function getDefinitions(): array
            {
                $this->definitionReads++;
                return parent::getDefinitions();
            }
        };
        $manager->registerEntityType(new EntityType(id: 'post', label: 'Post', class: \stdClass::class, api: true));
        $inputs = new RouteExposureInputs();
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', ['api' => ['entity_type_allowlist' => []], 'api_catalog' => ['enabled' => false], 'ai_catalog' => ['enabled' => false]], []);
        $provider->setKernelServices(new class ($manager, $inputs) implements KernelServicesInterface {
            public function __construct(private EntityTypeManager $manager, private RouteExposureInputs $inputs) {}
            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManager::class => $this->manager,
                    RouteExposureInputs::class => $this->inputs,
                    default => null,
                };
            }
        });
        $provider->register();
        $manager->definitionReads = 0;
        $provider->boot();
        self::assertSame(1, $manager->definitionReads);
        self::assertSame(['post' => false], $inputs->freeze(['post'], true));
    }

    public function testBareProviderBootWithoutPublicationSlotRetainsPolicyBehavior(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(id: 'post', label: 'Post', class: \stdClass::class, api: true));
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', ['api_catalog' => ['enabled' => false], 'ai_catalog' => ['enabled' => false]], []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private EntityTypeManager $manager) {}
            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();
        $provider->boot();
        $policy = $provider->resolve(\Waaseyaa\Api\EntityTypeApiExposurePolicy::class);
        self::assertTrue($policy->isExposed('post'));
    }

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
        new ApiServiceProvider()->routes($router, $entityTypeManager);

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
