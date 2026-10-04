<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\McpAdminController;
use Waaseyaa\Api\Controller\McpApprovalController;
use Waaseyaa\Api\Http\Router\McpAdminApiRouter;
use Waaseyaa\Api\Http\Router\McpApprovalApiRouter;
use Waaseyaa\Api\McpAdmin\ServerConfigReadModelInterface;
use Waaseyaa\Api\McpAdmin\ServerConfigSnapshot;
use Waaseyaa\Api\McpAdmin\ToolDetail;
use Waaseyaa\Api\McpAdmin\ToolRegistryReadModelInterface;
use Waaseyaa\Api\McpAdmin\ToolRegistryRow;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequestPage;
use Waaseyaa\Foundation\Audit\Approval\ApprovalRequest;
use Waaseyaa\Foundation\Audit\Approval\ApprovalStatus;
use Waaseyaa\Foundation\Audit\Approval\ApprovalTuple;
use Waaseyaa\Foundation\Audit\Approval\OperationApprovalStoreInterface;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(McpAdminApiRouter::class)]
#[CoversClass(McpApprovalApiRouter::class)]
final class McpTerminalBindingsTest extends TestCase
{
    public function testAdminPopulatedAndMissingModelsPreserveResponsesAndDecodedNames(): void
    {
        $registry = $this->createMock(ToolRegistryReadModelInterface::class);
        $registry->expects(self::exactly(2))->method('listTools')->willReturn([new ToolRegistryRow('tool.read', 'Read', 'query', ['read'])]);
        $registry->expects(self::exactly(2))->method('findTool')->with('tool.read')->willReturn(new ToolDetail('tool.read', 'Read', 'Read content', 'query', ['read'], ['type' => 'object'], []));
        $config = $this->createMock(ServerConfigReadModelInterface::class);
        $config->expects(self::exactly(2))->method('serverConfig')->willReturn(new ServerConfigSnapshot('streamable-http', '2025-03-26', [], ['tools']));
        foreach ([[$registry, $config], [null, null]] as [$r, $c]) {
            foreach (['tools', 'tool', 'serverConfig'] as $action) {
                [$provider, $bus] = $this->provider([ToolRegistryReadModelInterface::class => $r, ServerConfigReadModelInterface::class => $c]);
                $this->parity($provider, $bus, new McpAdminApiRouter(new McpAdminController($r, $c)), McpAdminController::class, $action, new Request(attributes: $action === 'tool' ? ['name' => 'tool%2Eread', '_route_params' => ['name' => 'tool%2Eread']] : []), 200);
            }
        }
    }

    public function testApprovalStoreStaysLazyAndFailureKeeps503Boundary(): void
    {
        $store = $this->createMock(OperationApprovalStoreInterface::class);
        $store->expects(self::exactly(2))->method('listPending')->with(12, 'cursor')->willReturn(new ApprovalRequestPage([]));
        foreach ([$store, null, new \stdClass(), new \RuntimeException('private database detail')] as $binding) {
            [$provider, $bus] = $this->provider([OperationApprovalStoreInterface::class => $binding]);
            $legacy = new McpApprovalApiRouter(new McpApprovalController(static function () use ($binding) {
                if ($binding instanceof \Throwable) {
                    throw $binding;
                }
                return $binding;
            }));
            foreach ([
                ['index', new Request(query: ['limit' => '0']), 400, false],
                ['decide', new Request(attributes: ['id' => 'apr_0123456789abcdef0123456789abcdef', '_route_params' => ['id' => 'apr_0123456789abcdef0123456789abcdef']]), 403, false],
                ['index', new Request(query: ['limit' => '12', 'cursor' => 'cursor']), $binding === $store ? 200 : 503, true],
            ] as [$action, $request, $status, $readsStore]) {
                $bus->reads = [];
                $response = $this->parity($provider, $bus, $legacy, McpApprovalController::class, $action, $request, $status);
                self::assertSame($readsStore, in_array(OperationApprovalStoreInterface::class, $bus->reads, true));
                self::assertStringNotContainsString('private', $response->getContent());
            }
        }
    }

    public function testAdminUnhealthyBindingsRefuseSelectedConstruction(): void
    {
        foreach ([ToolRegistryReadModelInterface::class, ServerConfigReadModelInterface::class] as $key) {
            foreach ([new \stdClass(), new \RuntimeException('private read-model detail')] as $binding) {
                [$provider, $bus] = $this->provider([$key => $binding]);
                $definition = $this->definition(McpAdminApiRouter::class, 'tools');
                $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
                $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
                self::assertTrue($services->has(McpAdminApiRouter::class));
                self::assertSame([], $bus->reads);
                try {
                    new RouteHandlerResolver($definition, $services)->resolveMatched();
                    self::fail('Unhealthy declared model must refuse.');
                } catch (HandlerResolutionException $error) {
                    self::assertStringContainsString('resolution-failed', $error->getMessage());
                    self::assertStringNotContainsString('private', $error->getMessage());
                }
            }
        }
    }

