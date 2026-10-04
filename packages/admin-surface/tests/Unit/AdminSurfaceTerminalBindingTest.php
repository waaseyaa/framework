<?php

declare(strict_types=1);

namespace Waaseyaa\AdminSurface\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceHostFactoryInterface;
use Waaseyaa\AdminSurface\Http\AdminSurfaceHttpController;
use Waaseyaa\AdminSurface\Http\PageBuilderHttpController;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface;
use Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceRequest;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\RouteContributionContext;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(AdminSurfaceServiceProvider::class)]
#[CoversClass(AdminSurfaceHttpController::class)]
#[CoversClass(PageBuilderHttpController::class)]
final class AdminSurfaceTerminalBindingTest extends TestCase
{
    public function testSelectedCoreActionsPreserveWireStatusAndMatchedParameterAuthority(): void
    {
        foreach ([
            [['ok' => true, 'data' => ['fixture' => 'success']], 200],
            [['ok' => false, 'error' => ['status' => 403, 'title' => 'Denied']], 403],
            [['ok' => false, 'error' => ['status' => '403']], 200],
            [['ok' => false, 'error' => ['status' => 600]], 200],
            [['ok' => true, 'error' => ['status' => 403]], 200],
        ] as [$envelope, $status]) {
            foreach (['session' => [], 'catalog' => [], 'list' => ['type' => 'article'], 'get' => ['type' => 'article', 'id' => '1'], 'action' => ['type' => 'article', 'action' => 'update']] as $action => $params) {
                $request = Request::create('/fixture', $action === 'action' ? 'POST' : 'GET');
                $request->attributes->add($params);
                $host = $this->createMock(AbstractAdminSurfaceHost::class);
                $host->expects(self::exactly(2))->method('handle' . ucfirst($action))->with($request, ...array_values($params))->willReturn($envelope);
                $factory = new class ($host) implements AdminSurfaceHostFactoryInterface {
                    public int $calls = 0;
                    public function __construct(private AbstractAdminSurfaceHost $host) {}
                    public function createAdminSurfaceHost(): AbstractAdminSurfaceHost
                    {
                        $this->calls++;
                        return $this->host;
                    }
                };
                [$provider, $bus] = $this->provider([AdminSurfaceHostFactoryInterface::class => $factory]);
                $callable = $this->select($provider, $bus, $request, 'admin_surface.' . $action);
                self::assertSame(1, $factory->calls);
                $response = new ControllerDispatcher([])->dispatch($request);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('application/json', $response->headers->get('Content-Type'));
                self::assertSame(json_encode($envelope, JSON_THROW_ON_ERROR), $response->getContent());
                $conflicting = array_fill_keys(array_keys($params), 'conflicting');
                self::assertSame($response->getContent(), $callable($request, ...$conflicting)->getContent());
                self::assertNotSame($provider->resolve(AdminSurfaceHttpController::class), $provider->resolve(AdminSurfaceHttpController::class));
                self::assertSame(3, $factory->calls);
            }
        }
    }

    public function testSelectedPageBuilderActionsPreservePrincipalBodyAndTransportRules(): void
    {
        foreach ([
            [['ok' => true, 'data' => []], 200, null],
            [['ok' => false, 'error' => ['status' => 428]], 428, null],
            [['ok' => false, 'error' => ['status' => '403']], 200, null],
            [['ok' => false, 'error' => ['status' => 600]], 200, null],
            [['ok' => false, 'error' => ['status' => 403], 'statusCode' => 409, 'body' => ['fixture' => 'owned']], 409, ['fixture' => 'owned']],
        ] as [$envelope, $status, $body]) {
            foreach (['definitions' => ['surface' => 'pages'], 'command' => ['surface' => 'pages', 'id' => '1'], 'preview' => ['surface' => 'pages', 'id' => '1'], 'history' => ['surface' => 'pages', 'id' => '1'], 'revision' => ['surface' => 'pages', 'id' => '1', 'revision' => '2'], 'restore' => ['surface' => 'pages', 'id' => '1'], 'draft' => ['surface' => 'pages', 'id' => '1']] as $action => $params) {
                $request = Request::create('/fixture', in_array($action, ['command', 'preview', 'restore'], true) ? 'POST' : 'GET', content: '{"fixture":"body"}');
                $principal = new AuthorizationPrincipal(7, true, [], [], 'fixture');
                $request->attributes->add($params + ['_authorization_principal' => $principal]);
                $host = $this->createMock(PageBuilderSurfaceHostInterface::class);
                $host->expects(self::exactly(2))->method('handle' . ucfirst($action))->with(self::callback(static fn(PageBuilderSurfaceRequest $input): bool => $input->actor === $principal && $input->content === '{"fixture":"body"}'), ...array_values($params))->willReturn($envelope);
                [$provider, $bus] = $this->provider([PageBuilderSurfaceHostInterface::class => $host]);
                $callable = $this->select($provider, $bus, $request, 'admin_surface.page_builder.' . $action);
                $response = new ControllerDispatcher([])->dispatch($request);
                self::assertSame($status, $response->getStatusCode());
                self::assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
                self::assertSame(json_encode($body ?? $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $response->getContent());
                $result = $callable($request, ...array_fill_keys(array_keys($params), 'conflicting'));
                self::assertSame($body ?? $envelope, $result['body'] ?? $result);
                self::assertNotSame($provider->resolve(PageBuilderHttpController::class), $provider->resolve(PageBuilderHttpController::class));
            }
        }
    }

    public function testUnhealthyDeclaredHostsRefuseSelectionWithoutFallback(): void
    {
        foreach ([AdminSurfaceHostFactoryInterface::class => 'admin_surface.session', PageBuilderSurfaceHostInterface::class => 'admin_surface.page_builder.definitions'] as $service => $name) {
            foreach ([new \stdClass(), new \RuntimeException('private host failure')] as $value) {
                [$provider, $bus] = $this->provider([$service => $value]);
                try {
                    $this->select($provider, $bus, Request::create('/fixture'), $name);
                    self::fail('An unhealthy declared host must refuse.');
                } catch (HandlerResolutionException $error) {
                    self::assertSame('resolution-failed', $error->reason);
                    self::assertStringNotContainsString('private host failure', $error->getMessage());
                }
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
        $provider = new AdminSurfaceServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        return [$provider, $bus];
    }

    private function select(AdminSurfaceServiceProvider $provider, object $bus, Request $request, string $name): \Closure
    {
        $context = new RouteContributionContext(AdminSurfaceServiceProvider::class, 0, capabilities: ['service:Waaseyaa\AdminSurface\PageBuilder\PageBuilderSurfaceHostInterface' => true]);
        $definitions = array_column(iterator_to_array($provider->routeDefinitions($context)), null, 'name');
        $definition = $definitions[$name];
        self::assertSame([], $bus->reads);
        $request->attributes->add(['_route' => $name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has($definition->handler->target));
        self::assertSame([], $bus->reads);
        $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
        $request->attributes->set('_controller', $callable);
        return $callable;
    }
}
