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
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testOptionalInstallGatesAreFrozenAtBootEvenWhenPackagesAppearLater(): void
    {
        $missing = ['Waaseyaa\\Mcp\\McpServiceProvider', 'Waaseyaa\\Search\\SearchProviderInterface'];
        foreach ($missing as $class) {
            self::assertFalse(class_exists($class, false) || interface_exists($class, false));
        }
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', [
            'api' => ['content_search' => ['enabled' => true]],
            'api_catalog' => ['enabled' => false], 'ai_catalog' => ['enabled' => false],
        ], []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private EntityTypeManager $manager) {}
            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();
        $autoloaders = spl_autoload_functions();
        foreach ($autoloaders as $autoload) {
            spl_autoload_unregister($autoload);
        }
        $probes = [];
        $installation = static function (string $class) use ($missing, $autoloaders, &$probes): void {
            if (in_array($class, $missing, true)) {
                $probes[] = $class;
                return;
            }
            foreach ($autoloaders as $autoload) {
                $autoload($class);
            }
        };
        spl_autoload_register($installation);
        try {
            $provider->boot();
        } finally {
            spl_autoload_unregister($installation);
            foreach ($autoloaders as $autoload) {
                spl_autoload_register($autoload);
            }
        }
        self::assertEqualsCanonicalizing($missing, $probes);

        $lateProbes = [];
        $poison = static function (string $class) use ($missing, &$lateProbes): void {
            if (in_array($class, $missing, true)) {
                $lateProbes[] = $class;
                throw new \LogicException('Late optional-package probe.');
            }
        };
        spl_autoload_register($poison, true, true);
        try {
            for ($read = 0; $read < 2; $read++) {
                $router = new WaaseyaaRouter();
                $provider->routes($router, $manager);
                self::assertNull($router->getRouteCollection()->get('api.content_search'));
                self::assertNull($router->getRouteCollection()->get('api.mcp.admin.tools.index'));
            }
        } finally {
            spl_autoload_unregister($poison);
        }
        self::assertSame([], $lateProbes);
        self::assertTrue(class_exists($missing[0]));
        self::assertTrue(interface_exists($missing[1]));
        $router = new WaaseyaaRouter();
        $provider->routes($router, $manager);
        self::assertNull($router->getRouteCollection()->get('api.content_search'));
        self::assertNull($router->getRouteCollection()->get('api.mcp.admin.tools.index'));
    }

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
