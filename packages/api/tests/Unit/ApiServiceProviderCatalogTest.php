<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogTarget;
use Waaseyaa\Foundation\ServiceProvider\Capability\AcceptsApiCatalogEntryProvidersInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderCatalogTest extends TestCase
{
    #[Test]
    public function configured_catalog_is_public_get_head_and_owned_by_the_api_package(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = $this->provider($manager, 'https://cms.example');
        self::assertInstanceOf(AcceptsApiCatalogEntryProvidersInterface::class, $provider);
        $provider->withApiCatalogEntryProviders([$this->contributor('/mcp')]);
        $provider->boot();

        $router = new WaaseyaaRouter();
        $provider->routes($router, $manager);
        $route = $router->getRouteCollection()->get('api.catalog');

        self::assertNotNull($route);
        self::assertSame('/.well-known/api-catalog', $route->getPath());
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
        self::assertTrue($route->getOption('_public'));
        self::assertSame('api.catalog', $route->getDefault('_controller'));
    }

    #[Test]
    public function catalog_route_is_absent_without_a_canonical_base_url(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = $this->provider($manager, null);
        $provider->withApiCatalogEntryProviders([$this->contributor('/mcp')]);
        $provider->boot();

        $router = new WaaseyaaRouter();
        $provider->routes($router, $manager);

        self::assertNull($router->getRouteCollection()->get('api.catalog'));
    }

    #[Test]
    public function explicit_enable_without_a_canonical_base_url_fails_closed_without_echoing_config(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = $this->provider($manager, null, enabled: true);
        $provider->withApiCatalogEntryProviders([$this->contributor('/mcp')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('api_catalog.base_url');
        $provider->boot();
    }

    #[Test]
    public function conflicting_contributors_fail_during_boot(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = $this->provider($manager, 'https://cms.example');
        $provider->withApiCatalogEntryProviders([
            $this->contributor('/mcp', 'application/json'),
            $this->contributor('/mcp', 'text/plain'),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('/mcp');
        $provider->boot();
    }

    #[Test]
    public function non_string_base_url_is_a_configuration_error_not_a_silent_disable(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', [
            'api_catalog' => ['base_url' => true],
        ], []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('api_catalog.base_url');
        $provider->boot();
    }

    private function provider(EntityTypeManager $manager, ?string $baseUrl, ?bool $enabled = null): ApiServiceProvider
    {
        $config = [];
        if ($baseUrl !== null || $enabled !== null) {
            $config['api_catalog'] = array_filter([
                'base_url' => $baseUrl,
                'enabled' => $enabled,
            ], static fn(mixed $value): bool => $value !== null);
        }

        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', $config, []);
        $provider->setKernelServices(new class ($manager) implements KernelServicesInterface {
            public function __construct(private readonly EntityTypeManager $manager) {}

            public function get(string $abstract): ?object
            {
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        });
        $provider->register();

        return $provider;
    }

    private function contributor(string $path, string $type = 'application/json'): ServiceProvider
    {
        return new class ($path, $type) extends ServiceProvider implements ProvidesApiCatalogEntriesInterface {
            public function __construct(private readonly string $path, private readonly string $type) {}

            public function register(): void {}

            public function apiCatalogEntries(): array
            {
                return [new ApiCatalogEntry(new ApiCatalogTarget($this->path, $this->type))];
            }
        };
    }
}
