<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouteCollection;
use Waaseyaa\Bimaaji\BimaajiServiceProvider;
use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Graph\ApplicationGraphGenerator;
use Waaseyaa\Bimaaji\Introspection\Admin\AdminIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\Entity\EntityIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\JsonApi\JsonApiIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\PublicSurface\PublicSurfaceProvider;
use Waaseyaa\Bimaaji\Introspection\Routing\RoutingIntrospectionProvider;
use Waaseyaa\Bimaaji\Introspection\Sovereignty\SovereigntyIntrospectionProvider;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\ServiceProvider\Capability\HasNativeCommandsInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyConfigInterface;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;
use Waaseyaa\Routing\WaaseyaaRouter;

#[CoversClass(BimaajiServiceProvider::class)]
final class BimaajiServiceProviderTest extends TestCase
{
    #[Test]
    public function registers_application_graph_generator_as_singleton(): void
    {
        $provider = $this->makeProvider();

        $bindings = $provider->getBindings();
        self::assertArrayHasKey(ApplicationGraphGenerator::class, $bindings);
        self::assertTrue($bindings[ApplicationGraphGenerator::class]['shared']);

        $generator = $provider->resolve(ApplicationGraphGenerator::class);
        self::assertInstanceOf(ApplicationGraphGenerator::class, $generator);

        $again = $provider->resolve(ApplicationGraphGenerator::class);
        self::assertSame($generator, $again, 'Singleton must return the same instance.');
    }

    #[Test]
    public function registers_six_default_section_providers(): void
    {
        $provider = $this->makeProvider();

        $expected = [
            AdminIntrospectionProvider::class,
            EntityIntrospectionProvider::class,
            JsonApiIntrospectionProvider::class,
            PublicSurfaceProvider::class,
            RoutingIntrospectionProvider::class,
            SovereigntyIntrospectionProvider::class,
        ];

        $bindings = $provider->getBindings();
        foreach ($expected as $providerClass) {
            self::assertArrayHasKey(
                $providerClass,
                $bindings,
                "Expected binding for {$providerClass} in BimaajiServiceProvider.",
            );
            self::assertTrue(
                $bindings[$providerClass]['shared'],
                "Section provider {$providerClass} must be bound as a singleton.",
            );
        }
    }

    #[Test]
    public function tags_section_providers_under_canonical_tag(): void
    {
        $provider = $this->makeProvider();

        $tags = $provider->getTags();
        self::assertArrayHasKey(BimaajiServiceProvider::SECTION_PROVIDER_TAG, $tags);

        $tagged = $tags[BimaajiServiceProvider::SECTION_PROVIDER_TAG];
        self::assertCount(6, $tagged, 'Six default providers must be tagged.');

        self::assertContains(AdminIntrospectionProvider::class, $tagged);
        self::assertContains(EntityIntrospectionProvider::class, $tagged);
        self::assertContains(JsonApiIntrospectionProvider::class, $tagged);
        self::assertContains(PublicSurfaceProvider::class, $tagged);
        self::assertContains(RoutingIntrospectionProvider::class, $tagged);
        self::assertContains(SovereigntyIntrospectionProvider::class, $tagged);
    }

    #[Test]
    public function generator_produces_graph_with_six_sections(): void
    {
        $provider = $this->makeProvider();
        $generator = $provider->resolve(ApplicationGraphGenerator::class);

        $graph = $generator->generate();
        self::assertInstanceOf(ApplicationGraph::class, $graph);

        $sectionKeys = array_keys($graph->sections);
        sort($sectionKeys);
        self::assertSame(
            ['admin', 'entities', 'jsonapi', 'public_surface', 'routing', 'sovereignty'],
            $sectionKeys,
        );
    }

