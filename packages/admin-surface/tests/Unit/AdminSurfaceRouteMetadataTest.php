<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;

#[CoversClass(AdminSurfaceServiceProvider::class)]
final class AdminSurfaceRouteMetadataTest extends TestCase
{
    public function testCompleteDeclarationsPreserveCapturedCoreAndPageBuilderTables(): void
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/admin-route-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        $provider = new AdminSurfaceServiceProvider();
        $provider->register();
        foreach ($fixture['profiles'] as $profile => $expected) {
            $context = new RouteContributionContext(AdminSurfaceServiceProvider::class, 0, capabilities: [
                'service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface' => $profile === 'page_builder',
            ]);
            $routes = iterator_to_array($provider->routeDefinitions($context));
            self::assertCount(count($expected), $routes);
            self::assertSame(range(0, count($expected) - 1), array_column($routes, 'ordinal'));
            foreach ($routes as $index => $route) {
                self::assertSame($expected[$index], [
                    'name' => $route->name, 'path' => $route->path, 'methods' => $route->methods,
                    'defaults' => $route->defaults, 'options' => $route->options, 'requirements' => $route->requirements,
                    'host' => $route->host, 'schemes' => $route->schemes, 'condition' => $route->condition,
                ]);
                self::assertSame(0, $route->priority);
                self::assertSame(AdminSurfaceServiceProvider::class, $route->sourceId);
                self::assertArrayHasKey($route->handler->target, $provider->getBindings());
                self::assertTrue(new \ReflectionMethod($route->handler->target, $route->handler->method)->isPublic());
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testColdDeclarationsDoNotResolveHostsOrLoadRequestCode(): void
    {
        $bus = new class implements \Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface {
            public int $reads = 0;
            public function get(string $abstract): ?object
            {
                $this->reads++;
                throw new \LogicException('Declaration resolved a host.');
            }
        };
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        foreach ([\Waaseyaa\Foundation\Routing\Metadata\RouteDefinition::class, \Waaseyaa\Foundation\Routing\Metadata\HandlerReference::class] as $class) {
            new \ReflectionClass($class);
        }
        $context = new RouteContributionContext(AdminSurfaceServiceProvider::class, 0, capabilities: [
            'service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface' => true,
        ]);
        $loads = [];
        $trap = static function (string $class) use (&$loads): void {
            $loads[] = $class;
            throw new \LogicException('Declaration loaded request code.');
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
        self::assertCount(13, $first);
        self::assertSame(array_map(static fn($route) => $route->toArray(), $first), array_map(static fn($route) => $route->toArray(), $second));
    }
}