    public function testApprovalDecisionPreservesOriginOperatorAndSelfApprovalConfiguration(): void
    {
        $id = 'apr_0123456789abcdef0123456789abcdef';
        $now = new \DateTimeImmutable('2026-10-03T00:00:00Z');
        $pending = new ApprovalRequest($id, new ApprovalTuple('42', 'mcp.write', 'save', str_repeat('a', 64)), ApprovalStatus::Pending, 'fixture', [], $now, $now->modify('+15 minutes'));
        $decided = new ApprovalRequest($id, $pending->tuple, ApprovalStatus::Approved, 'fixture', [], $now, $pending->expiresAt, 42, $now->modify('+1 minute'));
        foreach ([false, true] as $allowSelf) {
            $store = $this->createMock(OperationApprovalStoreInterface::class);
            $store->expects(self::exactly(3))->method('find')->with($id)->willReturn($pending);
            $store->expects($allowSelf ? self::exactly(3) : self::never())->method('decide')->with($id, true, 42, null)->willReturn($decided);
            [$provider, $bus] = $this->provider([OperationApprovalStoreInterface::class => $store]);
            $provider->setKernelContext(sys_get_temp_dir(), ['cors_origins' => ['https://operator.test'], 'mcp' => ['write_tier' => ['approval' => ['allow_self_approval' => $allowSelf]]]], []);
            $request = Request::create('https://app.test/api/mcp/approvals/' . $id . '/decision', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"decision":"approve"}');
            $request->headers->set('Origin', 'https://operator.test');
            $request->attributes->set('_authorization_principal', new AuthorizationPrincipal(42, true, ['admin'], ['mcp.approval.decide'], 'fixture'));
            $request->attributes->set('id', $id);
            // A conflicting callable argument must not replace matched-request authority.
            $request->attributes->set('_route_params', ['id' => 'different-id']);
            $legacy = new McpApprovalApiRouter(new McpApprovalController(static fn() => $store, ['https://operator.test'], $allowSelf));
            $this->parity($provider, $bus, $legacy, McpApprovalController::class, 'decide', $request, $allowSelf ? 204 : 403);
            $definition = $this->definition(McpApprovalApiRouter::class, 'decide');
            $request->attributes->set('_controller', $definition->handler->id);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            $callable = new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::assertSame($allowSelf ? 204 : 403, $callable($request, id: 'different-id')->getStatusCode());
        }
    }

    public function testNonBooleanSelfApprovalConfigurationRefusesBeforeStoreResolution(): void
    {
        [$provider, $bus] = $this->provider([]);
        $provider->setKernelContext(sys_get_temp_dir(), ['mcp' => ['write_tier' => ['approval' => ['allow_self_approval' => 'true']]]], []);
        $definition = $this->definition(McpApprovalApiRouter::class, 'index');
        $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has(McpApprovalApiRouter::class));
        self::assertSame([], $bus->reads);
        try {
            new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::fail('Self-approval configuration must remain strict boolean.');
        } catch (HandlerResolutionException $error) {
            self::assertStringContainsString('resolution-failed', $error->getMessage());
        }
        self::assertNotContains(OperationApprovalStoreInterface::class, $bus->reads);
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

    private function definition(string $router, string $action): RouteDefinition
    {
        return new RouteDefinition('fixture.mcp', '/fixture', HandlerReference::fromString('class:' . $router . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
    }

    private function parity(ApiServiceProvider $provider, KernelServicesInterface $bus, DomainRouterInterface $legacy, string $controller, string $action, Request $request, int $status): Response
    {
        $request->attributes->set('_controller', $controller . '::' . $action);
        $expected = new ControllerDispatcher([$legacy])->dispatch($request);
        $definition = $this->definition($legacy::class, $action);
        $request->attributes->set('_route', $definition->name);
        $request->attributes->set('_controller', $definition->handler->id);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has($legacy::class));
        self::assertSame([], $bus->reads);
        $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
        self::assertNotContains(OperationApprovalStoreInterface::class, $bus->reads);
        $request->attributes->set('_controller', $handler);
        $actual = new ControllerDispatcher([])->dispatch($request);
        self::assertSame($status, $actual->getStatusCode());
        self::assertSame($status, $expected->getStatusCode());
        self::assertSame($expected->getContent(), $actual->getContent());
        self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
        self::assertSame($expected->headers->get('Cache-Control'), $actual->headers->get('Cache-Control'));
        self::assertSame($handler, $request->attributes->get('_controller'));
        self::assertNotSame($provider->resolve($legacy::class), $provider->resolve($legacy::class));
        return $actual;
    }
}
