<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;

#[CoversClass(ApiServiceProvider::class)]
final class ApiServiceProviderRouteMetadataTest extends TestCase
{
    public function testCompleteDeclarationsKeepCapturedLegacyStructureAndExplicitTargets(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/api-provider-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['profiles'] as $profile => $expected) {
            $enabled = $profile === 'enabled';
            $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
                'api.route.content_search' => $enabled, 'api.route.mcp' => true,
                'api.route.catalog' => $enabled, 'api.route.ai_catalog' => $enabled,
            ], entities: [['id' => 'article', 'api_exposed' => true], ['id' => 'hidden', 'api_exposed' => false], ['id' => 'oidc_client', 'api_exposed' => false]]);
            $provider = new ApiServiceProvider();
            $provider->register();
            $definitions = iterator_to_array($provider->routeDefinitions($context));
            self::assertCount(count($expected), $definitions);
            self::assertSame(range(0, count($expected) - 1), array_column($definitions, 'ordinal'));
            foreach ($definitions as $index => $definition) {
                $row = $expected[$index];
                unset($row['handler']);
                $priority = $row['options']['_waaseyaa_priority'] ?? 0;
                unset($row['options']['_waaseyaa_priority']);
                self::assertSame($row, [
                    'name' => $definition->name, 'path' => $definition->path,
                    'methods' => $definition->methods, 'defaults' => $definition->defaults,
                    'options' => $definition->options, 'requirements' => $definition->requirements,
                    'host' => $definition->host, 'schemes' => $definition->schemes, 'condition' => $definition->condition,
                ]);
                self::assertSame($priority, $definition->priority);
                self::assertSame(ApiServiceProvider::class, $definition->sourceId);
                self::assertSame('class', $definition->handler->kind);
                self::assertArrayHasKey($definition->handler->target, $provider->getBindings());
            }
        }
    }
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testColdCompleteDeclarationsUseOnlyCopiedInputs(): void
    {
        $bus = new class implements \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface {
            public bool $poison = false;
            public int $reads = 0;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                if ($this->poison) {
                    throw new \LogicException('Inspection resolved services.');
                }
                return null;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        foreach ([\Waaseyaa\Foundation\Routing\Metadata\RouteDefinition::class, \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::class] as $class) {
            new \ReflectionClass($class);
        }
        $bus->poison = true;
        $bus->reads = 0;
        $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
            'api.route.content_search' => true, 'api.route.mcp' => true,
            'api.route.catalog' => true, 'api.route.ai_catalog' => true,
            'service:Waaseyaa\Workflows\Transition\TransitionService' => true,
        ], entities: [['id' => 'article', 'api_exposed' => true], ['id' => 'oidc_client', 'api_exposed' => false]]);
        $loads = [];
        $trap = static function (string $class) use (&$loads): void {
            $loads[] = $class;
            throw new \LogicException('Inspection autoloaded execution code.');
        };
        spl_autoload_register($trap, true, true);
        try {
            $first = iterator_to_array($provider->routeDefinitions($context));
            $second = iterator_to_array($provider->routeDefinitions($context));
        } finally {
            spl_autoload_unregister($trap);
        }
        self::assertSame([], $loads);
        self::assertSame(0, $bus->reads);
        self::assertSame(array_map(static fn($route) => $route->toArray(), $first), array_map(static fn($route) => $route->toArray(), $second));
        self::assertContains('api.article.workflow_transition', array_column($first, 'name'));
        self::assertContains('api.mcp.approvals.decision', array_column($first, 'name'));
        self::assertContains('api.oidc-clients.create', array_column($first, 'name'));
        self::assertSame(range(0, count($first) - 1), array_column($first, 'ordinal'));
        foreach ($first as $route) {
            self::assertArrayHasKey($route->handler->target, $provider->getBindings());
            self::assertTrue(new \ReflectionMethod($route->handler->target, $route->handler->method)->isPublic());
        }
    }

    public function testMissingFinalizedAvailabilityRefusesAndAbsentCapabilitiesRemoveOnlyOwnedRoutes(): void
    {
        $provider = new ApiServiceProvider();
        try {
            iterator_to_array($provider->routeDefinitions(new RouteContributionContext(ApiServiceProvider::class, 0)));
            self::fail('Missing boot facts must refuse.');
        } catch (\Waaseyaa\Foundation\Routing\Metadata\RouteCompositionException $error) {
            self::assertSame('inputs-unavailable', $error->reason);
        }
        $context = new RouteContributionContext(ApiServiceProvider::class, 0, capabilities: [
            'api.route.content_search' => false, 'api.route.mcp' => false,
            'api.route.catalog' => false, 'api.route.ai_catalog' => false,
        ], entities: [['id' => 'hidden', 'api_exposed' => false]]);
        $routes = iterator_to_array($provider->routeDefinitions($context));
        $names = array_column($routes, 'name');
        foreach (['api.content_search', 'api.catalog', 'ai.catalog', 'api.mcp.approvals.decision', 'api.oidc-clients.create', 'api.hidden.workflow_transition'] as $absent) {
            self::assertNotContains($absent, $names);
        }
        self::assertContains('api.hidden.not_exposed_path', $names);
        self::assertContains('api.classification.policies.update', $names);
        self::assertSame(range(0, count($routes) - 1), array_column($routes, 'ordinal'));
    }

    public function testBareCompatibilityProjectionMatchesCapturedCompleteTable(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/api-provider-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixture['profiles'] as $profile => $expected) {
            $manager = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
            foreach (['article' => true, 'hidden' => false, 'oidc_client' => false] as $id => $exposed) {
                $manager->registerEntityType(new \Waaseyaa\Entity\EntityType(id: $id, label: $id, class: \stdClass::class, api: $exposed));
            }
            $provider = new ApiServiceProvider();
            $enabled = $profile === 'enabled';
            $provider->setKernelContext('/tmp/test-project', [
                'api' => ['content_search' => ['enabled' => $enabled]],
                'api_catalog' => ['enabled' => $enabled, 'base_url' => 'https://cms.example'],
                'ai_catalog' => ['enabled' => $enabled, 'base_url' => 'https://cms.example'],
            ], []);
            $provider->setKernelServices(new class ($manager) implements \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface {
                public function __construct(private \Waaseyaa\Entity\EntityTypeManager $manager) {}
                public function get(string $abstract): ?object
                {
                    return $abstract === \Waaseyaa\Entity\EntityTypeManager::class ? $this->manager : null;
                }
            });
            $provider->register();
            $provider->withApiCatalogEntryProviders([$provider]);
            $provider->withAiCatalogEntryProviders([$provider]);
            $provider->boot();
            $router = new \Waaseyaa\Routing\WaaseyaaRouter();
            $provider->routes($router, $manager);
            $rows = [];
            foreach ($router->getRouteCollection() as $name => $route) {
                $defaults = $route->getDefaults();
                $controller = $defaults['_controller'];
                unset($defaults['_controller']);
                $options = $route->getOptions();
                unset($options['compiler_class']);
                $rows[] = ['name' => $name, 'path' => $route->getPath(), 'handler' => $controller instanceof \Closure ? 'Waaseyaa\Api\Controller\NotExposedController::__invoke' : $controller,
                    'methods' => $route->getMethods(), 'defaults' => $defaults, 'options' => $options,
                    'requirements' => $route->getRequirements(), 'host' => $route->getHost(), 'schemes' => $route->getSchemes(), 'condition' => $route->getCondition()];
            }
            self::assertSame($expected, $rows);
        }
    }

}
