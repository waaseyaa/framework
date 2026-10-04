<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Api\ApiServiceProvider;
use Waaseyaa\Api\Controller\NotificationController;
use Waaseyaa\Api\Controller\SchedulerController;
use Waaseyaa\Api\Http\Router\NotificationAdminApiRouter;
use Waaseyaa\Api\Http\Router\SchedulerAdminApiRouter;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\Http\Router\DomainRouterInterface;
use Waaseyaa\Foundation\Kernel\KernelHandlerContainer;
use Waaseyaa\Foundation\Routing\Metadata\HandlerReference;
use Waaseyaa\Foundation\Routing\Metadata\RouteDefinition;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Notification\ChannelInterface;
use Waaseyaa\Notification\NotificationDispatcher;
use Waaseyaa\Queue\SyncQueue;
use Waaseyaa\Routing\Exception\HandlerResolutionException;
use Waaseyaa\Routing\RouteHandlerResolver;
use Waaseyaa\Scheduler\Execution\LeaseAwareClosureCommand;
use Waaseyaa\Scheduler\Execution\LeaseExecutionContext;
use Waaseyaa\Scheduler\Schedule;
use Waaseyaa\Scheduler\ScheduledTask;
use Waaseyaa\Scheduler\ScheduleInterface;
use Waaseyaa\Scheduler\ScheduleRunner;
use Waaseyaa\Scheduler\Storage\ScheduleStateRepository;
use Waaseyaa\Scheduler\Testing\InMemoryFenceGuard;
use Waaseyaa\Scheduler\Testing\InMemoryLeaseAuthority;
use Waaseyaa\Scheduler\Testing\InMemoryOccurrenceRepository;
use Waaseyaa\Tests\Support\UserInternalFieldReaderFixture;

#[CoversClass(ApiServiceProvider::class)]
#[CoversClass(SchedulerAdminApiRouter::class)]
#[CoversClass(NotificationAdminApiRouter::class)]
final class AdminTerminalBindingsTest extends TestCase
{
    public function testSchedulerTerminalsPreserveNamesIdempotencyAndExecution(): void
    {
        foreach ([['index', null, null, 200], ['trigger', 'missing', 'key', 404], ['trigger', 'known', null, 428], ['trigger', 'known', 'key', 200], ['trigger', ['invalid'], 'key', 404], ['trigger', null, 'key', 404], ['trigger', 'known', str_repeat('x', 256), 400]] as [$action, $name, $key, $status]) {
            [$legacyBindings, $legacyEffects] = $this->schedulerFixture($action !== 'index');
            [$bindings, $effects] = $this->schedulerFixture($action !== 'index');
            [$provider, $bus] = $this->provider($bindings);
            $legacy = new SchedulerAdminApiRouter(new SchedulerController(...array_values($legacyBindings)));
            $request = new Request();
            if ($name !== null) {
                $request->attributes->set('name', $name);
            }
            if ($key !== null) {
                $request->headers->set('Idempotency-Key', $key);
            }
            $this->assertParity($provider, $bus, $legacy, SchedulerController::class, $action, $request, $status);
            self::assertSame($action === 'trigger' && $status === 200 ? 1 : 0, $effects->count);
            self::assertSame($legacyEffects->count, $effects->count);
        }
    }

    public function testNotificationTerminalsPreserveTypeAndChannelResponse(): void
    {
        foreach ([['index', null, false, 200], ['test', 'mail', false, 200], ['test', 'mail', true, 500], ['test', 'missing', false, 404], ['test', ['invalid'], false, 404], ['test', null, false, 404]] as [$action, $type, $throws, $status]) {
            $channel = $this->createMock(ChannelInterface::class);
            $send = $channel->expects($action === 'test' && $type === 'mail' ? self::exactly(2) : self::never())->method('send');
            if ($throws) {
                $send->willThrowException(new \DomainException('fixture delivery refused'));
            }
            $dispatcher = new NotificationDispatcher(new SyncQueue(), ['mail' => $channel]);
            $reader = new UserInternalFieldReaderFixture();
            [$provider, $bus] = $this->provider([NotificationDispatcher::class => $dispatcher, UserInternalFieldReaderInterface::class => $reader]);
            $legacy = new NotificationAdminApiRouter(new NotificationController($dispatcher, $reader));
            $request = new Request();
            if ($type !== null) {
                $request->attributes->set('type', $type);
            }
            $this->assertParity($provider, $bus, $legacy, NotificationController::class, $action, $request, $status);
        }
    }

