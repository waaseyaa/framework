<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\MediaVersionController;
use Waaseyaa\Api\Controller\MercureMonitorController;
use Waaseyaa\Api\Http\Router\MediaVersionApiRouter;
use Waaseyaa\Api\Http\Router\MercureMonitorApiRouter;
use Waaseyaa\Api\Media\MediaVersionReadModelInterface;
use Waaseyaa\Api\Media\MediaVersionResource;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorInterface;
use Waaseyaa\Api\MercureMonitor\ChannelInspectorRow;
use Waaseyaa\Api\MercureMonitor\EventStreamReadModelInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(MediaVersionApiRouter::class)]
#[CoversClass(MercureMonitorApiRouter::class)]
final class ReadTerminalBindingsTest extends TestCase
{
    public function testMediaExplicitTerminalsPreserveAccountStatusAndParameterAdaptation(): void
    {
        $account = new AuthorizationPrincipal(7, true, ['administrator'], [], 'fixture');
        $resource = new MediaVersionResource(1, 'uuid', 'cas://blob', 'image/jpeg', 12, str_repeat('a', 64), 123, 7);
        $model = $this->createMock(MediaVersionReadModelInterface::class);
        $model->expects(self::exactly(2))->method('findForMedia')->willReturnCallback(static function (string $uuid, AccountInterface $seen) use ($account, $resource): iterable {
            self::assertSame('uuid', $uuid);
            self::assertSame($account, $seen);
            return [$resource];
        });
        $model->expects(self::exactly(6))->method('findByVid')->willReturnCallback(static function (string $uuid, int $vid, AccountInterface $seen) use ($account, $resource): ?MediaVersionResource {
            self::assertSame($account, $seen);
            self::assertSame($vid === 0 ? '' : 'uuid', $uuid);
            return $vid === 1 ? $resource : null;
        });
        $model->expects(self::exactly(4))->method('existsByVid')->willReturnCallback(static fn(string $uuid, int $vid): bool => $vid === 2);
        foreach ([$model, null] as $readModel) {
            foreach ([['index', 'uuid', null, 200], ['show', 'uuid', '1', $readModel === null ? 404 : 200], ['show', 'uuid', 2, $readModel === null ? 404 : 403], ['show', ['invalid'], ['invalid'], 404]] as [$action, $uuid, $vid, $status]) {
                [$provider, $bus] = $this->provider($readModel === null ? [] : [MediaVersionReadModelInterface::class => $readModel]);
                $request = new Request(attributes: ['_account' => $account, 'uuid' => $uuid]);
                if ($action === 'show') {
                    $request->attributes->set('vid', $vid);
                }
                $legacy = new MediaVersionApiRouter(new MediaVersionController($readModel));
                $this->assertParity($provider, $bus, $legacy, MediaVersionController::class, $action, $request, $status);
            }
        }
    }

    public function testMonitorExplicitTerminalsPreserveJsonAndDisabledStream(): void
    {
        $inspector = $this->createMock(ChannelInspectorInterface::class);
        $inspector->expects(self::exactly(2))->method('listChannels')->willReturn([new ChannelInspectorRow('admin', 2, 123.0, 'saved')]);
        foreach (['channels', 'subscribers', 'events'] as $action) {
            [$provider, $bus] = $this->provider([ChannelInspectorInterface::class => $inspector]);
            $legacy = new MercureMonitorApiRouter(new MercureMonitorController($inspector));
            $this->assertParity($provider, $bus, $legacy, MercureMonitorController::class, $action, new Request(), 200);
        }
    }

    public function testThrownWrongTypedAndUnavailableBindingsRefuseSelectedConstruction(): void
    {
        foreach ([
            [MediaVersionApiRouter::class, MediaVersionReadModelInterface::class, new \RuntimeException('private backend detail')],
            [MediaVersionApiRouter::class, MediaVersionReadModelInterface::class, new \stdClass()],
            [MercureMonitorApiRouter::class, ChannelInspectorInterface::class, new \RuntimeException('private monitor detail')],
            [MercureMonitorApiRouter::class, EventStreamReadModelInterface::class, new \stdClass()],
            [MercureMonitorApiRouter::class, '', null],
        ] as [$router, $key, $binding]) {
            [$provider, $bus] = $this->provider($key === '' ? [] : [$key => $binding]);
            $method = $router === MediaVersionApiRouter::class ? 'index' : 'channels';
            $definition = new RouteDefinition('fixture.read', '/fixture', HandlerReference::fromString('class:' . $router . '::' . $method), sourceId: 'fixture.api', ordinal: 0);
            $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has($router));
            self::assertSame([], $bus->reads);
            try {
                new RouteHandlerResolver($definition, $services)->resolveMatched();
                self::fail('Selected unhealthy/unavailable binding must refuse.');
            } catch (HandlerResolutionException $error) {
                self::assertStringContainsString('resolution-failed', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    private function provider(array $bindings): array
    {
        $bus = new class ($bindings) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private array $bindings) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                $value = $this->bindings[$abstract] ?? null;
                if ($value instanceof \Throwable) {
                    throw $value;
                }
                return $value;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }

    private function assertParity(ApiServiceProvider $provider, KernelServicesInterface $bus, DomainRouterInterface $legacy, string $controller, string $action, Request $request, int $status): void
    {
        $request->attributes->set('_controller', $controller . '::' . $action);
        $expected = new ControllerDispatcher([$legacy])->dispatch($request);
        $definition = new RouteDefinition('fixture.read', '/fixture', HandlerReference::fromString('class:' . $legacy::class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
        $request->attributes->set('_route', $definition->name);
        $request->attributes->set('_controller', $definition->handler->id);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has($legacy::class));
        self::assertSame([], $bus->reads);
        $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
        $request->attributes->set('_controller', $handler);
        $actual = new ControllerDispatcher([])->dispatch($request);
        self::assertSame($status, $expected->getStatusCode());
        self::assertSame($status, $actual->getStatusCode());
        self::assertSame($expected->getContent(), $actual->getContent());
        foreach (['Content-Type', 'Cache-Control', 'X-Accel-Buffering'] as $header) {
            self::assertSame($expected->headers->get($header), $actual->headers->get($header));
        }
        self::assertSame($handler, $request->attributes->get('_controller'));
        self::assertNotSame($provider->resolve($legacy::class), $provider->resolve($legacy::class));
        if ($actual instanceof StreamedResponse && $expected instanceof StreamedResponse) {
            self::assertSame("event: disabled\ndata: {}\n\n", $this->streamBytes($actual));
            self::assertSame($this->streamBytes($expected), $this->streamBytes($actual));
        }
    }

    private function streamBytes(StreamedResponse $response): string
    {
        $previous = ignore_user_abort();
        $level = ob_get_level();
        ob_start();
        ob_start();
        try {
            ($response->getCallback())();
            ob_end_flush();
            return ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            ignore_user_abort((bool) $previous);
        }
    }
}
