<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\QueueController;
use Waaseyaa\Api\Http\Router\QueueAdminApiRouter;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Queue\FailedJobRepositoryInterface;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;

#[CoversClass(QueueAdminApiRouter::class)]
#[CoversClass(ApiServiceProvider::class)]
final class QueueAdminApiRouterTest extends TestCase
{
    public function testExplicitActionsPreserveLegacyRequestAndResponseAdaptation(): void
    {
        foreach ([['index', null], ['retry', '42'], ['discard', 42], ['retry', ['invalid']], ['discard', null], ['retry', 'known'], ['discard', 'known']] as [$action, $id]) {
            $repo = $this->createMock(FailedJobRepositoryInterface::class);
            $repo->method('all')->willReturn([]);
            $repo->expects($action === 'index' ? self::never() : self::exactly(2))
                ->method('find')->with(is_scalar($id) ? (string) $id : '')->willReturn($id === 'known' ? [
                    'id' => 'known', 'queue' => 'default', 'payload' => serialize(new \stdClass()),
                    'exception' => '', 'failed_at' => '2026-10-03T00:00:00Z',
                ] : null);
            $repo->method('claimForRetry')->willReturn(true);
            $repo->expects($id === 'known' ? self::exactly(2) : self::never())->method('forget')->with('known');
            $queue = $this->createMock(QueueInterface::class);
            $queue->expects($action === 'retry' && $id === 'known' ? self::exactly(2) : self::never())->method('dispatch');
            $bus = new class ($repo, $queue) implements KernelServicesInterface {
                public array $reads = [];
                public function __construct(private FailedJobRepositoryInterface $repo, private QueueInterface $queue) {}
                public function get(string $abstract): ?object
                {
                    $this->reads[] = $abstract;
                    return match ($abstract) {
                        FailedJobRepositoryInterface::class => $this->repo,
                        QueueInterface::class => $this->queue,
                        default => null,
                    };
                }
            };
            $provider = new ApiServiceProvider();
            $provider->setKernelServices($bus);
            $provider->register();
            $bus->reads = [];
            $request = Request::create('/api/queue/jobs?page=2&per_page=3');
            if ($id !== null) {
                $request->attributes->set('id', $id);
            }
            $request->attributes->set('_controller', QueueController::class . '::' . $action);
            $legacy = new ControllerDispatcher([new QueueAdminApiRouter(new QueueController($repo, $queue))])->dispatch($request);
            $definition = new RouteDefinition('fixture.queue', '/api/queue/jobs', HandlerReference::fromString('class:' . QueueAdminApiRouter::class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
            $request->attributes->set('_route', $definition->name);
            $request->attributes->set('_controller', $definition->handler->id);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has(QueueAdminApiRouter::class));
            self::assertSame([], $bus->reads);
            $handler = new RouteHandlerResolver($definition, $services)->resolveMatched();
            $request->attributes->set('_controller', $handler);
            $actual = new ControllerDispatcher([])->dispatch($request);
            self::assertSame($action === 'index' ? 200 : ($id === 'known' ? 204 : 404), $actual->getStatusCode());
            self::assertSame($legacy->getStatusCode(), $actual->getStatusCode());
            self::assertSame($legacy->getContent(), $actual->getContent());
            self::assertSame($legacy->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
            self::assertSame($handler, $request->attributes->get('_controller'));
            self::assertSame([FailedJobRepositoryInterface::class, QueueInterface::class, \Waaseyaa\Queue\Transport\TransportInterface::class], $bus->reads);
            self::assertNotSame($provider->resolve(QueueAdminApiRouter::class), $provider->resolve(QueueAdminApiRouter::class));
        }
    }

    public function testSelectedRequiredDependencyFailureRefusesInsteadOfFallingThrough(): void
    {
        $provider = new ApiServiceProvider();
        $provider->setKernelServices(new class implements KernelServicesInterface {
            public function get(string $abstract): ?object
            {
                if ($abstract === FailedJobRepositoryInterface::class) {
                    throw new \RuntimeException('private queue backend failure');
                }
                return null;
            }
        });
        $provider->register();
        $definition = new RouteDefinition('fixture.queue', '/api/queue/jobs', HandlerReference::fromString('class:' . QueueAdminApiRouter::class . '::index'), sourceId: 'fixture.api', ordinal: 0);
        $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
        $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
        self::assertTrue($services->has(QueueAdminApiRouter::class));
        $this->expectException(HandlerResolutionException::class);
        $this->expectExceptionMessage('resolution-failed');
        new RouteHandlerResolver($definition, $services)->resolveMatched();
    }
}
