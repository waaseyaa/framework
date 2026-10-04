<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Audit\AuditEventResource;
use Waaseyaa\Api\Audit\AuditQueryDto;
use Waaseyaa\Api\Audit\AuditQueryReadModelInterface;
use Waaseyaa\Api\Controller\AuditQueryController;
use Waaseyaa\Api\Http\Router\AuditApiRouter;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(AuditApiRouter::class)]
final class AuditTerminalBindingTest extends TestCase
{
    public function testPopulatedAndAbsentModelsPreserveResponseAndQueryAuthority(): void
    {
        $model = $this->createMock(AuditQueryReadModelInterface::class);
        $query = new AuditQueryDto(accountUid: 7, entityType: 'node', entityUuid: 'uuid:part', kinds: ['entity.read', 'entity.write'], from: new \DateTimeImmutable('2026-10-01T00:00:00Z'), limit: 500, offset: 0);
        $row = new AuditEventResource(1, 'audit-uuid', 'entity.read', 7, 'node', 'uuid:part', 'node:uuid:part', 'allowed', 'info', ['source' => 'fixture'], '2026-10-01T00:00:00Z');
        $model->expects(self::exactly(2))->method('count')->with(self::equalTo($query))->willReturn(1);
        $model->expects(self::exactly(2))->method('findBy')->with(self::equalTo($query))->willReturn([$row]);
        foreach ([$model, null] as $binding) {
            [$provider, $bus] = $this->provider($binding);
            $request = new Request(query: ['page' => ['limit' => '999', 'offset' => '-5'], 'filter' => ['account' => '7', 'entity' => 'node:uuid:part', 'kind' => 'entity.read, , entity.write', 'from' => '2026-10-01T00:00:00Z', 'to' => 'invalid']]);
            $request->attributes->set('_controller', AuditQueryController::class . '::index');
            $expected = new ControllerDispatcher([new AuditApiRouter(new AuditQueryController($binding))])->dispatch($request);
            [$definition, $services] = $this->selection($provider, $request);
            self::assertTrue($services->has(AuditApiRouter::class));
            self::assertSame([], $bus->reads);
            $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $handler);
            $actual = new ControllerDispatcher([])->dispatch($request);
            self::assertSame(200, $actual->getStatusCode());
            self::assertSame($expected->getContent(), $actual->getContent());
            self::assertSame('application/vnd.api+json', $actual->headers->get('Content-Type'));
            self::assertSame($expected->headers->get('Cache-Control'), $actual->headers->get('Cache-Control'));
            self::assertSame($handler, $request->attributes->get('_controller'));
            $payload = json_decode($actual->getContent(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($binding === null ? ['total' => 0, 'limit' => 50, 'offset' => 0] : ['total' => 1, 'limit' => 500, 'offset' => 0], $payload['meta']);
            self::assertSame($binding === null ? [] : [$row->toArray()], $payload['data']);
            self::assertNotSame($provider->resolve(AuditApiRouter::class), $provider->resolve(AuditApiRouter::class));
        }
    }

    public function testInvalidAndThrownBindingsRefuseWithoutLeakingDetails(): void
    {
        foreach ([new \stdClass(), new \RuntimeException('private backend detail')] as $binding) {
            [$provider, $bus] = $this->provider($binding);
            [$definition, $services] = $this->selection($provider, new Request());
            self::assertTrue($services->has(AuditApiRouter::class));
            self::assertSame([], $bus->reads);
            $this->assertRefusal($definition, $services);
        }
    }

    public function testDeclaredAdapterWithMissingBackendRefusesRatherThanReturningEmptySuccess(): void
    {
        $provider = new ApiServiceProvider();
        $provider->register();
        $bus = new class ($provider) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private ApiServiceProvider $provider) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                return $abstract === AuditQueryReadModelInterface::class ? $this->provider->resolve($abstract) : null;
            }
        };
        $provider->setKernelServices($bus);
        [$definition, $services] = $this->selection($provider, new Request());
        self::assertTrue($services->has(AuditApiRouter::class));
        self::assertSame([], $bus->reads);
        $this->assertRefusal($definition, $services);
        self::assertSame([AuditQueryReadModelInterface::class, 'Waaseyaa\\Audit\\Contract\\AuditQueryInterface'], $bus->reads);
    }

    private function provider(?object $binding): array
    {
        $bus = new class ($binding) implements KernelServicesInterface {
            public array $reads = [];
            public function __construct(private ?object $binding) {}
            public function get(string $abstract): ?object
            {
                $this->reads[] = $abstract;
                if ($abstract !== AuditQueryReadModelInterface::class) {
                    return null;
                }
                if ($this->binding instanceof \Throwable) {
                    throw $this->binding;
                }
                return $this->binding;
            }
        };
        $provider = new ApiServiceProvider();
        $provider->setKernelServices($bus);
        $provider->register();
        $bus->reads = [];
        return [$provider, $bus];
    }

    private function selection(ApiServiceProvider $provider, Request $request): array
    {
        $definition = new RouteDefinition('fixture.audit', '/api/audit/events', HandlerReference::fromString('class:' . AuditApiRouter::class . '::index'), sourceId: 'fixture.api', ordinal: 0);
        $request->attributes->set('_route', $definition->name);
        $request->attributes->set('_controller', $definition->handler->id);
        return [$definition, new KernelHandlerContainer([$provider], [])->explicitServices($request)];
    }

    private function assertRefusal(RouteDefinition $definition, \Psr\Container\ContainerInterface $services): void
    {
        try {
            new RouteHandlerResolver($definition, $services)->resolveMatched();
            self::fail('An unhealthy declared audit binding must refuse.');
        } catch (HandlerResolutionException $error) {
            self::assertStringContainsString('resolution-failed', $error->getMessage());
            self::assertStringNotContainsString('private', $error->getMessage());
        }
    }
}