    public function testMissingThrownAndInvalidRequiredBindingsRefuseSelection(): void
    {
        foreach ([
            [SchedulerAdminApiRouter::class, ScheduleInterface::class, new \RuntimeException('private scheduler failure')],
            [SchedulerAdminApiRouter::class, ScheduleInterface::class, new \stdClass()],
            [SchedulerAdminApiRouter::class, '', null],
            [NotificationAdminApiRouter::class, NotificationDispatcher::class, new \RuntimeException('private notification failure')],
            [NotificationAdminApiRouter::class, NotificationDispatcher::class, new \stdClass()],
            [NotificationAdminApiRouter::class, '', null],
            [NotificationAdminApiRouter::class, NotificationDispatcher::class, new NotificationDispatcher(new SyncQueue(), [])],
        ] as [$router, $key, $value]) {
            [$provider, $bus] = $this->provider($key === '' ? [] : [$key => $value]);
            $definition = new RouteDefinition('fixture.admin', '/fixture', HandlerReference::fromString('class:' . $router . '::index'), sourceId: 'fixture.api', ordinal: 0);
            $request = new Request(attributes: ['_route' => $definition->name, '_controller' => $definition->handler->id]);
            $services = new KernelHandlerContainer([$provider], [])->explicitServices($request);
            self::assertTrue($services->has($router));
            self::assertSame([], $bus->reads);
            try {
                new RouteHandlerResolver($definition, $services)->resolveMatched();
                self::fail('Required dependency failure must refuse.');
            } catch (HandlerResolutionException $error) {
                self::assertStringContainsString('resolution-failed', $error->getMessage());
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    private function schedulerFixture(bool $withTask): array
    {
        $db = DBALDatabase::createSqlite();
        $db->query('CREATE TABLE waaseyaa_schedule_state (task_name VARCHAR(255) PRIMARY KEY, last_run_at VARCHAR(50) NOT NULL, last_result TEXT NOT NULL)');
        $state = new ScheduleStateRepository($db);
        $schedule = new Schedule();
        $effects = new class {
            public int $count = 0;
        };
        if ($withTask) {
            $schedule->add(new ScheduledTask('known', '* * * * *', new LeaseAwareClosureCommand(static function (LeaseExecutionContext $context) use ($effects): void {
                $context->effect('api-terminal-fixture', 'trigger', static function () use ($effects): void {
                    $effects->count++;
                });
            }), preventOverlap: true));
        }
        $runner = new ScheduleRunner($schedule, new SyncQueue(), new InMemoryLeaseAuthority(), $state, fenceGuard: new InMemoryFenceGuard(), occurrenceRepository: new InMemoryOccurrenceRepository());
        return [[ScheduleInterface::class => $schedule, ScheduleStateRepository::class => $state, ScheduleRunner::class => $runner], $effects];
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
        $definition = new RouteDefinition('fixture.admin', '/fixture', HandlerReference::fromString('class:' . $legacy::class . '::' . $action), sourceId: 'fixture.api', ordinal: 0);
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
        self::assertSame($expected->headers->get('Content-Type'), $actual->headers->get('Content-Type'));
        self::assertSame($handler, $request->attributes->get('_controller'));
        self::assertNotSame($provider->resolve($legacy::class), $provider->resolve($legacy::class));
    }
}
