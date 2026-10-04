<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiDiscoveryController;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Api\EntityTypeApiExposurePolicy;
use Waaseyaa\Api\Http\DiscoveryApiHandler;
use Waaseyaa\Api\Http\Router\DiscoveryRouter;
use Waaseyaa\Api\Tests\Fixtures\TestEntity;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(DiscoveryRouter::class)]
final class DiscoveryTerminalBindingTest extends TestCase
{
    public function testIndexPreservesExposedLinksAndReusesFinalizedKernelHandler(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $manager->registerEntityType(new EntityType('article', 'Article', TestEntity::class, api: true));
        $manager->registerEntityType(new EntityType('hidden', 'Hidden', TestEntity::class, api: false));
        $policy = EntityTypeApiExposurePolicy::fromConfig($manager, []);
        $handler = new DiscoveryApiHandler($manager, $this->createStub(DatabaseInterface::class));
        foreach ([false, true] as $authenticated) {
            [$provider, $bus] = $this->provider($manager);
            $kernel = new HttpKernel(sys_get_temp_dir());
            new \ReflectionProperty(HttpKernel::class, 'discoveryHandler')->setValue($kernel, $handler);
            $provider->configureHttpKernel($kernel);
            self::assertSame([], $bus->reads);
            $principal = new AuthorizationPrincipal($authenticated ? 7 : 0, $authenticated, [], [], 'fixture');
            $request = new Request(attributes: ['_account' => $principal, '_authorization_principal' => $principal, '_broadcast_storage' => new \ReflectionClass(BroadcastStorage::class)->newInstanceWithoutConstructor(), '_controller' => ApiDiscoveryController::class . '::discover']);
            $expected = new ControllerDispatcher([new DiscoveryRouter($handler, $manager, $policy)])->dispatch($request);
            $definition = $this->definition();
            $request->attributes->set('_route', $definition->name);
            $request->attributes->set('_controller', $definition->handler->id);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has(DiscoveryRouter::class));
            self::assertSame([], $bus->reads);
            $resolved = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $resolved);
            $actual = new ControllerDispatcher([])->dispatch($request);
            self::assertSame(200, $actual->getStatusCode());
            self::assertSame($expected->getContent(), $actual->getContent());
            self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
            $payload = json_decode($actual->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($authenticated ? ['self', 'article'] : ['self'], array_keys($payload['links']));
            $router = $provider->resolve(DiscoveryRouter::class);
            self::assertSame($handler, new \ReflectionProperty(DiscoveryRouter::class, 'discoveryHandler')->getValue($router));
            self::assertNotSame($router, $provider->resolve(DiscoveryRouter::class));
        }
    }

    public function testUnfinalizedHttpHandlerRefusesSelection(): void
    {
        [$provider, $bus] = $this->provider(new EntityTypeManager(new EventDispatcher()));
        $definition = $this->definition();
        $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has(DiscoveryRouter::class));
        self::assertSame([], $bus->reads);
        try {
            new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::fail('Missing HTTP finalization must refuse.');
        } catch (HandlerResolutionException $error) {
            self::assertStringContainsString('resolution-failed', $error->getMessage());
        }
    }

    public function testUnhealthyManagerRefusesAfterFinalization(): void
    {
        $manager = new EntityTypeManager(new EventDispatcher());
        $handler = new DiscoveryApiHandler($manager, $this->createStub(DatabaseInterface::class));
        foreach ([null, new \stdClass(), new \RuntimeException('private manager detail')] as $binding) {
            [$provider, $bus] = $this->provider($binding);
            $kernel = new HttpKernel(sys_get_temp_dir());
            new \ReflectionProperty(HttpKernel::class, 'discoveryHandler')->setValue($kernel, $handler);
            $provider->configureHttpKernel($kernel);
            $definition = $this->definition();
            $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertSame([], $bus->reads);
            try {
                new RouteHandlerResolver($definition, $services)->resolveMatched();
                self::fail('An unhealthy manager must refuse.');
            } catch (HandlerResolutionException $error) {
                self::assertStringContainsString('resolution-failed', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    private function definition(): RouteDefinition
    {
        return new RouteDefinition('fixture.discovery', '/api', HandlerReference::fromString('class:' . DiscoveryRouter::class . '::discover'), sourceId: 'fixture.api', ordinal: 0);
    }

    private function provider(?object $manager): array
    {
        $bus = new class ($manager) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private ?object $manager) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                if ($this->manager instanceof \Throwable) {
                    throw $this->manager;
                }
                return $abstract === EntityTypeManager::class ? $this->manager : null;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }
}