    #[Test]
    public function provider_dependencies_resolve_lazily_via_kernel_services(): void
    {
        $resolved = [];
        $kernel = new class($resolved) implements KernelServicesInterface {
            /** @param array<string, int> $tracker */
            public function __construct(private array &$tracker)
            {
            }

            public function get(string $abstract): ?object
            {
                $this->tracker[$abstract] = ($this->tracker[$abstract] ?? 0) + 1;

                return match ($abstract) {
                    EntityTypeManagerInterface::class, EntityTypeManager::class => self::stubEntityTypeManager(),
                    RouteCollection::class => new RouteCollection(),
                    SovereigntyConfigInterface::class => self::stubSovereigntyConfig(),
                    default => null,
                };
            }

            private static function stubEntityTypeManager(): EntityTypeManagerInterface
            {
                return new class implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
                    public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }

                    public function registerEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
                    {
                    }

                    public function registerCoreEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
                    {
                    }

                    /** @return array<string, \Waaseyaa\Entity\EntityTypeInterface> */
                    public function getDefinitions(): array
                    {
                        return [];
                    }

                    public function hasDefinition(string $entityTypeId): bool
                    {
                        return false;
                    }

                    public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }

                    public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }
                };
            }

            private static function stubSovereigntyConfig(): SovereigntyConfigInterface
            {
                return new class implements SovereigntyConfigInterface {
                    public function get(string $key): ?string
                    {
                        return null;
                    }

                    public function getProfile(): SovereigntyProfile
                    {
                        return SovereigntyProfile::SelfHosted;
                    }

                    /** @return array<string, string> */
                    public function all(): array
                    {
                        return [];
                    }
                };
            }
        };

        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext('/tmp', [], []);
        $provider->setKernelServices($kernel);
        $provider->register();

        // Before resolving the generator, kernel-services should not yet have
        // been hit (the bindings are closures that defer resolution).
        self::assertSame([], $resolved, 'Kernel-services must not be touched at register() time.');

        $generator = $provider->resolve(ApplicationGraphGenerator::class);
        self::assertInstanceOf(ApplicationGraphGenerator::class, $generator);

        // After resolving the generator, the six default providers were
        // instantiated, each having pulled its deps from the kernel-services
        // bus.
        self::assertGreaterThanOrEqual(1, $resolved[EntityTypeManagerInterface::class] ?? 0);
        self::assertGreaterThanOrEqual(1, $resolved[RouteCollection::class] ?? 0);
        self::assertGreaterThanOrEqual(1, $resolved[SovereigntyConfigInterface::class] ?? 0);
    }

    #[Test]
    public function implements_has_native_commands_interface_and_yields_graph_dump(): void
    {
        $provider = new BimaajiServiceProvider();
        self::assertInstanceOf(HasNativeCommandsInterface::class, $provider);

        $commands = iterator_to_array((function () use ($provider): \Generator {
            yield from $provider->nativeCommands();
        })());

        // Two commands: graph:dump (M1 WP02) + bimaaji:install (M5 WP03).
        self::assertCount(2, $commands);
        $byName = [];
        foreach ($commands as $command) {
            self::assertInstanceOf(\Waaseyaa\CLI\CommandDefinition::class, $command);
            $byName[$command->name] = $command;
        }

        self::assertArrayHasKey('graph:dump', $byName);
        // Three flags: --section (required value), --format (required value), --strict (none/boolean).
        $graphOptions = array_map(static fn(\Waaseyaa\CLI\OptionDefinition $opt): string => $opt->name, $byName['graph:dump']->options);
        self::assertSame(['section', 'format', 'strict'], $graphOptions);

        self::assertArrayHasKey('bimaaji:install', $byName);
        // Four flags: --client (array), --features (required+default), --dry-run (none), --force (none).
        $installOptions = array_map(static fn(\Waaseyaa\CLI\OptionDefinition $opt): string => $opt->name, $byName['bimaaji:install']->options);
        self::assertSame(['client', 'features', 'dry-run', 'force'], $installOptions);
    }

    #[Test]
    public function route_collection_resolution_falls_back_to_waaseyaa_router(): void
    {
        $router = $this->makeRouterWithEmptyCollection();

        $kernel = new class($router) implements KernelServicesInterface {
            public function __construct(private WaaseyaaRouter $router)
            {
            }

            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    WaaseyaaRouter::class => $this->router,
                    EntityTypeManagerInterface::class => null,
                    default => null,
                };
            }
        };

        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext('/tmp', [], []);
        $provider->setKernelServices($kernel);
        $provider->register();

        // Resolving the JsonApi provider must succeed by going through
        // WaaseyaaRouter::getRouteCollection() when no direct RouteCollection
        // binding exists. EntityTypeManager isn't required for this path; if
        // resolution touches it we expect the documented runtime exception.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('EntityTypeManager');
        $provider->resolve(ApplicationGraphGenerator::class);
    }

    private function makeProvider(): BimaajiServiceProvider
    {
        $provider = new BimaajiServiceProvider();
        $provider->setKernelContext('/tmp', [], []);
        $provider->setKernelServices($this->makeKernelServices());
        $provider->register();

        return $provider;
    }

    private function makeKernelServices(): KernelServicesInterface
    {
        return new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                return match ($abstract) {
                    EntityTypeManagerInterface::class, EntityTypeManager::class => self::stubEntityTypeManager(),
                    RouteCollection::class => new RouteCollection(),
                    SovereigntyConfigInterface::class => self::stubSovereigntyConfig(),
                    default => null,
                };
            }

            private static function stubEntityTypeManager(): EntityTypeManagerInterface
            {
                return new class implements EntityTypeManagerInterface {
            public function resolveFieldDefinitions(string $entityTypeId, ?string $bundle = null): array { return []; }
                    public function getDefinition(string $entityTypeId): \Waaseyaa\Entity\EntityTypeInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }

                    public function registerEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
                    {
                    }

                    public function registerCoreEntityType(\Waaseyaa\Entity\EntityTypeInterface $type, ?string $registrant = null): void
                    {
                    }

                    /** @return array<string, \Waaseyaa\Entity\EntityTypeInterface> */
                    public function getDefinitions(): array
                    {
                        return [];
                    }

                    public function hasDefinition(string $entityTypeId): bool
                    {
                        return false;
                    }

                    public function getStorage(string $entityTypeId): \Waaseyaa\Entity\Storage\EntityStorageInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }

                    public function getRepository(string $entityTypeId): \Waaseyaa\Entity\Repository\EntityRepositoryInterface
                    {
                        throw new \RuntimeException('not used in WP01 unit test');
                    }
                };
            }

            private static function stubSovereigntyConfig(): SovereigntyConfigInterface
            {
                return new class implements SovereigntyConfigInterface {
                    public function get(string $key): ?string
                    {
                        return null;
                    }

                    public function getProfile(): SovereigntyProfile
                    {
                        return SovereigntyProfile::SelfHosted;
                    }

                    /** @return array<string, string> */
                    public function all(): array
                    {
                        return [];
                    }
                };
            }
        };
    }

    private function makeRouterWithEmptyCollection(): WaaseyaaRouter
    {
        $reflection = new \ReflectionClass(WaaseyaaRouter::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        if ($reflection->hasProperty('routes')) {
            $property = $reflection->getProperty('routes');
            $property->setValue($instance, new RouteCollection());
        }

        return $instance;
    }
}
