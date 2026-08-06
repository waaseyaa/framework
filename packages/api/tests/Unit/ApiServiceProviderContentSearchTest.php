<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Auth\AtomicRateLimiterInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Discovery\AiCatalog\AiCatalogEntry;
use Waaseyaa\Foundation\Discovery\ApiCatalog\ApiCatalogEntry;
use Waaseyaa\Foundation\Exception\ConfigException;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesAiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApiCatalogEntriesInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Search\SearchProviderInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderContentSearchTest extends TestCase
{
    #[Test]
    public function disabled_configuration_leaves_no_route(): void
    {
        foreach ([[], ['api' => ['content_search' => ['enabled' => false]]]] as $config) {
            $provider = $this->provider($config, []);
            $router = new WaaseyaaRouter();

            $provider->routes($router, new EntityTypeManager(new EventDispatcher()));

            self::assertNull($router->getRouteCollection()->get('api.content_search'));
        }
    }

    #[Test]
    public function enabled_services_register_an_exact_public_get_and_head_route_ahead_of_entity_routes(): void
    {
        $search = $this->createStub(SearchProviderInterface::class);
        $limiter = $this->createStub(AtomicRateLimiterInterface::class);
        $provider = $this->provider(
            ['api' => ['content_search' => ['enabled' => true]]],
            [SearchProviderInterface::class => $search, AtomicRateLimiterInterface::class => $limiter],
        );
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType(
            id: 'content',
            label: 'Content',
            class: TestEntity::class,
            keys: TestEntity::definitionKeys(),
            api: true,
        ));
        $router = new WaaseyaaRouter();

        $provider->routes($router, $manager);
        $router->sortRoutesByPriority();
        $route = $router->getRouteCollection()->get('api.content_search');

        self::assertNotNull($route);
        self::assertSame('/api/content/search', $route->getPath());
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
        self::assertTrue($route->getOption('_public'));
        self::assertSame(100, $route->getOption('_waaseyaa_priority'));
        self::assertSame('api.content_search', $router->match('/api/content/search')['_route']);
    }

    #[Test]
    public function enabled_search_contributes_the_same_public_artifact_to_api_and_ai_catalogs(): void
    {
        $provider = $this->provider(
            ['api' => ['content_search' => ['enabled' => true]]],
            [],
        );

        self::assertInstanceOf(ProvidesApiCatalogEntriesInterface::class, $provider);
        self::assertInstanceOf(ProvidesAiCatalogEntriesInterface::class, $provider);

        $apiEntries = $provider->apiCatalogEntries();
        self::assertCount(1, $apiEntries);
        self::assertInstanceOf(ApiCatalogEntry::class, $apiEntries[0]);
        self::assertSame([
            'path' => '/api/content/search',
            'type' => 'application/vnd.api+json',
            'title' => 'Public content search',
        ], $apiEntries[0]->endpoint->fingerprintData());

        $searchEntries = array_values(array_filter(
            $provider->aiCatalogEntries(),
            static fn(AiCatalogEntry $entry): bool => $entry->key === 'api:content-search',
        ));
        self::assertCount(1, $searchEntries);
        self::assertSame('/api/content/search', $searchEntries[0]->path);
        self::assertSame(['ContentDiscovery', 'ReadOnlySearch'], $searchEntries[0]->capabilities);
    }

    #[Test]
    public function disabled_search_withdraws_both_catalog_contributions(): void
    {
        foreach ([[], ['api' => ['content_search' => ['enabled' => false]]]] as $config) {
            $provider = $this->provider($config, []);

            self::assertSame([], $provider->apiCatalogEntries());
            self::assertSame([], $provider->aiCatalogEntries());
        }
    }

    #[Test]
    public function enabled_catalogs_accept_application_queries_for_the_public_search_artifact(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $provider = $this->provider([
            'api' => ['content_search' => ['enabled' => true]],
            'api_catalog' => [
                'enabled' => true,
                'base_url' => 'https://cms.example',
            ],
            'ai_catalog' => [
                'enabled' => true,
                'base_url' => 'https://cms.example',
                'representative_queries' => [
                    'api:content-search' => [
                        'Find health services',
                        'Search upcoming community events',
                    ],
                ],
            ],
        ], [EntityTypeManager::class => $manager]);
        $provider->register();
        $provider->withApiCatalogEntryProviders([$provider]);
        $provider->withAiCatalogEntryProviders([$provider]);

        $provider->boot();
        $router = new WaaseyaaRouter();
        $provider->routes($router, $manager);

        self::assertNotNull($router->getRouteCollection()->get('api.catalog'));
        self::assertNotNull($router->getRouteCollection()->get('ai.catalog'));
    }

    #[Test]
    public function malformed_configuration_fails_closed(): void
    {
        foreach ([
            ['api' => ['content_search' => ['enabled' => 'true']]],
            ['api' => ['content_search' => ['enabled' => true, 'rate_limit' => ['identity_max' => 0]]]],
            ['api' => ['content_search' => ['enabled' => true, 'rate_limit' => ['identity_max' => 50, 'global_max' => 20]]]],
        ] as $config) {
            $provider = $this->provider($config, [
                SearchProviderInterface::class => $this->createStub(SearchProviderInterface::class),
                AtomicRateLimiterInterface::class => $this->createStub(AtomicRateLimiterInterface::class),
            ]);

            try {
                $provider->routes(new WaaseyaaRouter(), new EntityTypeManager(new EventDispatcher()));
                self::fail('Malformed content-search configuration must fail closed.');
            } catch (ConfigException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function route_registration_never_resolves_database_backed_optional_services(): void
    {
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', [
            'api' => ['content_search' => ['enabled' => true]],
        ], []);
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                throw new \RuntimeException('Route registration eagerly resolved ' . $abstract);
            }
        });

        $router = new WaaseyaaRouter();
        $provider->routes($router, new EntityTypeManager(new EventDispatcher()));

        self::assertNotNull($router->getRouteCollection()->get('api.content_search'));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<class-string, object> $services
     */
    private function provider(array $config, array $services): ApiServiceProvider
    {
        $provider = new ApiServiceProvider();
        $provider->setKernelContext('/tmp/test-project', $config, []);
        $provider->setKernelServices(new class ($services) implements KernelServicesInterface {
            /** @param array<class-string, object> $services */
            public function __construct(private readonly array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        });

        return $provider;
    }
}
